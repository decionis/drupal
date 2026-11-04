<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis\Support;

use Decionis\Client\Canonical\Jcs;
use Decionis\Client\Dossier\Verifier;

/**
 * A synthetic signed Dossier served locally; no real tenant or retained key.
 */
final class EvidenceApi {

  public const DOSSIER = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaa01';
  public const EVALUATION = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbb01';
  public const ORG = 'cccccccc-cccc-4ccc-8ccc-cccccccccc01';
  public const KEY_ID = 'synthetic-evidence-test';

  /**
   * The child process serving the synthetic evidence API.
   *
   * @var resource
   */
  private $process;

  /**
   * The temporary file containing the synthetic API response.
   */
  private string $file;

  /**
   * The base URL of the local evidence server.
   */
  public string $url;

  /**
   * The synthetic dossier record and public verification keys.
   */
  public array $fixture;

  public function __construct() {
    $secret = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
    $public = sodium_crypto_sign_publickey_from_secretkey($secret);
    $inputs = (object) ['action' => 'PRICE_CHANGE', 'currency' => 'EUR', 'amount' => 1250, 'empty_object' => new \stdClass()];
    $machine = (object) ['protocol_version' => '1.1', 'dossier_id' => self::DOSSIER, 'evaluation_id' => self::EVALUATION, 'verdict' => 'BLOCK', 'policy_reference' => (object) ['version' => 'signed-policy-v7'], 'input_snapshot_digest' => Verifier::jcsDigest($inputs), 'execution_eligible' => FALSE];
    $portable = (object) ['dossier_id' => self::DOSSIER, 'machine_readable' => $machine];
    $artifacts = [];
    foreach (['portable_artifact' => $portable, 'inputs_snapshot' => $inputs] as $kind => $document) {
      $bytes = (new Jcs())->encode($document);
      $artifacts[] = (object) ['artifact_kind' => $kind, 'document_path' => '/' . $kind, 'media_type' => 'application/json', 'canonicalization_profile' => Jcs::PROFILE, 'canonical_document_sha256' => hash('sha256', $bytes), 'signature' => sodium_bin2base64(sodium_crypto_sign_detached($bytes, $secret), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)];
    }
    sodium_memzero($secret);
    $payload = (object) [
      'dossier_id' => self::DOSSIER,
      'evaluation_id' => self::EVALUATION,
      'portable_artifact' => $portable,
      'inputs_snapshot' => $inputs,
      'integrity' => (object) ['proof_bundle' => (object) ['bundle_type' => 'decionis.decision_dossier.proof_bundle', 'version' => '2.1', 'algorithm' => 'Ed25519', 'key_id' => self::KEY_ID, 'rotation_policy' => (object) ['strategy' => 'JWKS_OVERLAP', 'active_key_id' => self::KEY_ID], 'artifacts' => $artifacts]],
    ];
    $this->fixture = [
      'record' => ['dossier_id' => self::DOSSIER, 'org_id' => self::ORG, 'decision_evaluation_id' => self::EVALUATION, 'dossier_payload' => $payload, 'evidence_hashes' => [], 'created_at' => gmdate('c')],
      'jwks' => ['keys' => [['kty' => 'OKP', 'crv' => 'Ed25519', 'alg' => 'EdDSA', 'use' => 'sig', 'kid' => self::KEY_ID, 'x' => sodium_bin2base64($public, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)]]],
    ];
    $file = tempnam(sys_get_temp_dir(), 'decionis-evidence-');
    if ($file === FALSE) {
      throw new \RuntimeException('Could not create test fixture.');
    }
    $this->file = $file;
    $this->write();
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    if ($socket === FALSE) {
      throw new \RuntimeException('Could not allocate loopback port.');
    }
    $address = stream_socket_get_name($socket, FALSE);
    fclose($socket);
    $this->url = 'http://' . $address;
    $process = proc_open([PHP_BINARY, '-S', $address, dirname(__DIR__, 2) . '/fixtures/EvidenceRouter.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, NULL, ['DECIONIS_EVIDENCE_FIXTURE' => $this->file]);
    if (!is_resource($process)) {
      throw new \RuntimeException('Could not start loopback evidence fixture.');
    }
    $this->process = $process;
    for ($i = 0; $i < 100; $i++) {
      $ready = @stream_socket_client('tcp://' . $address, $error, $message, 0.05);
      if (is_resource($ready)) {
        fclose($ready);
        return;
      }
      usleep(10000);
    }
    throw new \RuntimeException('Loopback evidence fixture did not start.');
  }

  /**
   * Lets a test replace only the synthetic server response.
   */
  public function write(): void {
    file_put_contents($this->file, json_encode($this->fixture, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
  }

  /**
   * Removes the local server and its generated public fixture.
   */
  public function close(): void {
    if (is_resource($this->process)) {
      proc_terminate($this->process);
      proc_close($this->process);
    }
    if (is_file($this->file)) {
      unlink($this->file);
    }
  }

}
