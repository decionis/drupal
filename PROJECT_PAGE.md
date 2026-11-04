# Commerce Gate for Drupal: project-page source

Source copy for the [Decionis Commerce Gate full project](https://www.drupal.org/project/decionis).
The public beta uses the `decionis` machine name and `drupal/decionis` Composer identity.
The [downloads page](https://commerce.decionis.com/downloads) lists verified release availability,
installation steps, checksums, provenance and the Getting Started guide.

## Short description

See estimates from your store's own history before connecting an account. Then review commerce
policy decisions in Shadow and enforce one action when your workspace entitlement permits it.

## Description

**Initial beta availability.** Local Store X-ray and the existing Shadow journey are available.
Enforcement trials, tuned-limit publication, billing-contact confirmation and workspace ownership
claims are not yet available in this beta.

**Start with your store.** Store X-ray scans 30–365 days of local orders, refunds and promotions,
with a progress page and a headline for each currency. It shows orders below the suggested margin
floor, cumulative refunded payments over the limit, stacking, observed outside refunds and cost coverage. No account,
HTTP request or decision-log write is needed. Refund amounts are cumulative per payment and use available payment timestamps; they do not reconstruct individual partial-refund events. Every X-ray result is labelled an unsigned estimate.

**Tune limits with findings.** Per-currency suggestions come from your own history. Sparse data
uses explicit defaults. Editing a limit updates its counts; accepted values live in Drupal
configuration. These local limits drive the X-ray and sandbox. When connected, saving requests
publication through the bounded Drupal handoff. Each currency shows **Published as version …**
only after confirmation; otherwise it remains **Not yet published**. The confirmed version is used
for subsequent live checks. Native credentials retain their decision-only scopes.

**Cost coverage you can see.** Installation, updates and the repeatable recipe add an editable
Cost field to every variation type. Map an existing cost field or use the bounded CSV import in
the admin UI. Variation and order-line coverage remain visible; unknown or mismatched costs are
reported as not covered.

**Connect after the first insight.** One form collects business name, billing email, site URL and
terms acceptance. Credentials use Key and a State-held site/host binding, so config imports do not
disconnect the site and staging copies cannot use production approvers. The connection progress
page runs the first replay batches before landing on Decisions; cron handles the rest. Bounded
concurrency and source/entity/policy upserts prevent repeated replay from doubling its rows.
Service outages are shown honestly. Connect, mail fallback and enforcement depend on the deployed
service contracts; an unavailable entitlement keeps enforcement disabled.

**Shadow without waiting on the network.** Native checkout and admin-save checks record pending
work locally and evaluate after the response or through cron. Enforce keeps its synchronous
checkout budget and failure policy. The Decisions page shows each action's effective mode, the
largest catch in each currency and readable reasons. Completed remote decisions may link to
signed Dossiers; inline verification shows signature status, key and signed policy version when
available, and offers the complete payload to download. Pending, incomplete or invalid evidence
is never presented as verified.

**Enforce this one.** Each action has a control with its 90-day count, amount involved and expected
approval frequency. A permission-checked confirmation changes only that action, subject to the
server's current entitlement. When a trial is available, that confirmation starts the server's
14-day trial; visiting the page or retrying does not start another trial. Trial and payment-grace
expiry return actions to Shadow. A held refund or price change uses an exact-action, single-use
grant; changed, expired or already-run proposals are refused.

**Keep reviewing.** Deferred refusals produce one nudge for the authorized admin after a successful
save. An opt-in weekly digest uses Drupal mail and rechecks recipient permissions. A seven-check
protection score links missing setup steps to their fixes. Printable X-ray and Shadow reports
include CSV, separate currencies, coverage and top items. Aggregate reports are unsigned; Shadow
reports list available individual Dossier references and verification links.

## Scope and boundaries

The `decionis_commerce` module covers supported refund forms, variation price validation, promotions/coupons,
order review/placement and manual stock writes with Commerce Stock. Custom code that skips those
hooks or writes directly to the database can bypass them. The local sandbox, available through
the admin UI and Drush, checks wiring using rolled-back synthetic records and is labelled sandbox,
not production evidence.

Refunds issued outside Drupal never pass its preflight. A supported provider integration can make
one visible afterward only by updating the matching Drupal payment with a refund.

Fincra Support confirmed on 4 November 2026 that refunds cover underpayments (the full amount
received) and overpayments (the excess). Other refund requests require a separate customer payout
outside Drupal. No ordinary full or partial refund API for correctly paid purchases has been
confirmed. The module does not execute those payouts, and they are not automatically reflected
as Drupal refunds. Fincra stores should start with orders, prices and promotions, and review
payout authorization in their separate payment workflow. Failed Fincra payments recorded as
refunded are excluded from the outside-refund log.

Optional AgentSafe setup can hold a supported payment key under root or a separate service user.
The UI shows redacted file/command previews; installation remains a Drush/host action. Setup
requires readiness and service lifetime checks before moving the key. Cron reports and emails
outages; explicit rollback restores supported key access or prints host steps. A service under
Drupal's own account is a weaker boundary. Fincra compatibility patches remain required until
upstream releases them.

## Data and availability

The X-ray and limit drafting send nothing. Connection sends business name, billing email, site URL
and consent for provisioning. Remote policy requests use bounded commerce facts and keyed abuse
signals, excluding customer names, emails, addresses, coupon codes and payment details. Reports,
nudges and digests contain no customer data or coupon codes. Secrets are excluded from config
exports and logs.

This is a public beta without Drupal security-advisory coverage. **Connected historical replay is
metered against the workspace's governed-action allowance, even in Shadow Mode.** The local
Store X-ray is free and uses none of that allowance. Review [Build pricing](https://decionis.com/pricing#drupal-replay)
before connecting or sending a large history. Service availability, the Drupal provider deployment,
policy publication, Accounts entitlements and Core/Accounts mail remain required for connected
features. Drupal's optional digest uses its own mail setup. See the packaged README for
installation, defaults, validation scope and current limitations.

## Requirements and licence

Drupal 10.3+ or 11, Commerce 3.3.13+ (or 2.41+ on Drupal 10), PHP 8.1+ with `curl`/`sodium`
(PHP 8.3 for Drupal 11), Key and separately scheduled Drush/protected-URL cron. Page-triggered automatic cron is skipped when response completion is unconfirmed. Commerce Fincra currently requires Drupal 10.
Earlier Commerce releases with the 2026-10-28 advisories are excluded.

GPL-2.0-or-later. The `decionis/php-client` dependency is Apache-2.0.
