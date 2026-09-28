# Changelog

All notable changes to this module. Versions follow [Semantic Versioning](https://semver.org).

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
