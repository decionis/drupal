# Commerce Gate for Drupal changelog

## 0.1.2-beta1 — first Drupal.org public beta

Prepared on 2026-11-04 with Revision 2. The official full project and public Git mirror contain
the module distribution and all three required Commerce Fincra patches. Beta releases are not
covered by Drupal's security advisory policy.

- Added archive exclusions for development tools and a reproducible module-only export that
  retains the original source commit and per-file hashes in separate release evidence.
- Made DrupalCode's PHPCS, PHPStan, PHPUnit and local sandbox checks blocking, with separate
  Drupal 11/Commerce 3, Drupal 10/Commerce 3 and Drupal 10/Commerce 2 dependency builds.
- Removed the seven doc-comment coding-standard exclusions while preserving runtime behavior
  and PHPStan generic/shape types.
- Documented Drupal Composer installation and migration from the private ZIP repository.
- Added the first-enforcement trial confirmation. Accounts starts the workspace's one 14-day
  trial; each action still needs its own administrator choice. Expired trials and grace periods
  immediately return actions to Shadow.
- Added bounded policy publication when limits are saved. Drupal pins only a confirmed version
  for the same connection and values, shows publication status per currency, and safely resolves
  uncertain requests before publishing another change. Local limits remain available when
  publication is unsupported or needs owner approval.
- Added a status-report warning for Shadow checks queued more than an hour, with scheduled-cron
  guidance. Reading the warning does not process queued work.
- Aligned installation and pricing guidance with the public downloads catalog. Connected
  historical replay is metered; the local Store X-ray uses no governed-action allowance.

Update with `composer require 'drupal/decionis:^0.1@beta' --with-dependencies`, then run
`drush updb -y` and `drush cr`. Review the README's service dependencies, supported hooks and
failure policies before enabling enforcement. Entitlements, policy publication, billing
enrollment and mail need the matching Accounts/Core services and their rollout prerequisites.
The new service authorities are disabled for this beta's initial rollout, pending the required
Account rollout evidence and live staging acceptance. The local Store X-ray and existing
Shadow journey remain available.
The native key retains only decision scopes. Fincra Support confirmed on 4 November 2026 that
refunds cover underpayments (full amount received) and overpayments (excess only). Other customer
refund requests require a separate payout outside Drupal. No ordinary refund API for correctly
paid purchases was confirmed, and these payouts are not automatically reflected as Drupal refunds.

## 0.1.1 — Revision 1 private beta

Prepared on 2026-11-04. The Composer identity remains `drupal/decionis`; this is a
private artifact release, not a Drupal.org project release or a claim of Drupal
security-advisory coverage.

- Added the guided first run, local Store X-ray, cost coverage and CSV import,
  per-currency limit suggestions, action-specific enforcement controls, printable
  reports and local sandbox tools.
- Moved live Shadow evaluations to deferred processing, with completed-result
  nudges, optional digests and a bounded connection replay. Explicit Drush or
  protected-URL cron is required where response completion cannot be confirmed.
- Bound credentials, queued work, entitlements and held approvals to the current
  site and connection. Configuration import preserves managed credentials;
  staging copies cannot use the original site's keys or approvals.
- Hardened AgentSafe setup, redacted previews, service lifetime checks, gateway
  key custody, watchdog alerts and explicit recovery. Rollback keeps raw payment
  keys out of exported Drupal configuration.
- Added this licence text and the governed private prerelease packaging path.

After adding the new ZIP to the site's Composer artifact repository, run
`composer update drupal/decionis --with-dependencies`, `drush updb -y` and
`drush cr`. Run database updates on the original site before making copies. Keep
the existing artifact available until deployment and recovery checks pass.

Local limit drafting does not publish remote policy. Provisioning, mail fallback,
entitlements, replay billing and managed approver delivery remain service-side
dependencies. Fincra has no ordinary Drupal refund operation. AgentSafe's loopback
setup needs a host design for multi-webhead deployments. Outages alert
administrators; key recovery remains explicit. The README describes the supported
hooks, failure policies and complete installation/recovery steps.

## 0.1.0 — earlier private-beta package example

Earlier installation instructions used this artifact version. No public
Drupal.org or Packagist release of `drupal/decionis` was found during the
2026-11-04 distribution audit. Version 0.1.1 avoids replacing a prior private
artifact that may already have been handed to a design partner.
