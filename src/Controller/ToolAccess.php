<?php

declare(strict_types=1);

namespace Drupal\decionis\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;

/**
 * Keeps optional Commerce tools off sites without the integration enabled.
 */
final class ToolAccess extends ControllerBase {

  /**
   * Route permissions are checked separately from this module prerequisite.
   */
  public function commerce(): AccessResult {
    return AccessResult::allowedIf($this->moduleHandler()->moduleExists('decionis_commerce'))->addCacheTags(['config:core.extension']);
  }

}
