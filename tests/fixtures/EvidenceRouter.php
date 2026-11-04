<?php

/**
 * @file
 * Serves a generated, non-production evidence fixture on loopback.
 */

declare(strict_types=1);

/**
 * Non-production loopback: serves only the generated test Dossier and JWKS.
 */
$fixture = json_decode((string) file_get_contents((string) getenv('DECIONIS_EVIDENCE_FIXTURE')));
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
if ($path === '/.well-known/decision-dossier-jwks.json') {
  print json_encode($fixture->jwks);
}
elseif (str_starts_with((string) $path, '/v1/protocol/dossiers/')) {
  print json_encode(['dossier' => $fixture->record]);
}
else {
  http_response_code(404);
  print '{"error":"SYNTHETIC_TEST_ROUTE_NOT_FOUND"}';
}
