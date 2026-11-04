<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce\Sandbox;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryOverrideInterface;
use Drupal\Core\Config\StorageInterface;

/**
 * Captures outgoing sandbox mail in Drupal's test collector.
 *
 * Mail stays in State inside the rolled-back transaction, so a synthetic
 * order receipt never leaves the site.
 */
final class Quiet implements ConfigFactoryOverrideInterface {

  /**
   * Whether the current request is running an isolated sandbox scenario.
   */
  private static bool $active = FALSE;

  /**
   * Switches mail capture on or off for the sandbox run.
   */
  public static function set(bool $active): void {
    self::$active = $active;
    \Drupal::configFactory()->reset('system.mail');
  }

  /**
   * TRUE only while the isolated synthetic run is in progress.
   */
  public static function active(): bool {
    return self::$active;
  }

  /**
   * {@inheritdoc}
   */
  public function loadOverrides($names): array {
    return self::$active && in_array('system.mail', $names, TRUE)
      ? ['system.mail' => ['interface' => ['default' => 'test_mail_collector']]]
      : [];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheSuffix(): string {
    return self::$active ? 'decionis_sandbox' : '';
  }

  /**
   * {@inheritdoc}
   */
  public function createConfigObject($name, $collection = StorageInterface::DEFAULT_COLLECTION) {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheableMetadata($name): CacheableMetadata {
    return new CacheableMetadata();
  }

}
