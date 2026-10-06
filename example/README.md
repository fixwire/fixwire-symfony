# Shop (Symfony)

A small Symfony API with Fixwire, installed the way an app would install it:
`fixwire/symfony` in `composer.json`, the bundle in `config/bundles.php`,
the DSN in the environment and a few lines in
`config/packages/fixwire.yaml`.

```sh
composer install
FIXWIRE_DSN=https://<key>@<host> php -d variables_order=EGPCS -S localhost:8080 public/index.php
php bin/console messenger:consume async     # the worker, in another terminal
```

(`variables_order=EGPCS` lets the built-in server pass the environment to
the app, so it wins over `.env`. PHP-FPM passes it anyway.)

It reserves stock at an inventory service (`INVENTORY_URL`, default
`http://localhost:8081`) and sends invoices through Messenger (a Doctrine
transport on sqlite). Users sign in with HTTP basic: `ada` and `grace`, both
with the password `secret` (curl asks for it). Then:

```sh
curl localhost:8080/orders/7                                          # 200, with a query span
curl -u ada -H 'Content-Type: application/json' \
  -d '{"sku":"sku_1","card":"4242424242424242"}' localhost:8080/orders   # 201: an invoice is sent
curl -u grace -H 'Content-Type: application/json' \
  -d '{"sku":"sku_2","card":"4000000000000002"}' localhost:8080/orders   # 402: a declined payment
curl localhost:8080/admin/report                                      # a bug: 500
php bin/console app:send-reports                                      # the nightly reports (cron)
```

What arrives in Fixwire:

- **The declined payment** as an error of `POST /orders`, captured by the
  controller: the chain (`charging order …` caused by `card_declined`), the
  user `grace` from the firewall, the order as context, and the call to the
  inventory service as a breadcrumb. A request without a user (401) is not
  reported.
- **The bug** in `GET /admin/report` (`DivisionByZeroError`) as a crash.
- **A trace per request**, named after its route, with the SQL query and the
  call to the inventory service (which gets the trace headers).
- **The invoice failing in the worker** ("the mail server refused the
  invoice…") as a crash with the message, in the trace of the order that
  sent it.
- **Release health** for `shop@1.0.0`: each request is a session.
- **The nightly reports**: check-ins for the `nightly-report` monitor
  (`in_progress`, then `error`, as one account failed), the account's
  failure tagged `account: globex`, and the failed run as a crash of the
  command.

How it is wired:

```yaml
# config/packages/fixwire.yaml
fixwire:
    traces_sample_rate: 1.0
    trace_propagation_targets: ['%env(INVENTORY_URL)%']
    auto_session_tracking: true
```

```php
// src/Command/SendReportsCommand.php
return Fixwire\withMonitor('nightly-report', MonitorConfig::crontab('0 3 * * *', checkInMargin: 10, timezone: 'Europe/Berlin'),
    fn () => $this->sendReports($output));
```
