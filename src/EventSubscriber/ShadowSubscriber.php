<?php

declare(strict_types=1);

namespace Drupal\decionis\EventSubscriber;

use Drupal\decionis\Gate\Deferred;
use Drupal\decionis\Notification\Nudges;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Delivers local messages and runs HTTP only after an FPM response is finished.
 */
final class ShadowSubscriber implements EventSubscriberInterface {

  public function __construct(private readonly Deferred $deferred, private readonly Nudges $nudges) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // After authentication filtering (31), before Dynamic Page Cache (27),
    // which can stop the event on a previously visited administration page.
    return [KernelEvents::REQUEST => ['request', 30], KernelEvents::TERMINATE => ['terminate', 200]];
  }

  /**
   * Authentication has selected the recipient before their page is rendered.
   */
  public function request(RequestEvent $event): void {
    if ($event->isMainRequest() && $event->getRequest()->isMethod('GET')) {
      $this->nudges->deliver();
    }
  }

  /**
   * CLI and non-FPM servers leave work for the durable queue worker.
   */
  public function terminate(TerminateEvent $event): void {
    if ($event->isMainRequest()) {
      $this->deferred->finishResponse();
    }
  }

}
