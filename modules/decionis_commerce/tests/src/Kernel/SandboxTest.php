<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis_commerce\Kernel;

use Decionis\Client\Decision\Decision;
use Decionis\Client\Decision\Request;
use Drupal\decionis\Gate\Authority;
use Drupal\decionis_commerce\Sandbox\Hosted;
use Drupal\decionis_commerce\Sandbox\Isolation;
use Drupal\decionis_commerce\Sandbox\Local;
use Drupal\decionis_commerce\Sandbox\Sandbox;

/**
 * Tests drush decionis:sandbox with the local demo authority.
 *
 * The boundary holds, on the real hook points, and the database is left exactly
 * as it was.
 *
 * @group decionis
 */
final class SandboxTest extends DecionisCommerceTestBase {

  /**
   * Tests that local sandbox holds and leaves the database unchanged.
   */
  public function testLocalSandboxHoldsAndLeavesTheDatabaseUnchanged(): void {
    // Empty tables Drupal creates on first use may appear; no row may change.
    $this->container->get(Isolation::class)->prepare();
    $before = $this->snapshot();
    $report = $this->container->get(Sandbox::class)->run(new Local());
    $this->assertSame($before, $this->snapshot(), 'the sandbox rolled everything back');

    $this->assertSame('BOUNDARY HOLDS', $report['verdict']);
    $this->assertSame(0, $report['exit_code']);
    $this->assertSame('demo authority', $report['authority']);
    $this->assertSame(Sandbox::LABEL, $report['label']);
    $this->assertSame(['direct' => 5, 'shadow' => 5, 'enforce' => 0, 'risky' => 5], $report['exposure']);
    $this->assertSame(4, $report['wiring']['active']);
    $this->assertSame([], $report['wiring']['overrides']);
    $this->assertGreaterThan(0, $report['evidence']['count']);
    $this->assertSame($report['evidence']['count'], $report['evidence']['verified']);

    $rows = array_column($report['scenarios'], NULL, 'scenario');
    $expected = [
      'Refund within limit (EUR 40)' => ['refunded', 'refunded, would ALLOW', 'ALLOW: refunded, dossier'],
      'Refund above ceiling (EUR 1,200)' => ['refunded', 'refunded, would ESCALATE', 'ESCALATE: held for approval'],
      'Price below cost (SKU-2)' => ['saved', 'saved, would BLOCK', 'BLOCK: price not saved'],
      'Two coupons, 45% combined' => ['applied', 'applied, would BLOCK', 'BLOCK: coupon refused'],
      'Order under 12% margin floor' => ['placed', 'placed, would BLOCK', 'BLOCK: order not placed'],
      'Approved refund, replayed' => ['refunded twice', 'refunded twice', 'ALLOW once, replay REFUSED'],
      'Order, authority unreachable' => ['placed', 'placed, ungoverned', 'placed (fail-open), marked ungoverned'],
    ];
    foreach ($expected as $scenario => [$direct, $shadow, $enforce]) {
      $this->assertSame([$direct, $shadow, $enforce], [$rows[$scenario]['direct']['text'], $rows[$scenario]['shadow']['text'], $rows[$scenario]['enforce']['text']], $scenario);
    }
  }

  /**
   * Tests that shadow never stops an action.
   */
  public function testShadowNeverStopsAnAction(): void {
    $report = $this->container->get(Sandbox::class)->run(new Local());
    foreach ($report['scenarios'] as $row) {
      $this->assertTrue($row['shadow']['executed'], $row['scenario'] . ' ran in shadow');
    }
  }

  /**
   * Tests that every sandbox key names its run, and the pinned policy is sent.
   */
  public function testSandboxKeysAreScopedAndThePolicyIsPinned(): void {
    $recorder = new class(new Local()) implements Authority {

      /**
       * @var list<array{string, ?string}> */
      public array $requests = [];

      public function __construct(private readonly Local $local) {}

      /**
       * {@inheritdoc}
       */
      public function label(): string {
        return 'recorder';
      }

      /**
       * {@inheritdoc}
       */
      public function available(): bool {
        return TRUE;
      }

      /**
       * {@inheritdoc}
       */
      public function evaluate(Request $request, bool $checkout, ?int $budgetMs = NULL): Decision {
        $this->requests[] = [$request->idempotencyKey(), $request->policyVersion];
        return $this->local->evaluate($request, $checkout, $budgetMs);
      }

    };
    $this->config('system.site')->set('uuid', 'a6b1c2d3-e4f5-4a6b-8c7d-9e0f1a2b3c4d')->save();
    $this->container->get(Sandbox::class)->run($recorder, NULL, 'drupal-commerce-pinned-v3');
    $first = $recorder->requests;
    $this->assertNotEmpty($first);
    $uuid = $this->config('system.site')->get('uuid');
    foreach ($first as [$key, $policy]) {
      $this->assertStringStartsWith('drupal-sandbox:' . $uuid . ':', $key);
      $this->assertSame('drupal-commerce-pinned-v3', $policy);
    }
    $recorder->requests = [];
    $this->container->get(Sandbox::class)->run($recorder);
    $this->assertSame([], array_intersect(array_column($first, 0), array_column($recorder->requests, 0)), 'a second run reuses no key');
  }

  /**
   * Tests that the hosted sandbox reads its credential from Key entries.
   */
  public function testHostedSandboxFallsBackToKeyEntries(): void {
    putenv(Hosted::KEY);
    putenv(Hosted::ORG . '=aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1');
    $read = [];
    [$hosted, $missing] = Hosted::fromEnvironment('https://api.decionis.com', static function (string $id) use (&$read): ?string {
      $read[] = $id;
      return $id === 'decionis_sandbox_api_key' ? 'sandbox-key-from-key-module' : NULL;
    });
    putenv(Hosted::ORG);
    $this->assertInstanceOf(Hosted::class, $hosted);
    $this->assertSame([], $missing);
    $this->assertSame(['decionis_sandbox_api_key'], $read, 'the environment wins where it has a value');
  }

  /**
   * Tests that hosted sandbox names the missing settings.
   */
  public function testHostedSandboxNamesTheMissingSettings(): void {
    putenv(Hosted::KEY);
    putenv(Hosted::ORG);
    [$hosted, $missing] = Hosted::fromEnvironment('https://api.decionis.com');
    $this->assertNull($hosted);
    $this->assertSame([Hosted::KEY, Hosted::ORG], $missing);
  }

}
