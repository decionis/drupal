<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce\Plugin\ConfigAction;

use Drupal\Core\Config\Action\Attribute\ConfigAction;
use Drupal\Core\Config\Action\ConfigActionException;
use Drupal\Core\Config\Action\ConfigActionPluginInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\decionis_commerce\Map\CostFields as Fields;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The recipe's idempotent cost-field action, supported by Drupal 10.3 and 11.
 */
#[ConfigAction(
  id: 'decionisEnsureCostFields',
  admin_label: new TranslatableMarkup('Add Decionis cost fields and widgets'),
)]
final class CostFields implements ConfigActionPluginInterface, ContainerFactoryPluginInterface {

  public function __construct(private readonly Fields $fields) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self($container->get(Fields::class));
  }

  /**
   * {@inheritdoc}
   */
  public function apply(string $configName, mixed $value): void {
    if ($configName !== 'decionis_commerce.settings' || $value !== TRUE) {
      throw new ConfigActionException('DECIONIS_COST_RECIPE_INVALID');
    }
    $this->fields->ensure();
  }

}
