// Loopback-only, synthetic Commerce Gate fixture. Never contacts an upstream.
import { createHash, randomUUID } from 'node:crypto';
import { createServer } from 'node:http';

const mode = process.argv[2] ?? 'ok';
const calls = [];
const attempts = new Map();
const decisions = new Map();
let trial = { state: 'trial_available', enforcement: false };
let active = 0;
let peak = 0;
const server = createServer((request, response) => {
  const url = new URL(request.url, 'http://127.0.0.1');
  if (url.pathname === '/stats') {
    response.end(JSON.stringify({ active, peak, calls }));
    return;
  }
  let raw = '';
  request.on('data', (chunk) => { raw += chunk; });
  request.on('end', () => {
    const body = raw ? JSON.parse(raw) : {};
    const key = request.headers['idempotency-key'] ?? '';
    const digest = createHash('sha256').update(raw).digest('hex');
    const attempt = (attempts.get(key) ?? 0) + 1;
    attempts.set(key, attempt);
    const call = {
      path: url.pathname,
      key,
      digest,
      policy: body.policy_version ?? null,
      exact: body.require_exact_policy_version ?? null,
      actor: body.context?.commerce_action?.actor ?? null,
      entity: body.context?.payload?.order_id ?? null,
      started: Date.now(),
    };
    calls.push(call);
    active++;
    peak = Math.max(peak, active);
    response.on('close', () => { active--; });
    const send = (status, payload) => {
      call.finished = Date.now();
      response.writeHead(status, { 'Content-Type': 'application/json' });
      response.end(JSON.stringify(payload));
    };
    if (url.pathname === '/v1/drupal/connect/start') {
      send(201, { connect_id: 'synthetic-replay-connect', challenge: 'synthetic-replay-challenge' });
      return;
    }
    if (url.pathname === '/v1/drupal/connect/verify') {
      send(200, {
        status: 'connected',
        org_id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1',
        api_key: 'dcy_aaaaaaaa_synthetic-site-scoped-test-key',
        mode: 'shadow',
      });
      return;
    }
    if (mode === 'trial' && ['/v1/drupal/status', '/v1/drupal/entitlement/trial'].includes(url.pathname)) {
      const org = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1';
      if (request.headers.authorization !== 'Bearer dcy_aaaaaaaa_synthetic-site-scoped-test-key' || Object.keys(body).length !== 1 || body.org_id !== org) {
        send(400, { error: 'INVALID_SYNTHETIC_REQUEST' });
        return;
      }
      if (url.pathname.endsWith('/trial')) {
        if (typeof key !== 'string' || !key || key.length > 200) {
          send(400, { error: 'IDEMPOTENCY_KEY_REQUIRED' });
          return;
        }
        if (trial.state === 'trial_available') {
          trial = { state: 'trial', enforcement: true, ends_at: new Date(Date.now() + 14 * 86400000).toISOString() };
        }
      }
      send(200, { org_id: org, entitlement: trial });
      return;
    }
    if (url.pathname === '/redirect') {
      response.writeHead(302, { Location: '/reached' });
      response.end();
      return;
    }
    if (url.pathname === '/oversize') {
      response.end('X'.repeat(2097153));
      return;
    }
    const delay = url.pathname === '/hang' ? 2000 : Number(url.searchParams.get('ms') ?? 100);
    setTimeout(() => {
      if (response.destroyed) return;
      if (mode === 'outage' || url.pathname === '/unavailable' || (url.pathname === '/retry' && attempt === 1)) {
        send(503, { error: 'SYNTHETIC_UNAVAILABLE' });
      } else if (url.pathname === '/v1/protocol/evaluate-decision') {
        const previous = decisions.get(key);
        if (previous && previous.digest !== digest) {
          send(409, { error: 'IDEMPOTENCY_CONFLICT' });
          return;
        }
        const decision = previous?.decision ?? {
          outcome: 'REJECT',
          confidence: 1,
          policy_version: mode === 'mismatch' ? 'fixture-wrong-policy' : (body.policy_version ?? 'fixture-policy-1'),
          objective_profile: 'synthetic-loopback',
          dossier_id: randomUUID(),
          evaluation_id: randomUUID(),
          mode: 'SHADOW',
          fallback_to_legacy: false,
          idempotent_replay: false,
          reason_codes: ['below_margin_floor'],
          governance_metrics: { override_drift_rate: 0, governance_tension_index: 0, boundary_volatility_score: 0, decision_volume_under_governance: 0 },
        };
        decisions.set(key, { digest, decision });
        send(200, { ...decision, idempotent_replay: !!previous });
      } else {
        send(200, { ok: true });
      }
    }, delay);
  });
});
server.listen(0, '127.0.0.1', () => process.stdout.write(`http://127.0.0.1:${server.address().port}\n`));
// A failed parent test must not leave a fixture process running indefinitely.
setTimeout(() => process.exit(0), 120000).unref();
