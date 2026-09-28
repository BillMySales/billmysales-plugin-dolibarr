BillMySales for Dolibarr
=========================

Dolibarr module that sends invoices to [BillMySales](https://www.billmysales.com)
when they reach the event you choose (validated, paid; BillMySales then
issues the billing document).

- Deliveries are queued when an invoice reaches a selected event (or the
  merchant asks to send it again), never sent from that same request: the
  invoice page never waits for BillMySales. They are sent by the module's
  own scheduled job (Home > Setup > Modules > Scheduled jobs, active by
  default). Failed deliveries are retried on network errors, timeouts,
  rate limits and server errors (after 1 min, 5 min, 30 min, 2 h and 12 h);
  other HTTP errors are logged, not retried. BillMySales is idempotent, so
  a repeated delivery is harmless.
- Each notification is signed with HMAC-SHA256 with the secret shared with
  BillMySales.
- The invoice detail page shows the last known delivery status, and a
  "Enviar a BillMySales" button to send it again.

Requirements: Dolibarr 19.0+ (tested up to 24.0.1), PHP 7.4+ (tested up to
8.5).

Installation
------------

1. Download `module_billmysales-<version>.zip` from the
   [releases](https://github.com/BillMySales/billmysales-plugin-dolibarr/releases)
   (not the repository's own zip).
2. Home > Setup > Modules > Deploy/install external module: upload it (or
   extract it into `htdocs/custom/billmysales` yourself).
3. Home > Setup > Modules: enable "BillMySales".
4. Home > Setup > Modules > BillMySales > Settings: the notification URL
   and the secret given by BillMySales, and the events that notify.
5. The scheduled job "BillMySalesProcessDueDeliveries" (Home > Setup >
   Modules > Scheduled jobs) must be active and a system cron must run
   Dolibarr's own job runner (`scripts/cron/cron_run_jobs.php`) for
   deliveries to go out without depending on an employee browsing the back
   office.

Notification
------------

A `POST` to the configured URL, with the invoice and its customer:

```json
{
  "facture": { "id": 1, "ref": "IN2601-0001", "socid": 1, "total_ttc": "11900.00", "statut": 1, "paye": 0, "lines": [ ] },
  "societe": { "id": 1, "name": "Acme SA" }
}
```

`facture` and `societe` are Dolibarr's own `Facture` and `Societe` objects,
as plain data (every public property, as Dolibarr itself holds them: most
numeric fields arrive as strings). Neither class declares a password, token
or API key field; the one property the module does strip, recursively
(including from nested objects, e.g. an invoice's lines), is `db`: every
Dolibarr object carries it as its database handle, which exposes the
database host, user, port and the object's last raw SQL query.

Headers:

| Header | Value |
|---|---|
| `X-BillMySales-Signature` | base64 of the HMAC-SHA256 of the raw body with the secret |
| `X-BillMySales-Platform` | `dolibarr` |
| `X-BillMySales-Platform-Version` | Dolibarr version |
| `X-BillMySales-Plugin-Version` | module version |
| `X-BillMySales-Source` | instance URL |
| `X-BillMySales-Event` | `invoice.validated`, `invoice.paid` or `invoice.resent` ("Enviar a BillMySales") |
| `X-BillMySales-Delivery` | UUID of the notification (the same on retries) |
| `User-Agent` | `BillMySales-dolibarr/<version>` |
| `X-DolibarrBMS-Hmac-Sha256` | the same signature, as the module's 1.x version sent it |

Verifying the signature (Python):

```python
expected = base64.b64encode(hmac.new(secret, body, hashlib.sha256).digest()).decode()
hmac.compare_digest(expected, headers["X-BillMySales-Signature"])
```

Development
-----------

Layout: `plugin/` is the module (what the zip installs, as the
`billmysales` folder); the repository root has the development tools.

```
plugin/                 core/modules/modBillmysales.class.php (the module descriptor)
  src/                  classes (BillMySales\Dolibarr\...), unit-tested
  core/triggers/        the trigger: queues a delivery on a selected event
  class/                the invoice card's hooks, and the scheduled job
  lib/                  wires plugin/src's classes to Dolibarr's own runtime
  admin/                settings and about pages
  assets/js/            settings page's show/hide secret button
  langs/                en_US, es_CL, es_ES catalogs
  sql/                  the module's two tables (Dolibarr's own convention)
tests/src/              PHPUnit unit tests
docker/Dockerfile       tools image (PHP CLI, Composer, Xdebug)
```

Every task runs in Docker containers (the host's PHP and Node.js aren't
used): PHP 7.4, the lowest the plugin supports (so PHPUnit 9.6;
`PHP_VERSION=8.5` runs them on another one), and ESLint in `node:24-alpine`:

```shell
make install        # composer install (dev tools) and npm ci
make lint           # PHP CS Fixer (PSR-12), dry run; required docblocks (phpcs.xml); ESLint (rules, JSDoc, style)
make fix            # PHP CS Fixer's and ESLint's fixes (style)
make analyse        # PHPStan (community Dolibarr stubs, PHP 7.4)
make test           # PHPUnit, with coverage (var/tests-coverage.txt): 100% of plugin/src
make check          # all of the above + version consistency
make build           # dist/module_billmysales-<version>.zip
make clean
```

The version lives in the module's own metadata (`$this->version` in
`plugin/core/modules/modBillmysales.class.php`); `CHANGELOG.md` must match
it (`make check`).

### End-to-end tests

```shell
make e2e            # E2E_KEEP=1 keeps the stack running (then make e2e-clean)
```

`tests/e2e/run.sh` clones the
[Dolibarr Docker stack](https://github.com/BillMySales/billmysales-docker-dolibarr)
into `var/e2e/stack` (`STACK_REPO`, `STACK_REF`, default `master`), starts it
with the module mounted and a webhook receiver on port 8099
(`tests/e2e/receiver.php`, standing in for BillMySales: it stores each
request as received and answers the status a case asks for), runs the cases
and removes the stack and the receiver. It needs only Docker. **The stack's
development ports (8106, 8406, 8025) and 8099 must be free: stop the
Dolibarr development stack first** (the script checks them before
starting).

Each case does something in Dolibarr and `tests/e2e/check.php` checks what
reached the receiver: the number of requests, the signature (recomputed
from the raw body with the secret, in `X-BillMySales-Signature` and
`X-DolibarrBMS-Hmac-Sha256`), the headers (platform, versions, event,
source, delivery UUID, User-Agent, the secret in none), the payload against
the invoice's JSON Schema (`tests/e2e/order-schema.json`), and the
invoice's id, status and paid flag.

The deliveries stay in `var/e2e/webhooks` until the next run or `make
clean`: `<case>-<time>.body` is the raw body, `.json` has the headers, the
decoded payload and the status the receiver answered.

### Releases

Bump the version (`$this->version` in
`plugin/core/modules/modBillmysales.class.php`), add its `CHANGELOG.md`
entry, commit and push a `vX.Y.Z` tag. The release workflow
(`.github/workflows/release.yml`) calls the tests (`ci.yml`, PHP 7.4 and
8.5) and then the end-to-end tests (`e2e.yml`), and only when both passed
checks the tag matches the version, runs `make build` and publishes the
GitHub Release with the zip. `ci.yml` and `e2e.yml` also run on every push
and pull request (the end-to-end deliveries are uploaded as the
`e2e-results` artifact). Dependabot (`.github/dependabot.yml`) opens weekly
pull requests for the Composer and npm tools and the workflows' actions.

Platform decisions
-------------------

Recorded here (not compared against anything else) so the reasoning behind
each choice stays with the code:

- **License**: the GNU Affero General Public License v3.0 or later
  (AGPL-3.0-or-later). Dolistore (Dolibarr's official module marketplace)
  requires an OSI-approved license that is GPL v3+ compatible; AGPL-3.0 is
  explicitly documented (by the Free Software Foundation, in both the GPLv3
  and AGPLv3 texts themselves) as convertible with GPLv3, making it the
  strongest copyleft option that satisfies the requirement.
- **Minimum version**: Dolibarr 19.0, its long-term support line, tested up
  to 24.0.1 (the version this project's own Dolibarr stack runs). PHP
  7.4+ (tested up to 8.5): Dolibarr 19 officially supports down to PHP 7.4,
  chosen so one toolchain (PHP CS Fixer, PHPStan, PHPUnit) runs unmodified
  from the floor to the latest PHP tested.
- **No official PHPStan stubs package**: no official stub package for
  Dolibarr core classes exists; `caprel/dolibarr-stubs-all` (community) is
  used instead, installed as `dev-master` (it has no tagged release). It
  only ships stubs up to Dolibarr 23 (the module targets 24): the closest
  version available, reviewed by hand where its stubs and the actual
  Dolibarr 24 source disagree.
- **No automated security ruleset**: Dolibarr and Dolistore have no public
  PHP_CodeSniffer ruleset for the marketplace's own review checklist;
  followed by convention and checked in code review.
- **Delivery queue and Dolibarr's own scheduled jobs**: the module keeps its
  own small queue table (`billmysales_delivery_queue`, one row per pending
  delivery, removed once it's no longer pending — not a growing log),
  drained by a job the module registers in Dolibarr's own scheduler (Home >
  Setup > Modules > Scheduled jobs), active by default: a real system cron
  running Dolibarr's own job runner is the only piece a self-hosted
  instance has to provide. An invoice reaching a selected event, or a
  manual resend, only ever queues a delivery, never sends it in the same
  request.
- **Per-invoice delivery status**: `billmysales_order_status` keeps one row
  per invoice that has ever had a delivery attempt (replaced on each new
  attempt, bounded by the shop's invoice count, not by attempts), shown on
  the invoice detail page; the technical log (every attempt, with its
  detail) goes to Dolibarr's own syslog.
- **Payload format**: kept the exact `{facture, societe}` shape (Dolibarr's
  own `Facture` and `Societe` objects, as plain data) the module's first
  version already sent, since changing it without being able to check it
  against BillMySales' own parser for this platform risks breaking
  existing integrations silently. Every Dolibarr object carries a public
  `db` property (its database handle, exposing the database host, user,
  port and the object's last raw SQL query); the module strips it
  recursively, including from nested objects such as an invoice's lines,
  before building the payload.
- **Events offered**: only `BILL_VALIDATE` (the invoice's amount and lines
  are final) and `BILL_PAYED` (fully paid) are offered in the settings.
  Dolibarr fires many other invoice trigger actions (`BILL_CREATE`: still a
  draft; `BILL_MODIFY`: fires too often to mean "ready to bill";
  `BILL_CANCEL`, `BILL_UNVALIDATE`, `BILL_UNPAYED`, `BILL_DELETE`: undo a
  previous state, not a new one to bill), which the module's trigger
  ignores.
- **The secret shown as a password field with a show button**: the
  settings form's secret field is a plain HTML password input; a small
  script (`plugin/assets/js/admin.js`) adds the show/hide button (no bundler:
  loaded as is by the back office, checked by ESLint). Saving it reads the
  field with Dolibarr's `password` GETPOST type, not `alpha`: `alpha`
  rewrites `\x` sequences to `/x` and drops quotes, which would corrupt an
  arbitrary secret.
- **No dedicated permission**: the module declares no permission of its own
  (`$this->rights` is empty). The invoice detail page's BillMySales block
  and its "Enviar a BillMySales" button follow the invoice's own read/write
  permission; there is nothing else in the module an ordinary user would
  need a separate permission for.
- **Settings keys changed**: no migration from the module's first version
  is offered (no real customer install of this rewrite exists yet); a
  reinstall reconfigures the settings.
- **End-to-end fixtures without a CLI**: Dolibarr has no official
  WP-CLI-alike CLI. `tests/e2e/fixtures.php`, copied into the stack's
  `dolibarr` container and run with plain `php` (Dolibarr's own
  `master.inc.php` bootstrap, the same one its `scripts/` directory uses),
  covers the setup Dolibarr itself has no dedicated CLI for (a thirdparty
  and a draft invoice, granting the development admin every permission,
  enabling a Dolibarr module: none of this is what the end-to-end cases
  test). Validating or paying the invoice, saving the settings, resending a
  delivery and installing the built zip go through plain HTTP with curl,
  cookies and the page's own CSRF tokens instead, exactly as a browser
  would submit them. The scheduled job is run through Dolibarr's own CLI
  (`scripts/cron/cron_run_jobs.php`, the same one the stack's `cron`
  container runs on a timer) with `--force`: Dolibarr's own scheduler only
  lets a job run once per its configured frequency, regardless of whether
  this module's queue has anything due, so forcing it is what makes a case
  right after another one due immediately instead of waiting for real time
  to pass.
- **Granting the development admin's permissions**: Dolibarr's superadmin
  account does not automatically hold a newly enabled module's
  permissions; `tests/e2e/fixtures.php`'s `grant-all-rights` calls
  `User::addrights()` with Dolibarr's own `allmodules` shortcut (the same
  one Home > Users > \[user\] > Permissions > "All" uses), once, for the
  cases that validate or pay an invoice.

License
-------

Copyright (c) 2026 BillMySales. Licensed under the
[GNU Affero General Public License v3.0 or later](LICENSE) (AGPL-3.0-or-later).
