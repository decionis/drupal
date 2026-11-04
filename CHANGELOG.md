# Commerce Gate for Drupal changelog

## 0.1.2-beta1 — first Drupal.org public beta

Prepared on 2026-11-04 from Revision 1. The official full project and public Git mirror contain
the module distribution and all three required Commerce Fincra patches. Beta releases are not
covered by Drupal's security advisory policy.

- Added archive exclusions for development tools and a reproducible module-only export that
  retains the original source commit and per-file hashes in separate release evidence.
- Made DrupalCode's PHPCS, PHPStan, PHPUnit and local sandbox checks blocking, with separate
  Drupal 11/Commerce 3, Drupal 10/Commerce 3 and Drupal 10/Commerce 2 dependency builds.
- Removed the seven doc-comment coding-standard exclusions while preserving runtime behavior
  and PHPStan generic/shape types.
- Documented Drupal Composer installation and migration from the private ZIP repository.

Update with `composer require 'drupal/decionis:^0.1@beta' --with-dependencies`, then run
`drush updb -y` and `drush cr`. Review the README's service dependencies, supported hooks and
failure policies before enabling enforcement. This release does not change the Revision 1
runtime behavior or add remote policy publication, provider refunds or service entitlements.

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
