<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis\Kernel;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\decionis\Connect\Binding;
use Drupal\decionis\Entity\Decision;
use Drupal\decionis\Gate\Authority;
use Drupal\decionis\Report\Pending;
use Drupal\KernelTests\KernelTestBase;
use Drupal\key\KeyRepositoryInterface;

/**
 * Status reads report queue age without processing work or reading credentials.
 *
 * @group decionis
 */
final class PendingTest extends KernelTestBase {

  private const NOW = 2000000000;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'key', 'decionis'];

  /**
   * The read-only status report with a fixed clock.
   */
  private Pending $pending;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['decionis']);
    $clock = $this->createMock(TimeInterface::class);
    $clock->method('getCurrentTime')->willReturn(self::NOW);
    $this->container->set('datetime.time', $clock);
    $this->pending = new Pending($this->container->get('database'), $clock);
    require_once \Drupal::root() . '/core/includes/install.inc';
    $this->container->get('module_handler')->loadInclude('decionis', 'install');
  }

  /**
   * Exactly one hour is not overdue, even for a much older replay event.
   */
  public function testOnlyQueueCreationOlderThanAnHourWarns(): void {
    $this->installEntitySchema('decionis_decision');
    $this->record('shadow', 'pending', 3600, self::NOW - 180 * 86400);
    $this->record('shadow', 'evaluating', 3599, self::NOW - 90 * 86400);
    $this->assertSame(['count' => 0, 'oldest_age' => 0], $this->pending->read());
    $this->assertArrayNotHasKey('decionis_shadow_pending', decionis_requirements('runtime'));

    $this->record('shadow', 'pending', 3601, self::NOW);
    $this->record('shadow', 'evaluating', 7200, self::NOW + 86400);
    $this->assertSame(['count' => 2, 'oldest_age' => 7200], $this->pending->read());
    $warning = decionis_requirements('runtime')['decionis_shadow_pending'];
    $this->assertSame(REQUIREMENT_WARNING, $warning['severity']);
    $this->assertStringContainsString('2 overdue Shadow checks', (string) $warning['value']);
    $this->assertStringContainsString('7200 seconds', (string) $warning['value']);
    $this->assertStringContainsString('drush cron', (string) $warning['description']);
    $this->assertArrayNotHasKey('decionis_shadow_pending', decionis_requirements('install'));
  }

  /**
   * Finished decisions and Enforce work are excluded regardless of age.
   */
  public function testCompletedAndEnforceRowsAreExcluded(): void {
    $this->installEntitySchema('decionis_decision');
    foreach (['proceeded', 'held', 'blocked', 'ungoverned', 'outside'] as $effect) {
      $this->record('shadow', $effect, 10000);
    }
    foreach (['pending', 'evaluating'] as $effect) {
      $this->record('enforce', $effect, 10000);
    }
    $this->assertSame(['count' => 0, 'oldest_age' => 0], $this->pending->read());
    $this->assertArrayNotHasKey('decionis_shadow_pending', decionis_requirements('runtime'));
  }

  /**
   * Before or during schema installation, status reads remain harmless.
   */
  public function testMissingTableAndColumnsAreGuarded(): void {
    $this->assertNull($this->pending->read());
    $this->assertArrayNotHasKey('decionis_shadow_pending', decionis_requirements('runtime'));
    $schema = $this->container->get('database')->schema();
    $fields = ['mode' => ['type' => 'varchar', 'length' => 16], 'effect' => ['type' => 'varchar', 'length' => 32], 'created' => ['type' => 'int']];
    foreach (array_keys($fields) as $missing) {
      $partial = $fields;
      unset($partial[$missing]);
      $schema->createTable('decionis_decision', ['fields' => $partial]);
      $this->assertNull($this->pending->read(), $missing);
      $this->assertArrayNotHasKey('decionis_shadow_pending', decionis_requirements('runtime'));
      $schema->dropTable('decionis_decision');
    }
  }

  /**
   * Reading status cannot dispatch, claim, evaluate, or alter queued work.
   */
  public function testRuntimeStatusDoesNotMutateOrReadCredentials(): void {
    $this->installEntitySchema('decionis_decision');
    $this->record('shadow', 'pending', 7200);
    $this->record('shadow', 'evaluating', 7201);
    $queue = $this->container->get('queue')->get('decionis_shadow', TRUE);
    $queue->createItem(['id' => 1]);
    $this->container->get('state')->set(Binding::STATE, ['org_id' => 'synthetic-status-workspace', 'api_key' => 'synthetic-status-key-reference']);
    $keys = $this->createMock(KeyRepositoryInterface::class);
    $keys->expects($this->never())->method('getKey');
    $this->container->set('key.repository', $keys);
    $queues = $this->createMock(QueueFactory::class);
    $queues->expects($this->never())->method('get');
    $this->container->set('queue', $queues);
    $authority = $this->createMock(Authority::class);
    $authority->expects($this->never())->method('available');
    $authority->expects($this->never())->method('evaluate');
    $this->container->set(Authority::class, $authority);
    $before = $this->snapshot();
    for ($read = 0; $read < 2; $read++) {
      $this->assertArrayHasKey('decionis_shadow_pending', decionis_requirements('runtime'));
    }
    $this->assertSame($before, $this->snapshot());
  }

  /**
   * Uninstall clears all selection generations and known policy receipt keys.
   */
  public function testUninstallClearsOwnedStateAndPreservesOtherModules(): void {
    $state = $this->container->get('state');
    $owned = ['decionis.enforcement.first', 'decionis.enforcement.first.price_change', 'decionis.enforcement.second', 'decionis.enforcement.second.refund_request', 'decionis_commerce.policy', 'decionis_commerce.policy_pending'];
    foreach ($owned as $key) {
      $state->set($key, ['synthetic' => TRUE]);
    }
    $retained = ['decionis.enforcement_backup', 'decionis_commerce.policy_other', 'another_module.setting'];
    foreach ($retained as $key) {
      $state->set($key, 'retained');
    }
    decionis_uninstall();
    decionis_uninstall();
    foreach ($owned as $key) {
      $this->assertNull($state->get($key), $key);
      $this->assertNull($this->container->get('keyvalue')->get('state')->get($key), $key);
    }
    foreach ($retained as $key) {
      $this->assertSame('retained', $state->get($key), $key);
    }
  }

  /**
   * Adds a synthetic decision with independently chosen queue and event times.
   */
  private function record(string $mode, string $effect, int $age, ?int $occurred = NULL): void {
    Decision::create(['action' => 'price_change', 'mode' => $mode, 'effect' => $effect, 'created' => self::NOW - $age, 'occurred' => $occurred ?? self::NOW])->save();
  }

  /**
   * Captures durable observations, queues, and State values for comparison.
   *
   * @return array<string, string>
   *   Serialized rows for each table, ordered by its key fields.
   */
  private function snapshot(): array {
    $rows = [];
    foreach (['decionis_decision' => ['id'], 'queue' => ['item_id']] as $table => $keys) {
      $query = $this->container->get('database')->select($table, 't')->fields('t');
      foreach ($keys as $key) {
        $query->orderBy($key);
      }
      $rows[$table] = serialize($query->execute()->fetchAll());
    }
    $state = $this->container->get('keyvalue')->get('state')->getAll();
    ksort($state);
    $rows['state'] = serialize($state);
    return $rows;
  }

}
