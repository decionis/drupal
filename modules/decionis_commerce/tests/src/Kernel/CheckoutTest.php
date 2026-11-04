<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis_commerce\Kernel;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\decionis\Connect\Binding;
use Drupal\decionis\Gate\Credentials;
use Drupal\decionis\Gate\Gate;
use Drupal\decionis\Gate\Ledger;
use Drupal\decionis\Gate\Remote;
use Drupal\decionis\Gate\Settings;
use Drupal\decionis_commerce\Guard\Orders;
use Drupal\decionis_commerce\Map\Actions;
use Drupal\decionis_commerce\Map\Cost;
use Drupal\key\Entity\Key;
use Drupal\key\KeyRepositoryInterface;
use Drupal\key\Plugin\KeyProviderBase;

/**
 * Checks the warm checkout's 120 ms budget when Decionis does not answer.
 *
 * The order is placed and marked ungoverned when the authority times out.
 *
 * @group decionis
 */
final class CheckoutTest extends DecionisCommerceTestBase {

  /**
   * The loopback server that never answers in time.
   *
   * @var resource|null
   */
  private $server = NULL;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $port = $this->slowServer();
    $this->config('decionis.settings')
      ->set('api_base_url', 'http://127.0.0.1:' . $port)
      ->set('org_id', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1')
      ->set('mode', 'enforce')
      ->save();
    $this->container->get(Credentials::class)->store('api_key', Credentials::API_KEY, 'Decionis API key', 'dcy_aaaaaaaa_synthetic-checkout-test-key');
    $this->container->get('state')->set(Settings::ENTITLEMENT, ['enforcement' => TRUE, 'state' => 'paid']);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if (is_resource($this->server)) {
      proc_terminate($this->server);
      proc_close($this->server);
    }
    parent::tearDown();
  }

  /**
   * Tests that checkout fails open inside the budget when decionis is down.
   */
  public function testCheckoutFailsOpenInsideTheBudgetWhenDecionisIsDown(): void {
    $this->assertSame('enforce', $this->container->get(Settings::class)->mode('order_acceptance'));
    $variation = ProductVariation::create(['type' => 'default', 'sku' => 'FAST', 'price' => new Price('100', 'USD'), 'status' => 1]);
    $variation->save();
    $this->container->get(Cost::class)->override($variation, 60.0);
    $orders = $this->container->get(Orders::class);

    // The first check in a fresh process compiles every class on its path
    // (the CLI runs without opcache) and builds the log's field definitions.
    // A store runs warm, so the budget is held to the second checkout; the
    // first only has to end.
    $started = hrtime(TRUE);
    $orders->check($this->draft($variation), 'checkout_review', TRUE);
    $cold = (hrtime(TRUE) - $started) / 1e6;
    $this->assertLessThan(1000, $cold, sprintf('the first checkout check took %.1f ms', $cold));

    $order = $this->draft($variation);
    $started = hrtime(TRUE);
    $outcome = $orders->check($order, 'checkout_review', TRUE);
    $elapsed = (hrtime(TRUE) - $started) / 1e6;

    $this->assertLessThan(120, $elapsed, sprintf('the checkout check took %.1f ms (the first, cold, %.1f ms)', $elapsed, $cold));
    $this->assertSame('ungoverned', $outcome?->effect);
    $this->assertSame(['UPSTREAM_UNREACHABLE'], $outcome->reasons);
    $this->assertTrue($outcome->proceeds());
    $this->assertFalse($order->getData(Orders::DATA)['governed']);

    $order->getState()->applyTransitionById('place');
    $order->save();
    $this->assertSame('completed', $order->getState()->getId(), 'the order is placed');
  }

  /**
   * Resolving a slow external Key provider does not restart the HTTP budget.
   */
  public function testCredentialReadsConsumeTheCheckoutBudget(): void {
    $provider = $this->createMock(KeyProviderBase::class);
    $provider->method('getPluginId')->willReturn('synthetic_slow');
    $provider->method('getConfiguration')->willReturn([]);
    $key = $this->createMock(Key::class);
    $key->method('getPlugin')->with('key_provider')->willReturn($provider);
    $key->method('getKeyValue')->willReturnCallback(static function (): string {
      usleep(35000);
      return 'dcy_aaaaaaaa_synthetic-checkout-test-key';
    });
    $keys = $this->createMock(KeyRepositoryInterface::class);
    $keys->method('getKey')->with(Credentials::API_KEY)->willReturn($key);
    $credentials = new Credentials($keys, $this->container->get('entity_type.manager'), $this->container->get('state'), $this->container->get('config.factory'), $this->container->get(Binding::class), $this->container->get('keyvalue'));
    $settings = $this->container->get(Settings::class);
    $gate = new Gate(new Remote($settings, $credentials), $settings, $this->container->get(Ledger::class));
    $orders = new Orders($gate, $this->container->get(Actions::class));
    $variation = ProductVariation::create(['type' => 'default', 'sku' => 'SLOW-KEY', 'price' => new Price('100', 'USD'), 'status' => 1]);
    $variation->save();
    $this->container->get(Cost::class)->override($variation, 60.0);
    // Warm Drupal's field definitions and the client before measuring.
    $orders->check($this->draft($variation), 'checkout_review', TRUE);
    $order = $this->draft($variation);
    $started = hrtime(TRUE);
    $outcome = $orders->check($order, 'checkout_review', TRUE);
    $elapsed = (hrtime(TRUE) - $started) / 1e6;

    $this->assertLessThan(120, $elapsed, sprintf('credential reads and checkout took %.1f ms', $elapsed));
    $this->assertSame('ungoverned', $outcome?->effect);
    $this->assertSame(['UPSTREAM_UNREACHABLE'], $outcome->reasons);
    $this->assertTrue($outcome->proceeds());
    $this->assertFalse($order->getData(Orders::DATA)['governed']);
  }

  /**
   * A draft order for one unit of the variation.
   */
  private function draft(ProductVariation $variation): Order {
    $item = OrderItem::create(['type' => 'default', 'purchased_entity' => $variation, 'quantity' => '1', 'unit_price' => $variation->getPrice()]);
    $item->save();
    $order = Order::create(['type' => 'default', 'store_id' => $this->store->id(), 'mail' => 'buyer@example.com', 'uid' => 0, 'state' => 'draft', 'order_items' => [$item]]);
    $order->recalculateTotalPrice()->save();
    return $order;
  }

  /**
   * Starts a server that holds every request for two seconds.
   */
  private function slowServer(): int {
    $router = sys_get_temp_dir() . '/decionis-slow-' . getmypid() . '.php';
    file_put_contents($router, '<?php sleep(2); http_response_code(503);');
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $name = (string) stream_socket_get_name($socket, FALSE);
    fclose($socket);
    $port = (int) substr($name, strrpos($name, ':') + 1);
    $this->server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $router], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    for ($i = 0; $i < 100 && @fsockopen('127.0.0.1', $port) === FALSE; $i++) {
      usleep(20000);
    }
    return $port;
  }

}
