<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce\Hook;

use Drupal\commerce_checkout\Plugin\Commerce\CheckoutFlow\CheckoutFlowInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_payment\Form\PaymentOperationForm;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariationTypeInterface;
use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\decionis\Gate\Gate;
use Drupal\decionis\Gate\Background;
use Drupal\decionis_commerce\Guard\Orders;
use Drupal\decionis_commerce\Guard\Refunds;
use Drupal\decionis_commerce\Gateway\Custody;
use Drupal\decionis_commerce\Gateway\Watchdog;
use Drupal\decionis_commerce\Map\Actions;
use Drupal\decionis_commerce\Map\Attempts;
use Drupal\decionis_commerce\Map\CostFields;
use Drupal\decionis_commerce\Plugin\QueueWorker\ReplayWorker;
use Drupal\decionis_commerce\Sandbox\Quiet;
use Drupal\state_machine\Form\StateTransitionConfirmForm;
use Drupal\state_machine\Form\StateTransitionFormInterface;

/**
 * Wires the commerce hook points.
 *
 * Object-oriented hooks for Drupal 11.1+; decionis_commerce.module calls the
 * same methods on Drupal 10.
 */
final class CommerceHooks {

  use StringTranslationTrait;

  /**
   * Payments whose refund went through the refund gate in this request.
   *
   * @var array<string, true>
   */
  private static array $gated = [];

  /**
   * The field types a unit cost can live in.
   */
  private const COST_TYPES = ['commerce_price', 'decimal', 'float', 'integer'];

  /**
   * Keeps a gateway secret under its existing AgentSafe custody.
   */
  #[Hook('commerce_payment_gateway_presave')]
  public function gatewayPresave(PaymentGatewayInterface $gateway): void {
    \Drupal::service(Custody::class)->presave($gateway);
  }

  /**
   * Checks the local payment service independently of account refreshes.
   */
  #[Hook('cron')]
  public function cron(): void {
    if (!Background::allowed()) {
      return;
    }
    \Drupal::service(Watchdog::class)->run();
  }

  /**
   * Builds the payment gateway outage notification.
   *
   * @param string $key
   *   The Decionis mail template identifier.
   * @param array<string, mixed> $message
   *   The message whose subject and body are populated for the mail system.
   * @param array<string, mixed> $params
   *   The contextual parameters supplied by the mail caller.
   */
  #[Hook('mail')]
  public function mail(string $key, array &$message, array $params): void {
    if ($key === 'gateway_watchdog') {
      $message['subject'] = (string) $this->t('AgentSafe payment gateway unavailable');
      $message['body'][] = (string) $this->t('AgentSafe holds the payment key and is unavailable or uses an earlier site connection. Check its service and connection, or run drush decionis:gateway-rollback. The key remains protected until explicit rollback.');
    }
  }

  /**
   * Gives variation types created after installation an editable unit cost.
   */
  #[Hook('commerce_product_variation_type_insert')]
  public function variationTypeInserted(ProductVariationTypeInterface $type): void {
    // A temporary sandbox bundle uses in-memory costs. Avoid MySQL schema
    // changes that would commit its rollback transaction.
    if (!Quiet::active()) {
      \Drupal::service(CostFields::class)->bundle($type->id());
    }
  }

  /**
   * Puts the price constraint on the variation price field.
   *
   * @param array<string, \Drupal\Core\Field\FieldDefinitionInterface> $fields
   *   The base field definitions to augment with the price constraint.
   * @param \Drupal\Core\Entity\EntityTypeInterface $entityType
   *   The entity type owning the field definitions.
   */
  #[Hook('entity_base_field_info_alter')]
  public function baseFields(array &$fields, EntityTypeInterface $entityType): void {
    if ($entityType->id() === 'commerce_product_variation' && isset($fields['price'])) {
      $fields['price']->addConstraint('DecionisPrice');
    }
  }

  /**
   * Counts each coupon code a shopper tries, for the brute-force signal.
   *
   * @param array<string, mixed> $inlineForm
   *   The coupon redemption element receiving the validation callback.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The current state of the containing Commerce form.
   * @param array<string, mixed> $completeForm
   *   The complete form containing the coupon redemption element.
   */
  #[Hook('commerce_inline_form_coupon_redemption_alter')]
  public function couponForm(array &$inlineForm, FormStateInterface $formState, array &$completeForm): void {
    $inlineForm['#element_validate'][] = [self::class, 'couponTried'];
  }

  /**
   * Records a coupon attempt when Commerce validates the submitted code.
   *
   * This covers both the apply button and the checkout step's primary button.
   *
   * @param array<string, mixed> $element
   *   The coupon redemption element holding the submitted code.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The form state containing the code and triggering button.
   */
  public static function couponTried(array &$element, FormStateInterface $formState): void {
    $trigger = $formState->getTriggeringElement() ?? [];
    $code = $formState->getValue(array_merge($element['#parents'] ?? [], ['code']));
    if ((($trigger['#name'] ?? NULL) === 'apply_coupon' || ($trigger['#button_type'] ?? NULL) === 'primary') && is_string($code) && trim($code) !== '') {
      \Drupal::service(Attempts::class)->record();
    }
  }

  /**
   * Puts the promotion constraint on promotions and coupons.
   *
   * @param array<string, \Drupal\Core\Entity\EntityTypeInterface> $entityTypes
   *   The entity definitions to augment with the promotion constraint.
   */
  #[Hook('entity_type_alter')]
  public function entityTypes(array &$entityTypes): void {
    foreach (['commerce_promotion', 'commerce_promotion_coupon'] as $id) {
      if (isset($entityTypes[$id])) {
        $entityTypes[$id]->addConstraint('DecionisPromotion');
      }
    }
  }

  /**
   * Gates the refund form, the place-order forms and the checkout review step.
   *
   * Commerce builds the payment operation form's id from the payment's type
   * (commerce_payment_payment_manual_operation_form), so it is known by its
   * class.
   */
  #[Hook('form_alter')]
  public function formAlter(array &$form, FormStateInterface $formState, string $formId): void {
    if ($formId === 'decionis_settings_form') {
      $this->costField($form);
    }
    $object = $formState->getFormObject();
    if ($object instanceof PaymentOperationForm) {
      $form['#validate'][] = [self::class, 'validateRefund'];
    }
    if ($object instanceof StateTransitionFormInterface || $object instanceof StateTransitionConfirmForm) {
      $form['#validate'][] = [self::class, 'validatePlace'];
    }
    if (str_starts_with($formId, 'commerce_checkout_flow_') && ($form['#step_id'] ?? NULL) === 'review' && $object instanceof CheckoutFlowInterface) {
      $form['#validate'][] = [self::class, 'validateReview'];
    }
  }

  /**
   * Forgets a hook list cached before this module was installed.
   *
   * On Drupal 10 `drush recipe` can leave one behind, and the first cache
   * rebuild still reads it from memory. Entity definitions would then be
   * rebuilt without the price and promotion constraints, until the next
   * rebuild. Drupal 11.1+ compiles its hook lists into the container, so only
   * the Drupal 10 shim calls this.
   */
  public function rebuild(): void {
    \Drupal::moduleHandler()->resetImplementations();
    \Drupal::entityTypeManager()->clearCachedDefinitions();
    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
  }

  /**
   * Adds the unit-cost field selector to the Decionis settings form.
   *
   * Lists the recipe's cost field and other fields that can hold unit cost.
   *
   * @param array<string, mixed> $form
   *   The settings form receiving the field selector and submit callback.
   */
  private function costField(array &$form): void {
    $options = ['' => (string) $this->t('None: margin rules report "not covered"')];
    foreach (\Drupal::service('entity_field.manager')->getFieldStorageDefinitions('commerce_product_variation') as $name => $storage) {
      // A configurable number or price field; never the sale price itself.
      if (!$storage->isBaseField() && in_array($storage->getType(), self::COST_TYPES, TRUE)) {
        $options[$name] = $storage->getLabel() . ' (' . $name . ')';
      }
    }
    $form['cost'] = [
      '#type' => 'details',
      '#title' => $this->t('Cost base'),
      '#open' => TRUE,
      'cost_field' => [
        '#type' => 'select',
        '#title' => $this->t('Variation field holding the unit cost'),
        '#options' => $options,
        '#default_value' => (string) \Drupal::config('decionis_commerce.settings')->get('cost_field'),
        '#description' => $this->t('Margin rules need a cost base. Map the field your store keeps cost in, or fill the cost field the recipe adds with <code>drush decionis:cost-import costs.csv</code>.'),
      ],
    ];
    $form['#submit'][] = [self::class, 'saveCostField'];
  }

  /**
   * Saves the mapped cost field.
   *
   * @param array<string, mixed> $form
   *   The settings form with the allowed cost-field options.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The submitted state containing the selected field name.
   */
  public static function saveCostField(array &$form, FormStateInterface $formState): void {
    $field = (string) $formState->getValue('cost_field');
    if (array_key_exists($field, $form['cost']['cost_field']['#options'] ?? [])) {
      \Drupal::configFactory()->getEditable('decionis_commerce.settings')->set('cost_field', $field)->save();
    }
  }

  /**
   * Refund form validation: runs before the gateway's refund call.
   */
  public static function validateRefund(array &$form, FormStateInterface $formState): void {
    $object = $formState->getFormObject();
    $payment = $object instanceof EntityFormInterface ? $object->getEntity() : NULL;
    $amount = $formState->getValue(['payment', 'amount']);
    if (\Drupal::routeMatch()->getRawParameter('operation') !== 'refund' || !$payment instanceof PaymentInterface || !is_array($amount) || !is_numeric($amount['number'] ?? NULL) || $formState->hasAnyErrors()) {
      return;
    }
    $outcome = \Drupal::service(Refunds::class)->check($payment, new Price((string) $amount['number'], (string) ($amount['currency_code'] ?? $payment->getAmount()?->getCurrencyCode())));
    if ($outcome === NULL) {
      return;
    }
    self::$gated[(string) $payment->id()] = TRUE;
    if (!$outcome->proceeds()) {
      $formState->setErrorByName('payment][amount', $outcome->message(FALSE));
    }
    elseif ($outcome->mode === 'shadow' && in_array($outcome->verdict?->value, ['BLOCK', 'ESCALATE'], TRUE)) {
      \Drupal::messenger()->addWarning(t('Shadow Mode: Decionis would have @verdict this refund (@reasons). It ran.', [
        '@verdict' => $outcome->verdict?->value === 'BLOCK' ? 'blocked' : 'held',
        '@reasons' => implode(', ', $outcome->reasons) ?: 'policy',
      ]));
    }
  }

  /**
   * Checkout review step: the order margin check, before any payment.
   */
  public static function validateReview(array &$form, FormStateInterface $formState): void {
    $flow = $formState->getFormObject();
    if (!$flow instanceof CheckoutFlowInterface || $formState->hasAnyErrors()) {
      return;
    }
    $order = $flow->getOrder();
    $outcome = \Drupal::service(Orders::class)->check($order, 'checkout_review', TRUE);
    if ($outcome !== NULL && !$outcome->proceeds()) {
      $formState->setError($form, $outcome->message(TRUE));
    }
  }

  /**
   * Place-order forms: the order margin check, where a refusal can be shown.
   *
   * A check at the transition itself runs inside the order's save, so the
   * refusal could only surface as an error page.
   */
  public static function validatePlace(array &$form, FormStateInterface $formState): void {
    $object = $formState->getFormObject();
    $transition = $object instanceof StateTransitionConfirmForm ? $object->getTransition() : ($formState->getTriggeringElement()['#transition'] ?? NULL);
    $order = $object instanceof StateTransitionFormInterface || $object instanceof StateTransitionConfirmForm ? $object->getEntity() : NULL;
    if (!$order instanceof OrderInterface || $transition?->getId() !== 'place' || $formState->hasAnyErrors()) {
      return;
    }
    $outcome = \Drupal::service(Orders::class)->check($order, 'order_place', FALSE);
    if ($outcome !== NULL && !$outcome->proceeds() && !Orders::paid($order)) {
      $formState->setError($form, $outcome->message(FALSE));
    }
  }

  /**
   * Logs refunds that reach a payment without the refund gate.
   *
   * A gateway webhook or a provider dashboard. Logged as made outside Decionis,
   * with the Shadow verdict they would have had.
   */
  #[Hook('commerce_payment_update')]
  public function paymentUpdate(PaymentInterface $payment): void {
    $original = method_exists($payment, 'getOriginal') ? $payment->getOriginal() : ($payment->original ?? NULL);
    if (!$original instanceof PaymentInterface || isset(self::$gated[(string) $payment->id()])) {
      return;
    }
    $state = $payment->getState()->getId();
    $before = $original->getRefundedAmount();
    $after = $payment->getRefundedAmount();
    if (!in_array($state, ['refunded', 'partially_refunded'], TRUE) || $state === $original->getState()->getId() && ($after === NULL || ($before !== NULL && !$after->greaterThan($before)))) {
      return;
    }
    // commerce_fincra maps a failed payment to "refunded" on return: no money
    // moved back, so it is not a refund.
    if ($payment->getRemoteState() === 'failed') {
      return;
    }
    $amount = $after !== NULL && $before !== NULL && $after->greaterThan($before) ? $after->subtract($before) : $payment->getAmount();
    if ($amount === NULL || \Drupal::service(Gate::class)->bypassed()) {
      return;
    }
    [$action, $context] = \Drupal::service(Actions::class)->refund($original, $amount, 'outside_refund', 'drupal-outside');
    \Drupal::service(Gate::class)->observe($action, $context);
  }

  /**
   * Starts the 90-day replay when the site connects.
   */
  #[Hook('decionis_connected')]
  public function connected(string $orgId): void {
    ReplayWorker::enqueue((int) (\Drupal::config('decionis_commerce.settings')->get('replay_days') ?: 90));
  }

}
