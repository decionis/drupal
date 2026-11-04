<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis\Kernel;

use Decionis\Client\Decision\Action;
use Drupal\decionis\Approval\AgentSafe;
use Drupal\decionis\Approval\Intents;
use Drupal\decionis\Connect\Binding;
use Drupal\decionis\Gate\Credentials;
use Drupal\decionis\Gate\Settings;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\decionis\Support\FakeGrants;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;

/**
 * Grant authority HTTP is bound to the hold's original connection.
 *
 * @group decionis
 */
final class GrantBindingTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'key', 'decionis'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['decionis']);
    $this->config('decionis.settings')->set('org_id', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1')->save();
    $this->container->get(Credentials::class)->store('api_key', Credentials::API_KEY, 'Synthetic grant fixture', 'synthetic-grant-fixture-key');
    $this->container->get(Binding::class)->bind('https://shop.example');
  }

  /**
   * A hold from before reconnect cannot notify an approver through a new key.
   */
  public function testOldGrantAuthorityNeverSendsAfterReconnect(): void {
    $settings = $this->container->get(Settings::class);
    $identity = $settings->connectionIdentity();
    $this->assertNotNull($identity);
    $http = $this->createMock(ClientInterface::class);
    $http->expects($this->never())->method('sendRequest');
    $authority = (new AgentSafe($settings, $this->container->get(Credentials::class), $http))->bound($identity);
    $intent = $this->container->get(Intents::class)->capture(Action::refund(['type' => 'WORKFLOW', 'id' => 'fixture'], 'drupal', 'grant-fixture', '1', 10.0, 'EUR'), 'refund_request', 'payment:1');
    $this->container->get(Binding::class)->bind('https://shop.example');
    $this->expectExceptionMessage('DECIONIS_CONNECTION_CHANGED');
    $authority->escalate($intent);
  }

  /**
   * Responses and retries cannot cross connections for any grant operation.
   */
  public function testGrantResponsesAreDiscardedBeforeRetryAfterReconnect(): void {
    $settings = $this->container->get(Settings::class);
    foreach (['escalate', 'approved', 'claim', 'finalize'] as $operation) {
      $identity = $settings->connectionIdentity();
      $this->assertNotNull($identity);
      $http = $this->createMock(ClientInterface::class);
      $http->expects($this->once())->method('sendRequest')->willReturnCallback(function (): Response {
        $this->container->get(Binding::class)->bind('https://shop.example');
        return new Response(503);
      });
      $authority = (new AgentSafe($settings, $this->container->get(Credentials::class), $http))->bound($identity);
      $intent = $this->container->get(Intents::class)->capture(Action::refund(['type' => 'WORKFLOW', 'id' => 'fixture'], 'drupal', 'grant-fixture-' . $operation, '1', 10.0, 'EUR'), 'refund_request', 'payment:1');
      $fixture = new FakeGrants();
      $fixture->approved = TRUE;
      $grant = $fixture->approved('escalation-fixture', $intent);
      $this->assertNotNull($grant);
      $claim = $fixture->claim($grant, $intent);
      try {
        match ($operation) {
          'escalate' => $authority->escalate($intent),
          'approved' => $authority->approved('escalation-fixture', $intent),
          'claim' => $authority->claim($grant, $intent),
          'finalize' => $authority->finalize($grant, $claim, $intent, 'COMMITTED'),
        };
        $this->fail('A stale grant operation returned or retried.');
      }
      catch (\LogicException $error) {
        $this->assertSame('DECIONIS_CONNECTION_CHANGED', $error->getMessage());
      }
    }
  }

}
