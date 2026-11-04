<?php

declare(strict_types=1);

namespace Drupal\decionis\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\decionis\Entity\Decision;
use Drupal\decionis\Report\Reasons;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams the decision log as CSV for accountants and auditors.
 */
final class ExportController implements ContainerInjectionInterface {

  private const COLUMNS = ['id', 'created', 'action', 'hook', 'source', 'mode', 'verdict', 'effect', 'reasons', 'at_stake', 'currency', 'policy_version', 'dossier_id', 'dossier_sha256', 'target_type', 'target_id', 'authority'];

  public function __construct(private readonly EntityTypeManagerInterface $entityTypeManager) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('entity_type.manager'));
  }

  /**
   * Streams every decision as CSV.
   */
  public function download(): StreamedResponse {
    $storage = $this->entityTypeManager->getStorage('decionis_decision');
    $response = new StreamedResponse(function () use ($storage): void {
      $out = fopen('php://output', 'w');
      if ($out === FALSE) {
        return;
      }
      fputcsv($out, self::COLUMNS, ',', '"', '\\');
      $last = 0;
      do {
        $ids = $storage->getQuery()->accessCheck(FALSE)->condition('id', $last, '>')->sort('id')->range(0, 200)->execute();
        foreach ($storage->loadMultiple($ids) as $decision) {
          assert($decision instanceof Decision);
          fputcsv($out, array_map(fn (string $column): string => self::cell(match ($column) {
            'created' => gmdate('c', (int) $decision->get('created')->value),
            'reasons' => Reasons::join($decision->reasons(), $decision->value('action') === 'refund_request' && $decision->value('source') === 'replay'),
            default => $decision->value($column),
          }), self::COLUMNS), ',', '"', '\\');
          $last = (int) $decision->id();
        }
        $storage->resetCache($ids);
      } while ($ids !== []);
      fclose($out);
    });
    $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="decionis-decisions.csv"');
    $response->headers->set('Cache-Control', 'no-store');
    return $response;
  }

  /**
   * Neutralizes spreadsheet formulas in a cell.
   */
  public static function cell(string $value): string {
    return preg_match('/^(?:[\x00-\x20]*[=+\-@]|[\t\r\n])/', $value) === 1 ? "'" . $value : $value;
  }

}
