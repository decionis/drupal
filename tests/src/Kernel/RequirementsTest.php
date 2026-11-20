<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis\Kernel;

use Drupal\decionis\Hook\CoreHooks;
use Drupal\decionis\Report\Severity;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Every supported core reads the same requirements.
 *
 * Drupal 11.2 added the install requirements class and the runtime and update
 * requirements hooks; Drupal 11.3 stopped calling the legacy hook.
 *
 * @group decionis
 */
#[RunTestsInSeparateProcesses]
final class RequirementsTest extends KernelTestBase {

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
    $this->installEntitySchema('decionis_decision');
    require_once \Drupal::root() . '/core/includes/install.inc';
    $this->container->get('module_handler')->loadInclude('decionis', 'install');
  }

  /**
   * The install check finds the dependencies on old and new cores alike.
   */
  public function testInstallPhase(): void {
    $legacy = decionis_requirements('install');
    $this->assertSame(Severity::ok(), $legacy['decionis_dependencies']['severity']);
    if (function_exists('install_check_class_requirements')) {
      $extension = $this->container->get('extension.list.module')->get('decionis');
      $this->assertSame($legacy, install_check_class_requirements($extension));
    }
    $this->assertTrue(drupal_check_module('decionis'));
  }

  /**
   * The status report and update checks come from the hook methods.
   */
  public function testRuntimeAndUpdatePhases(): void {
    $hooks = $this->container->get(CoreHooks::class);
    $runtime = $hooks->runtimeRequirements();
    $this->assertSame(Severity::warning(), $runtime['decionis_connection']['severity']);
    $this->assertSame(Severity::ok(), $runtime['decionis_dependencies']['severity']);
    $this->assertSame($runtime, decionis_requirements('runtime'));
    $this->assertSame($hooks->updateRequirements(), decionis_requirements('update'));
    $this->assertArrayNotHasKey('decionis_connection', $hooks->updateRequirements());

    // Core calls the hook on 11.2+, and the legacy function before. From 11.3
    // the status report and update.php no longer register the legacy one.
    $handler = $this->container->get('module_handler');
    if ($handler->hasImplementations('runtime_requirements', 'decionis')) {
      $this->assertSame($runtime, $handler->invoke('decionis', 'runtime_requirements'));
    }
    else {
      $this->assertSame($runtime, $handler->invoke('decionis', 'requirements', ['runtime']));
    }
    $this->assertSame(version_compare(\Drupal::VERSION, '11.3', '<'), $handler->hasImplementations('requirements', 'decionis'));
  }

}
