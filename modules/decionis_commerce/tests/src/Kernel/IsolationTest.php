<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis_commerce\Kernel;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\decionis_commerce\Replay\Replay;
use Drupal\decionis_commerce\Sandbox\Interrupted;
use Drupal\decionis_commerce\Sandbox\Isolation;
use Drupal\decionis_commerce\Sandbox\Local;
use Drupal\decionis_commerce\Sandbox\Sandbox;
use Drupal\decionis_commerce\Sandbox\Wiring;

/**
 * Checks sandbox cleanup and hook wiring across database transaction models.
 *
 * MySQL and MariaDB commit on schema changes; SQLite and PostgreSQL do not.
 * A run on either kind must end with no synthetic records, and the wiring check
 * must tell the store whether its hook points are live.
 *
 * @group decionis
 */
final class IsolationTest extends DecionisCommerceTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['decionis_schema_test'];

  /**
   * Tests that the tables Drupal creates on first use exist before a run.
   */
  public function testPrepareCreatesTheTablesDrupalCreatesOnFirstUse(): void {
    $schema = $this->container->get('database')->schema();
    if ($schema->tableExists('queue')) {
      $schema->dropTable('queue');
    }
    $this->container->get(Isolation::class)->prepare();
    $this->assertTrue($schema->tableExists('queue'));
    $this->assertSame('0', (string) $this->container->get('database')->select('queue', 'q')->countQuery()->execute()->fetchField(), 'the probe leaves no item');
  }

  /**
   * Tests that a schema change during a run leaves no synthetic records.
   */
  public function testSchemaChangeDuringRunLeavesNoRecords(): void {
    $this->container->get(Isolation::class)->prepare();
    $this->container->get('state')->set('decionis_schema_test.armed', TRUE);
    $before = $this->snapshot();

    $interrupted = FALSE;
    try {
      $this->container->get(Sandbox::class)->run(new Local());
    }
    catch (Interrupted) {
      $interrupted = TRUE;
    }

    $this->assertSame($this->container->get('database')->databaseType() === 'mysql', $interrupted, 'only a database that commits on a schema change loses the run');
    // Deleting the records rebuilds the router and invalidates cache tags, as
    // deleting them by hand would. No other row may differ.
    $after = array_intersect_key($this->snapshot(), $before);
    unset($before['cachetags'], $after['cachetags']);
    $this->assertSame($before, $after);

    // The next run works, leftovers or not.
    $this->container->get('state')->delete('decionis_schema_test.armed');
    $this->assertSame('BOUNDARY HOLDS', $this->container->get(Sandbox::class)->run(new Local())['verdict']);
  }

  /**
   * Tests that a demo replay survives a schema change too.
   */
  public function testDemoReplayEndsCleanlyAfterSchemaChange(): void {
    $this->container->get('state')->set('decionis_schema_test.armed', TRUE);
    $this->assertArrayHasKey('label', $this->container->get(Replay::class)->demo(90));
  }

  /**
   * What a wiring report says about the refund form.
   *
   * @param array{points: array<string, bool>, overrides: list<string>} $report
   *   The report.
   *
   * @return array{bool, list<string>}
   *   Whether it is wired, and the overrides.
   */
  private static function refundForm(array $report): array {
    return [$report['points']['refund form'], $report['overrides']];
  }

  /**
   * Tests that the refund form counts as wired only if it is really gated.
   */
  public function testTheRefundFormIsWiredOnlyIfItIsGated(): void {
    $wiring = $this->container->get(Wiring::class);
    $this->assertSame([TRUE, []], self::refundForm($wiring->check()));

    $this->container->get('entity_type.manager')->getDefinition('commerce_payment')->setFormClass('operation', ContentEntityForm::class);
    $this->assertSame([FALSE, ['the payment operation form is replaced by ' . ContentEntityForm::class]], self::refundForm($wiring->check()), 'a replaced form is not gated');
  }

}
