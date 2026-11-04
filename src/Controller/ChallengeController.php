<?php

declare(strict_types=1);

namespace Drupal\decionis\Controller;

use Drupal\Core\State\StateInterface;
use Drupal\decionis\Connect\Connector;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;

/**
 * Serves the one-time connect challenge at /.well-known/decionis-challenge.
 *
 * Decionis fetches it while the connect request is open: it proves the
 * request came from the site at this URL, holding this site key. Outside
 * that window the route answers 404.
 */
final class ChallengeController implements ContainerInjectionInterface {

  public function __construct(private readonly StateInterface $state) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('state'));
  }

  /**
   * Returns the pending challenge proof, or 404 outside a connect.
   */
  public function serve(): JsonResponse {
    $pending = $this->state->get(Connector::STATE);
    $proof = is_array($pending) ? ($pending['proof'] ?? NULL) : NULL;
    $expires = is_array($pending) ? (int) ($pending['expires'] ?? 0) : 0;
    if (!is_array($proof) || $expires < time()) {
      return new JsonResponse(['error' => 'NO_PENDING_CHALLENGE'], 404, ['Cache-Control' => 'no-store']);
    }
    return new JsonResponse($proof, 200, ['Cache-Control' => 'no-store']);
  }

}
