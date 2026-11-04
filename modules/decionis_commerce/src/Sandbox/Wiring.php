<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce\Sandbox;

use Drupal\commerce_payment\Form\PaymentOperationForm;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormState;
use Drupal\decionis_commerce\EventSubscriber\OrderSubscriber;
use Drupal\decionis_commerce\Hook\CommerceHooks;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Checks hook wiring and flags custom code that could bypass a gate.
 *
 * This includes an altered refund form, a replaced payment operation form,
 * or a custom price widget.
 */
final class Wiring {

  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly EntityFieldManagerInterface $fieldManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EventDispatcherInterface $dispatcher,
    private readonly CommerceHooks $hooks,
    private readonly EntityTypeBundleInfoInterface $bundles,
  ) {}

  /**
   * Checks the active gate hooks and potential custom-code bypasses.
   *
   * @return array
   *   The hook status map and descriptions of detected overrides.
   *
   * @phpstan-return array{points: array<string, bool>, overrides: list<string>}
   */
  public function check(): array {
    $fields = $this->fieldManager->getBaseFieldDefinitions('commerce_product_variation');
    $priceConstraint = isset($fields['price']) && array_key_exists('DecionisPrice', $fields['price']->getConstraints());
    $promotion = $this->entityTypeManager->getDefinition('commerce_promotion', FALSE);
    $listeners = array_map(static fn ($listener) => is_array($listener) && is_object($listener[0]) ? get_class($listener[0]) : '', $this->dispatcher->getListeners('commerce_order.place.pre_transition'));
    $points = [
      'refund form' => $this->refundForm(),
      'price constraint' => $priceConstraint,
      'promotion constraint' => $promotion !== NULL && array_key_exists('DecionisPromotion', $promotion->getConstraints()),
      'order place transition' => in_array(OrderSubscriber::class, $listeners, TRUE),
    ];
    $overrides = [];
    $payment = $this->entityTypeManager->getDefinition('commerce_payment', FALSE);
    $handler = $payment?->getFormClass('operation');
    if ($handler !== NULL && $handler !== PaymentOperationForm::class) {
      $overrides[] = 'the payment operation form is replaced by ' . $handler;
    }
    $hooks = ['form_commerce_payment_form_alter'];
    foreach (array_keys($this->bundles->getBundleInfo('commerce_payment')) as $bundle) {
      $hooks[] = 'form_commerce_payment_' . $bundle . '_operation_form_alter';
    }
    foreach ($hooks as $name) {
      $this->moduleHandler->invokeAllWith($name, function (callable $hook, string $module) use (&$overrides): void {
        if (!str_starts_with($module, 'commerce') && !str_starts_with($module, 'decionis')) {
          $overrides[] = $module . ' alters the refund form';
        }
      });
    }
    foreach ($this->entityTypeManager->getStorage('entity_form_display')->loadByProperties(['targetEntityType' => 'commerce_product_variation']) as $display) {
      $widget = $display->getComponent('price')['type'] ?? NULL;
      if ($widget !== NULL && !in_array($widget, ['commerce_price_default', 'commerce_list_price'], TRUE)) {
        $overrides[] = 'variation price uses the custom widget ' . $widget . ' (' . $display->id() . ')';
      }
    }
    return ['points' => $points, 'overrides' => array_values(array_unique($overrides))];
  }

  /**
   * TRUE when the form alter really gates this site's refund form.
   *
   * Asks the same code the live form goes through, with the form object this
   * site would build, so a form it does not recognize counts as not wired.
   */
  private function refundForm(): bool {
    if (!$this->moduleHandler->hasImplementations('form_alter', 'decionis_commerce') || !$this->entityTypeManager->getDefinition('commerce_payment')->getFormClass('operation')) {
      return FALSE;
    }
    $form = [];
    $this->hooks->formAlter($form, (new FormState())->setFormObject($this->entityTypeManager->getFormObject('commerce_payment', 'operation')), '');
    return in_array([CommerceHooks::class, 'validateRefund'], $form['#validate'] ?? [], TRUE);
  }

}
