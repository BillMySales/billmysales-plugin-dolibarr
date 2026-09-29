# Changelog

All notable changes to this module. Versions follow [Semantic Versioning](https://semver.org).

## [2.0.1] - 2026-09-29

- Dolibarr 19 is supported and tested: the settings form reads the secret
  with the `none` check type, which every Dolibarr version knows (19 has no
  `password` type: it logged an error on each save).
- The scheduled job runs while the module is enabled, checked with
  `isModEnabled()` instead of Dolibarr's `$conf->billmysales->enabled`,
  which Dolibarr plans to remove.
- Tested end to end: Dolibarr 19.0.4 with PHP 7.4 and 8.2, 20.0.4 with 8.2,
  21.0.4 with 8.3, 22.0.5 with 8.4, 23.0.4 with 8.4 and 24.0.1 with 8.5.

## [2.0.0] - 2026-09-28

Rewritten:

- Deliveries are queued when an invoice is validated or paid (or the
  merchant asks to send it again), and sent from the module's own cron job
  (registered in Dolibarr's native scheduler, active by default): validating
  or paying an invoice never waits for BillMySales. Failed deliveries are
  retried on network errors, timeouts, rate limits and server errors (after
  1 min, 5 min, 30 min, 2 h and 12 h); other HTTP errors are logged, not
  retried.
- Standard `X-BillMySales-*` headers, plus the legacy
  `X-DolibarrBMS-Hmac-Sha256` signature header the 1.x module sent.
- The invoice detail page shows the last known delivery status, and a "Send
  to BillMySales" button to send it again.
- English source strings, with Spanish translation catalogs (`es_CL`,
  `es_ES`).
- Settings stored in new `BILLMYSALES_*` Configuration values (reconfigure
  after the update).
- The invoice and customer data sent to BillMySales no longer includes
  Dolibarr's internal database handle (exposed the database host, user,
  port and the object's last raw SQL query, as a property every Dolibarr
  object carries).

## [1.0.0] - 2022-01-07

- Webhook to a configurable URL when an invoice is paid, signed with
  HMAC-SHA256, sent synchronously from the trigger.
