<!--
  Public links checked on 2026-11-04: drupal.org/project/key,
  drupal.org/project/commerce_fincra, decionis.com/contact, decionis.com/pricing,
  github.com/decionis/agent-safe-pipeline, npmjs.com/package/@decionis/verify
  (registry API), packagist.org/packages/decionis/php-client.
  drupal.org/project/decionis and github.com/decionis/drupal now resolve to the
  official full project and the public module-only distribution mirror.
-->

# Commerce Gate for Drupal

See what your own store's recent orders, refunds and promotions reveal before connecting an
account. The **Store X-ray** runs on the site, separates currencies and shows where cost data is
missing. It sends nothing off the site and labels its results as estimates.

After connection, Commerce Gate evaluates commerce actions against your workspace's policy.
Shadow Mode records what would have been held or blocked without holding up a shopper or an admin
save. You can then choose **Enforce this one** for an action when the server confirms an
entitlement. Remote decisions can carry signed Decision Dossiers; pending checks and local
estimates are identified separately.

## Availability and requirements

Local Store X-ray and the existing Shadow journey are available. Enforcement trials, tuned-limit
publication, billing-contact confirmation and workspace ownership claims are not yet available in
this beta.

This module is a public beta. The official [Decionis Commerce Gate project](https://www.drupal.org/project/decionis)
hosts the public release series, with a [module-only GitHub mirror](https://github.com/decionis/drupal).
Use the [downloads page](https://commerce.decionis.com/downloads) for verified package availability,
release files, checksums, provenance and the Getting Started guide.
The machine name remains `decionis` and the Composer identity remains `drupal/decionis`.
Beta releases are not covered by Drupal's security advisory policy. The earlier private **0.1.1**
artifact remains a separate historical release. [Contact Decionis](https://decionis.com/contact)
for connected-service access and support.

- Drupal 10.3 or later, or Drupal 11; PHP 8.1 or later, with PHP 8.3 for Drupal 11.
- Drupal Commerce 3.3.13 or later, or Commerce 2.41 or later on Drupal 10. Earlier Commerce
  releases carry the 2026-10-28 security advisories and are excluded by the package.
- PHP's `curl` and `sodium` extensions, and the [Key](https://www.drupal.org/project/key) module.
- Scheduled Drush cron or Drupal’s protected cron URL for deferred Shadow checks, the remaining replay, optional digests and gateway health. Automatic cron on a page request does no Commerce Gate network work unless FPM has confirmed that the response ended.

X-ray, limit tuning, cost coverage and the local sandbox work without the API. Connect and remote
replay need the service and its Drupal-provider deployment. Enforcement needs a server-issued
entitlement; the module does not grant a trial itself. The email-code fallback and managed
billing/approver notifications also depend on Core/Accounts mail. Drupal's optional site-local
digest has its own mail configuration.

## First run

### 1. Install and add costs

In a Drupal project with a supported Commerce version, install the module with Composer, which
resolves the PHP client and module dependencies:

```bash
composer require 'drupal/decionis:^0.1@beta'
```

Projects built from `drupal/recommended-project` already use `https://packages.drupal.org/8`. If
yours does not, first run `composer config repositories.drupal composer https://packages.drupal.org/8`.
The [downloads page](https://commerce.decionis.com/downloads) has the ZIP alternative, its checksum
and provenance.

Then enable the module and apply the recipe. A global `drush` works the same as `vendor/bin/drush`
where it is installed.

```bash
vendor/bin/drush en decionis_commerce -y
cd web
php core/scripts/drupal recipe modules/contrib/decionis/recipes/decionis_commerce
cd ..
vendor/bin/drush cr
```

If Commerce and Drush are not installed yet, first run
`composer require 'drupal/commerce:^3.3.13' drush/drush`. Existing Commerce 2 stores on Drupal 10
can keep Commerce 2.41 or later. `web` is the Drupal root in these examples; some projects use
`docroot`. After upgrading an existing installation, run `vendor/bin/drush updb -y` and
`vendor/bin/drush cr` from the project root.

When upgrading from the private ZIP instructions, remove the old Composer artifact repository
entry with `composer config --unset repositories.decionis` before requiring the public beta.
Keep the old ZIP and the previous Composer lock file available for recovery; the package name
has not changed.

Installation, the repeatable recipe and the update hook add an editable **Cost** field to every
product variation type, including types added later. Existing values and custom form widgets are
preserved. Under **Cost base** at `/admin/commerce/config/decionis`, you can instead select the
field where your store already records unit costs.

Enter costs in each variation's sale currency. The X-ray, settings and status report show both
variation coverage and order-line coverage. Missing or mismatched costs remain **not covered**.
To import a spreadsheet, export `sku,cost` (optionally `sku,cost,currency`) and use **Import variation
costs** at `/admin/commerce/decionis/cost-import`. The UI accepts up to 2 MiB and 5,000 rows, checks
all rows before starting its Batch run, and reports any save failure. The Drush alternative remains:

```bash
vendor/bin/drush decionis:cost-import costs.csv
```

### 2. Run the Store X-ray

Follow the install message to `/admin/commerce/decionis/x-ray`. Users need the
_administer decionis_ permission. Choose 90 days, or any window from 30 to 365 days, and run the
scan through its progress page. It uses your site's history, without an account or an HTTP call.

The result has one headline per currency: orders below the suggested margin floor and the
estimated value below that floor, cumulative refunded payments over the limit, stacked promotions, observed outside
refunds and cost coverage. It retains totals and the top five safe references per action and
currency in State. It creates no decision rows or Dossiers.

The page says **estimate on your site, using suggested limits** and **nothing left this site**.
Amounts involved in a flagged action are not a claim of money saved. Historical margins use
available current unit costs; promotion estimates use the configuration still present on the
site. Refund history contains each payment’s cumulative refunded amount, not individual refund events. A pair of EUR 300 partial refunds therefore appears as one EUR 600 payment total. Its window uses the available changed time on Commerce 3 or payment completion time on Commerce 2. This is a starting point for review, not a reconstruction of every past policy decision.

### 3. Tune the limits

At `/admin/commerce/config/decionis`, each currency has a refund ceiling, margin floor, stacking
ceiling and repeat-refund limit. Findings show the observations behind each suggestion; editing a
value updates the counts. Saving keeps the limits in Drupal configuration, so normal config sync
can deploy them. Rerun an existing X-ray after changing limits.

Each signal needs at least 20 observations to draft a percentile. Refunds and stacking use the
95th percentile; the repeat threshold is one above its 95th percentile because reaching it flags
an action. The margin floor uses the 10th percentile and never exceeds the observed median.
Sparse data uses these explicit starting points:

| Currency |                                    Refund ceiling |
| -------- | ------------------------------------------------: |
| EUR      |                                               500 |
| USD      |                                               500 |
| GBP      |                                               400 |
| NGN      |                                           150,000 |
| GHS      |                                             5,000 |
| Other    | 0, until tuned or supported by sufficient history |

The other defaults are a 12% margin floor (capped at the observed median when available), 30%
stacking ceiling and repeat threshold 3. These are starting amounts, not exchange-rate conversions.
The page identifies every fallback and its sample count.

Accepted limits drive the local X-ray and sandbox. When connected, **Save configuration** also
requests publication through the bounded Drupal handoff. The native key retains only decision
scopes. Each currency is labelled **Published as version …** only after the service confirms
publication; that version is pinned for subsequent live checks. Otherwise it remains **Not yet
published** and the reason is shown. A 403/404 response keeps limits local. A conflict or owner-review
requirement must be resolved before assuming the local values govern remote decisions.

### 4. Connect, then see the first replay results

Open `/admin/commerce/config/decionis/connect`. The form asks for business name, billing email,
site URL and acceptance of the terms. You do not paste an API key.

The service fetches a signed one-time challenge from `/.well-known/decionis-challenge` or
`/decionis/challenge`. Allow these routes while connecting, including during maintenance mode.
A site behind HTTP authentication or a firewall must allow the challenge routes. The email-code
fallback for an unreachable site requires the external mail sender; while unavailable it reports
the service error instead of claiming a code was sent.

After connecting in Shadow, a progress page evaluates the first bounded replay batches before
opening **Decisions** at `/admin/commerce/decionis/decisions`. Cron continues the remaining
90-day history. Remote concurrency defaults to four requests and is configurable from one to
eight. Repeating the same source/entity/policy replay updates its row instead of doubling counts.
If the API is unavailable, the page shows the actual failed/ungoverned status; it cannot promise a
signed catch. **Connected historical replay is metered against the workspace's governed-action
allowance, even in Shadow Mode.** Review the allowance before connecting or replaying a large
history. Local Store X-ray is free and uses none of that allowance. See
[Build pricing](https://decionis.com/pricing#drupal-replay).

Connection identity and key references live in State; Key stores the credentials. Importing a
pre-connect configuration snapshot does not disconnect the site. Credentials are bound to the
site URL and host. A copied site drops to Shadow, shows a copy banner and offers reconnect or
local disconnect. It cannot use the original site's pending approvals. Run database updates on
the original site before making copies; a legacy unbound installation cannot identify its own
history before the first binding is established.

### 5. Review a catch and enforce one action

The Decisions page shows every action's effective mode, plain-language reasons and the largest
held or blocked item in each currency. **Verify** displays signature status, key id, signed policy
version when present, and evidence completeness. **Download Dossier** preserves the full Dossier payload, including its signed artifacts. An unavailable, incomplete or invalid proof is never labelled verified.

Each action links to **Enforce this one**, showing the last 90 days' count, amount involved and
expected approval frequency. Only that action changes, through a permission-checked confirmation
form. A server-issued **trial available** state lets that confirmation request the 14-day trial;
page views and repeated submissions cannot restart it. Only the selected action is enabled. The
control stays disabled with the server's reason while entitlement is absent or expired. Trial and
payment-grace deadlines return actions to Shadow locally when they pass; an active paid entitlement
keeps enforcement available. [Pricing](https://decionis.com/pricing) describes the Build plan.
Settings also allow returning an action to Shadow without deleting history.

For a Fincra store, start with orders, prices or promotions. Commerce Fincra has no ordinary
refund operation in Drupal. Fincra advises a separate customer payout for refund requests other
than qualifying underpayments or overpayments; the module does not execute those payouts.

## Daily review and reports

Live Shadow checks save a local pending record and send the request after the response has ended,
where FPM supports it, or through a cron queue. They do not spend the synchronous checkout
budget. A completed decision replaces the pending result once. If an admin price, promotion or
refund save would have been refused, its authorized actor gets one nudge on a subsequent page
load after evaluation. No nudge is delivered for a rolled-back save.

The opt-in weekly digest is configured in settings: enabled state, permitted recipients and day.
Drupal sends it only to selected active users who still have _administer decionis_. It contains
aggregate currency totals and links to the per-action controls, without customer data or coupon
codes. Schedule `vendor/bin/drush cron` or the protected cron URL independently of page traffic, and configure Drupal mail for delivery. On hosts where response completion cannot be confirmed, page-triggered automatic cron leaves this work queued so it cannot delay a save or checkout.

**Protection checklist** at `/admin/commerce/decionis/checklist` explains seven weighted checks:
connection, active hooks, cost coverage, tuned limits, an enforced action, payment-key custody and
observed outside refunds. Each missing check links to a fix. This setup score is not a safety
certification or transaction authorization.

The **Owner and agency report** offers X-ray and 90-day Shadow views, CSV exports and browser
printing to PDF. Aggregate reports are unsigned; the X-ray is explicitly an estimate. The Shadow
report lists available individual Dossier references and verification links. No currency conversion
or customer detail is added.

## Enforcement and coverage

| Result    | Refund, price, promotion, stock in Enforce                                    | Checkout in Enforce                                      |
| --------- | ----------------------------------------------------------------------------- | -------------------------------------------------------- |
| ALLOW     | Runs                                                                          | Order proceeds                                           |
| ESCALATE  | Refunds/prices wait for an approver; unsupported held actions are refused     | Proceeds; a shopper is never held                        |
| BLOCK     | Refused with the policy's reason                                              | Refused at review with a neutral message                 |
| No answer | Refused by default, or runs as ungoverned under the configured failure policy | Proceeds within the configured budget, marked ungoverned |

The default synchronous checkout budget remains 120 ms. Shadow permits the action and records
its eventual result separately. Refunds are checked on the refund form before the gateway call;
prices and promotions at their entity-validation hooks; checkout at review before payment; other
orders at their placement hooks; manual stock writes with Commerce Stock before the transaction.
Custom code that skips forms/entity validation or writes directly to the database can bypass
these hook points. Unknown cost prevents a margin-coverage claim.

Held refunds and price changes appear at `/admin/commerce/decionis/approvals`. A user with
_approve decionis holds_ opens the exact proposal in Presence. A valid single-use grant can run it
once; changed, expired, already-run or stale-connection proposals are refused. Managed approver
notifications remain dependent on the service's delivery setup.

Refunds issued outside Drupal never pass its preflight. Where a supported provider integration
updates the matching Drupal payment with a refund, Commerce Gate can show an outside-refund
observation and the decision that would have applied. An empty log does not prove that no refund
occurred.

Fincra Support confirmed on 4 November 2026 that its refund service covers underpayments
(returning the full amount received) and overpayments (returning only the excess). Other refund
requests require a separate customer payout outside Drupal. No ordinary full or partial refund
API for correctly paid purchases has been confirmed. The module does not execute these payouts,
and they are not automatically reflected as Drupal refunds. Review payout authorization in the
separate payment workflow your team uses. Failed Fincra payments recorded as refunded are
excluded from observations; the order check runs before the off-site payment redirect.

## What leaves the site

The X-ray and local limit drafting send nothing. Connecting sends the business name, billing
email, public site URL and consent needed to provision the workspace.

Remote evaluations send bounded commerce facts: amounts, currencies, SKUs, costs, order and
promotion ids, coupon counts and keyed hashes for abuse signals. They exclude customer names,
emails, addresses, coupon codes and payment details. Local recipient user ids support nudges and
mail; they are not added to policy requests. Reports and digests omit customer data and coupon
codes. API keys and site credentials are excluded from configuration exports and logs.

## Optional admin tools and AgentSafe

The **Local sandbox** at `/admin/commerce/decionis/sandbox` runs the same synthetic-store report
as `vendor/bin/drush decionis:sandbox --authority=local`, through the Batch API. It checks hook wiring and
labels every result **sandbox, not production evidence**. Its ephemeral signatures prove only
that the local demo path works. The status report also identifies inactive hooks.

The sandbox rolls back its database changes. It prepares Drupal tables first because MySQL schema
changes commit transactions. Other modules' hooks can still perform external work that rollback
cannot undo; use a staging copy if the site's hooks do so. A schema change during a run triggers
cleanup and exit code 2. Exit 0 means the tested boundary holds; exit 1 means exposure.

[AgentSafe](https://github.com/decionis/agent-safe-pipeline) can hold a supported Fincra gateway's
payment key under a separate service identity. The admin **AgentSafe dry-run preview** at
`/admin/commerce/decionis/gateway-preview` shows the plan and file contents with secrets redacted.
Installation and key movement remain Drush/host operations:

```bash
vendor/bin/drush decionis:gateway-setup --dry-run
```

The setup verifies service readiness before changing custody. Root uses the packaged dynamic-user
unit, readable non-secret YAML and a systemd credential. A separate-user setup prints the host's
steps; rerun with `--installed` after they are complete. User services must have proven linger
before the key moves. A service under Drupal's own account remains an amber, weaker boundary.
The plan refuses an ambiguous gateway or two populated test/live keys.

Options remain `--gateway=<id>`, `--approver=<id>`, `--installed`, `--agentsafe-version` and
`--agentsafe-release`. Without an approver, requests needing escalation are not forwarded. The
loopback service is local to its host; multi-webhead deployments need a host design before moving
keys. Readiness alone does not prove provider reachability or policy health.

Cron checks `/_agentsafe/readyz`. An outage after key movement raises a critical status error and
mails administrators through Drupal. It keeps the protected custody until an explicit rollback:

```bash
vendor/bin/drush decionis:gateway-rollback
```

Where Drupal can read the key, rollback restores access through a private Key file provider and
keeps raw secrets out of gateway configuration. Root/separate-user custody prints the precise host
steps. A test key is never silently reused for a live-mode gateway. After a same-site reconnect, the old remote authorization is disabled, but host-bound watchdog checks and explicit rollback remain available. Legacy custody without a stored site binding requires host-assisted recovery; an upgrade cannot infer a safe key path or earlier host identity. Review the dry-run plan before
using setup or rollback on a merchant host.

The package carries three Commerce Fincra compatibility patches pending upstream release. Apply
them in the consuming project before gateway setup:

```bash
composer config allow-plugins.cweagans/composer-patches true
composer require cweagans/composer-patches:^1.7
mkdir -p patches/commerce_fincra
cp web/modules/contrib/decionis/modules/decionis_commerce/gateway/patches/*.patch patches/commerce_fincra/
composer config --json extra.patches.drupal/commerce_fincra '{"API base URL":"patches/commerce_fincra/ApiBaseUrl.patch","Held secret key":"patches/commerce_fincra/HeldSecretKey.patch","Failed status":"patches/commerce_fincra/FailedStatus.patch"}'
composer update --lock
```

Keep `patches/` in the repository. [Commerce Fincra](https://www.drupal.org/project/commerce_fincra)
currently requires Drupal 10. Its supported refunds cover underpayments and overpayments as
described above; ordinary customer refund requests require a separate payout outside Drupal.
AgentSafe cannot expand that refund API scope or make a payout a Drupal refund record.

## Drush and troubleshooting

| Command                                               | Purpose                                                         |
| ----------------------------------------------------- | --------------------------------------------------------------- |
| `vendor/bin/drush decionis:sandbox --authority=local` | Offline synthetic-store check, rolled back; add `--json` for CI |
| `vendor/bin/drush decionis:replay --days=90`          | Remote Shadow replay of existing orders, refunds and promotions |
| `vendor/bin/drush decionis:replay --authority=local`  | Local estimate; creates no dashboard decision rows              |
| `vendor/bin/drush decionis:cost-import FILE.csv`      | Import variation costs from `sku,cost`                          |
| `vendor/bin/drush decionis:gateway-setup --dry-run`   | Print the host plan and redacted files                          |
| `vendor/bin/drush decionis:gateway-rollback`          | Restore supported local key access or print host recovery steps |

The sandbox also supports `--authority=hosted` with `DECIONIS_SANDBOX_API_KEY` and
`DECIONIS_SANDBOX_ORG_ID` (or lower-case Key entries), and `--authority=account` for the site's
workspace in Shadow. `--policy=<version>` pins a published policy for hosted/account runs.

- **Pending Shadow or replay rows:** run `vendor/bin/drush cron` or the protected cron URL and inspect the site queue.
  The status report warns when queued Shadow checks are more than one hour old; reading the report
  does no queue or network work. An unavailable API cannot produce a signed verdict. Local X-ray still works.
- **Copy banner:** reconnect the new site or disconnect its local copy. Do not reuse production
  approvers. URL/proxy configuration changes can also require rebinding.
- **Challenge not readable:** allow the two challenge routes. Public URLs must be HTTPS on the
  default port; configure Drupal's trusted reverse proxy settings when needed.
- **Enforce unavailable:** the server has not granted a current enforcement entitlement. The plan
  notice explains it; changing local config cannot override it.
- **Inactive hooks:** rebuild caches (`vendor/bin/drush cr`) and rerun the local sandbox. Check custom overrides.
- **Synchronous DNS warning:** use libcurl with threaded/c-ares resolution or a local caching
  resolver so the Enforce budget covers DNS appropriately.
- **Proof unavailable or invalid:** use the inline result and download. The
  [Dossier verifier](https://www.npmjs.com/package/@decionis/verify) also supports verification
  outside Drupal; keep trusted key material with an offline review.

Before uninstalling, export decision evidence and decide how to retain or remove decision and
approval records; Drupal refuses removal while those entities remain. An existing gateway that
depends on Commerce Gate's custody or rollback key injection must be removed after a separate replacement gateway has been configured and verified with independent key access. A rollback alone still uses the module's private
key binding. Preserve host recovery files until the replacement gateway has been checked.

Support: [decionis.com/contact](https://decionis.com/contact). Licence: GPL-2.0-or-later; the
`decionis/php-client` dependency is Apache-2.0.
