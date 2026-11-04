<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis\Kernel;

use Decionis\Client\Decision\Action;
use Decionis\Client\Decision\Request;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;
use Drupal\decionis\Entity\Decision;
use Drupal\decionis\Gate\Context;
use Drupal\decionis\Gate\Ledger;
use Drupal\decionis\Gate\Migration;
use Drupal\decionis\Gate\Outcome;
use Drupal\decionis\Report\Summary;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\decionis\Support\Scripted;

/**
 * Replay upsert and upgrade preserve durable, uniquely identified evidence.
 *
 * @group decionis
 */
final class LedgerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'key', 'decionis'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('decionis_decision');
    $this->installEntitySchema('decionis_hold');
    $this->installConfig(['decionis']);
    $this->config('decionis.settings')->set('org_id', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1')->save();
  }

  /**
   * Retry retains the canonical row and cannot erase a previous real verdict.
   */
  public function testReplayUpsertAndDatabaseUniqueness(): void {
    $action = Action::refund(['type' => 'WORKFLOW', 'id' => 'synthetic-replay'], 'drupal', 'synthetic-replay-1', '1', 12.0, 'EUR');
    $context = new Context('refund_request', 'replay', FALSE, 'commerce_payment', '1', 12.0, 'EUR', source: 'replay', occurredAt: time() - 180 * 86400);
    $authority = new Scripted('REJECT');
    $answer = $authority->evaluate(new Request($action, 'synthetic', 'api'), FALSE);
    $outcome = new Outcome('shadow', 'proceeded', $answer->verdict, $answer->reasonCodes, $answer->dossierId);
    $ledger = $this->container->get(Ledger::class);
    $pending = $ledger->record($action, $context, new Outcome('shadow', 'ungoverned', NULL), $authority->label(), NULL);
    $id = $ledger->record($action, $context, $outcome, $authority->label(), $answer);
    $this->assertSame($pending, $id, 'An unpinned outage resolves into the same canonical row.');
    $this->assertSame($id, $ledger->record($action, $context, $outcome, $authority->label(), $answer));
    $this->assertSame($id, $ledger->record($action, $context, new Outcome('shadow', 'ungoverned', NULL), $authority->label(), NULL, NULL, 'test-policy-v1'));
    $this->assertCount(1, Decision::loadMultiple());
    $entry = Decision::load($id);
    $this->assertSame('BLOCK', $entry->value('verdict'));
    $summary = $this->container->get(Summary::class);
    $this->assertSame(1, $summary->totals(0, 'replay', 0, FALSE, [$id])['reviewed']);
    $this->assertSame(0, $summary->totals(0, 'replay', 0, FALSE, [])['reviewed']);
    $this->assertSame(0, $summary->totals(time() - 90 * 86400, 'replay')['reviewed']);

    // Bypass the application lookup to prove the database itself arbitrates
    // simultaneous inserts. A second process cannot double the count.
    $this->expectException(IntegrityConstraintViolationException::class);
    $this->container->get('database')->insert('decionis_decision')->fields([
      'uuid' => $this->container->get('uuid')->generate(),
      'replay_key' => $entry->value('replay_key'),
    ])->execute();
  }

  /**
   * Revision 0 storage upgrades in place with rows and indexes intact.
   */
  public function testLegacySchemaUpgradeIsRepeatable(): void {
    $entry = Decision::create(['action' => 'refund_request', 'source' => 'live', 'effect' => 'proceeded', 'verdict' => 'ALLOW']);
    $entry->save();
    $id = $entry->id();
    $schema = $this->container->get('database')->schema();
    $schema->dropUniqueKey('decionis_decision', 'decionis_replay');
    $schema->dropIndex('decionis_decision', 'decionis_pending');
    $schema->dropIndex('decionis_decision', 'decionis_nudge');
    $repository = $this->container->get('entity.last_installed_schema.repository');
    $installed = $repository->getLastInstalledFieldStorageDefinitions('decionis_decision');
    $sql = $this->container->get('keyvalue')->get('entity.storage_schema.sql');
    foreach (Migration::FIELDS as $field) {
      $schema->dropField('decionis_decision', $field);
      unset($installed[$field]);
      $sql->delete('decionis_decision.field_schema_data.' . $field);
    }
    $data = $sql->get('decionis_decision.entity_schema_data');
    unset($data['decionis_decision']['unique keys']['decionis_replay'], $data['decionis_decision']['indexes']['decionis_pending'], $data['decionis_decision']['indexes']['decionis_nudge']);
    $sql->set('decionis_decision.entity_schema_data', $data);
    $repository->setLastInstalledFieldStorageDefinitions('decionis_decision', $installed);
    $old = $repository->getLastInstalledDefinition('decionis_decision');
    $old->setHandlerClass('storage_schema', SqlContentEntityStorageSchema::class);
    $repository->setLastInstalledDefinition($old);
    $this->container->get('entity_type.manager')->clearCachedDefinitions();

    Migration::update();
    Migration::update();
    $this->assertTrue($schema->indexExists('decionis_decision', 'decionis_replay'));
    $this->assertTrue($schema->indexExists('decionis_decision', 'decionis_pending'));
    $this->assertTrue($schema->indexExists('decionis_decision', 'decionis_nudge'));
    $fresh = Decision::load($id);
    $this->assertSame('ALLOW', $fresh->value('verdict'));
    $this->assertSame('', $fresh->value('pending_capture'));
    $this->assertCount(1, Decision::loadMultiple());
  }

}
