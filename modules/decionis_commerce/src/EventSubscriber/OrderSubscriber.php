<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce\EventSubscriber;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\decionis_commerce\Guard\Orders;
use Drupal\decionis_commerce\Guard\Refused;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * The order place transition.
 *
 * Checkout orders were already checked at the review step, before the
 * customer paid on- or off-site, so here the gate only logs. Orders placed
 * outside checkout (admin, API, imports) are checked here, and in Enforce a
 * BLOCK stops an unpaid order from being placed.
 */
final class OrderSubscriber implements EventSubscriberInterface {

  public function __construct(private readonly Orders $orders, private readonly RouteMatchInterface $routeMatch) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return ['commerce_order.place.pre_transition' => 'onPlace'];
  }

  /**
   * Checks an order placed outside checkout before the transition.
   */
  public function onPlace(WorkflowTransitionEvent $event): void {
    $order = $event->getEntity();
    if (!$order instanceof OrderInterface || Orders::decided($order)) {
      return;
    }
    $checkout = str_starts_with((string) $this->routeMatch->getRouteName(), 'commerce_checkout.') || str_starts_with((string) $this->routeMatch->getRouteName(), 'commerce_payment.checkout.');
    $outcome = $this->orders->check($order, 'order_place', $checkout);
    if ($outcome !== NULL && !$outcome->proceeds() && !$checkout && !Orders::paid($order)) {
      throw new Refused($outcome->message(FALSE));
    }
  }

}
