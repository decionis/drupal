<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis_commerce\Kernel;

use Drupal\Core\Mail\MailManagerInterface;
use Drupal\decionis\Connect\Binding;
use Drupal\decionis\Gate\Settings;
use Drupal\decionis_commerce\Gateway\Custody;
use Drupal\decionis_commerce\Gateway\Setup;
use Drupal\decionis_commerce\Gateway\Watchdog;
use Drupal\Tests\decionis_commerce\Support\ServerProbe;

/**
 * Readiness outages alert administrators once, independently of policy calls.
 *
 * @group decionis
 */
final class GatewayWatchdogTest extends DecionisCommerceTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->config('system.mail')->set('interface.default', 'test_mail_collector')->save();
    $this->config('decionis.settings')->set('org_id', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1')->set('api_key', 'synthetic_watchdog_reference')->save();
    $settings = $this->container->get(Settings::class);
    $binding = $this->container->get(Binding::class);
    $binding->bind('https://shop.example');
    $this->container->get('state')->set(Setup::STATE, [
      'custody' => TRUE,
      'gateway' => 'synthetic_gateway',
      'port' => 8787,
      'connection_id' => $settings->connectionIdentity(),
      'org_id' => $settings->get('org_id'),
      'api_key_id' => $settings->get('api_key'),
      'host' => $binding->host(),
    ]);
    $this->createUser(['administer decionis'], 'gatewayadmin', FALSE, ['mail' => 'gateway-admin@example.com']);
    $this->createUser([], 'gatewayviewer', FALSE, ['mail' => 'gateway-viewer@example.com']);
  }

  /**
   * Builds the watchdog with only its readiness response replaced.
   */
  private function service(ServerProbe $probe, ?MailManagerInterface $mail = NULL): Watchdog {
    return new Watchdog($this->container->get('state'), $this->container->get('entity_type.manager'), $this->container->get(Custody::class), $mail ?? $this->container->get('plugin.manager.mail'), $this->container->get('logger.factory'), $probe);
  }

  /**
   * An outage is critical and gets one Drupal email per administrator.
   */
  public function testOutageMailsOnceAndRecoveryAllowsNextAlert(): void {
    $probe = new ServerProbe(1000);
    $watchdog = $this->service($probe);
    $watchdog->run();
    $state = $this->container->get('state');
    $this->assertSame(2, $watchdog->requirements()['decionis_agentsafe']['severity']);
    $mail = $state->get('system.test_mail_collector', []);
    $admin = array_values(array_filter($mail, static fn (array $message): bool => $message['to'] === 'gateway-admin@example.com'));
    $this->assertCount(1, $admin);
    $this->assertSame('decionis_commerce_gateway_watchdog', $admin[0]['id']);
    $this->assertStringContainsString('gateway-rollback', $admin[0]['body']);
    $this->assertNotContains('gateway-viewer@example.com', array_column($mail, 'to'));

    $watchdog->run();
    $this->assertCount(count($mail), $state->get('system.test_mail_collector', []));
    $probe->answering = TRUE;
    $watchdog->run();
    $this->assertSame(0, $watchdog->requirements()['decionis_agentsafe']['severity']);
    $probe->answering = FALSE;
    $watchdog->run();
    $this->assertCount(count($mail) * 2, $state->get('system.test_mail_collector', []));
  }

  /**
   * A copied database never sends production gateway alerts.
   */
  public function testCopySuppressesReadinessAndMail(): void {
    $binding = $this->container->get(Binding::class);
    $record = $binding->record();
    $record['binding']['host'] = hash('sha256', 'another host');
    $this->container->get('state')->set(Binding::STATE, $record);
    $watchdog = $this->service(new ServerProbe(1000));
    $watchdog->run();
    $this->assertSame([], $watchdog->requirements());
    $this->assertNull($this->container->get('state')->get(Watchdog::STATE));
    $this->assertSame([], $this->container->get('state')->get('system.test_mail_collector', []));
  }

  /**
   * A delivery failure is retried after the bounded backoff, then remembered.
   */
  public function testFailedMailIsRetriedWithoutRepeatingSuccessfulMail(): void {
    $mail = $this->createMock(MailManagerInterface::class);
    $mail->expects($this->atLeastOnce())->method('mail')->willReturn(['result' => FALSE]);
    $this->service(new ServerProbe(1000), $mail)->run();
    $state = $this->container->get('state');
    $health = $state->get(Watchdog::STATE);
    $this->assertSame([], $health['notified']);
    $health['mailed_at'] = time() - 301;
    $state->set(Watchdog::STATE, $health);
    $watchdog = $this->service(new ServerProbe(1000));
    $watchdog->run();
    $this->assertNotEmpty($state->get(Watchdog::STATE)['notified']);
    $sent = count($state->get('system.test_mail_collector', []));
    $health = $state->get(Watchdog::STATE);
    $health['mailed_at'] = time() - 301;
    $state->set(Watchdog::STATE, $health);
    $watchdog->run();
    $this->assertCount($sent, $state->get('system.test_mail_collector', []));
  }

}
