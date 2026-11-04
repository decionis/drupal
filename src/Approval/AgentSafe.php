<?php

declare(strict_types=1);

namespace Drupal\decionis\Approval;

use Decionis\Client\Config;
use Decionis\Client\Grant\Authority;
use Decionis\Client\Grant\Claim;
use Decionis\Client\Grant\Grant;
use Decionis\Client\Http\CurlClient;
use Decionis\Client\Http\Transport;
use Decionis\Client\Intent\Intent;
use Drupal\decionis\Gate\Credentials;
use Drupal\decionis\Gate\Settings;
use Drupal\decionis\Http\Bound;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface;

/**
 * The AgentSafe execution authority, reached with the site's key.
 *
 * Holds escalate as managed escalations, so the approver acts in Presence.
 */
final class AgentSafe implements Grants {

  public function __construct(
    private readonly Settings $settings,
    private readonly Credentials $credentials,
    private readonly ?ClientInterface $http = NULL,
    private readonly ?string $identity = NULL,
  ) {}

  /**
   * Every operation on a hold uses the connection that originally captured it.
   */
  public function bound(string $identity): self {
    return new self($this->settings, $this->credentials, $this->http, $identity);
  }

  /**
   * {@inheritdoc}
   */
  public function available(): bool {
    return $this->settings->connected() && ($this->identity === NULL || $this->settings->connectionIdentity() === $this->identity) && $this->credentials->apiKey() !== NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function escalate(Intent $intent): Grant {
    return $this->authority($intent)->decide($intent, 'ENFORCEMENT', NULL, ['mode' => 'MANAGED', 'approver' => ['role_id' => 'store_approver']]);
  }

  /**
   * {@inheritdoc}
   */
  public function approved(string $escalationId, Intent $intent): ?Grant {
    return $this->authority($intent)->escalation($escalationId, $intent);
  }

  /**
   * {@inheritdoc}
   */
  public function claim(Grant $grant, Intent $intent): Claim {
    return $this->authority($intent)->claim($grant, $intent);
  }

  /**
   * {@inheritdoc}
   */
  public function finalize(Grant $grant, Claim $claim, Intent $intent, string $outcome): void {
    $this->authority($intent)->finalize($grant, $claim, $intent, $outcome);
  }

  /**
   * The client for the authority API.
   */
  private function authority(Intent $intent): Authority {
    $identity = $this->identity ?? $this->settings->connectionIdentity();
    $key = $this->credentials->apiKey();
    if ($identity === NULL || $key === NULL) {
      throw new \LogicException('DECIONIS_NOT_CONNECTED');
    }
    $org = $this->settings->get('org_id');
    if ($identity === '' || $this->settings->connectionIdentity() !== $identity || $intent->tenantId !== $org) {
      throw new \LogicException('DECIONIS_CONNECTION_CHANGED');
    }
    $factory = new HttpFactory();
    $config = new Config($key, $org, $this->settings->get('api_base_url'), $this->settings->get('jwks_url'));
    $http = new Bound($this->http ?? new CurlClient($factory, $factory, 2000, 10000), function () use ($identity): void {
      if ($this->settings->connectionIdentity() !== $identity) {
        throw new \LogicException('DECIONIS_CONNECTION_CHANGED');
      }
    });
    return new Authority($config, new Transport($http, $factory, $factory, 'drupal-decionis/1.0'));
  }

}
