<?php

declare(strict_types=1);

/**
 * BillMySales for Dolibarr.
 *
 * Copyright (c) 2026 BillMySales <https://www.billmysales.com>
 * Licensed under the GNU Affero General Public License v3.0 or later.
 * See LICENSE file for more details.
 */

/**
 * End-to-end tests: a small CLI, bootstrapping Dolibarr itself (like its
 * own scripts/ directory, not the web front end), for the setup steps
 * Dolibarr has no dedicated CLI for (a thirdparty and an invoice with a
 * line, granting a user every permission, enabling a Dolibarr module).
 * Validating or paying the invoice, saving the settings and resending a
 * delivery go through plain HTTP instead, exactly as a user would (run.sh).
 * Run inside the stack's "dolibarr" or "cron" container. Prints one
 * plain-text result line per command and exits non-zero on failure.
 *
 * Usage: php e2e-fixtures.php <command> [args...]
 */

if (!defined('NOSESSION')) {
    define('NOSESSION', '1');
}
require '/var/www/dolibarr/htdocs/master.inc.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/functions.lib.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';

/**
 * Creates a thirdparty and a draft invoice with one line.
 *
 * @param string $name  Thirdparty name.
 * @param string $price Line price, tax excluded.
 * @return void
 */
function billmysales_e2e_create_invoice(string $name, string $price): void
{
    global $db, $user;

    $societe = new Societe($db);
    $societe->name = $name;
    $societe->client = 1;
    $societe->country_id = 1;
    $id_societe = $societe->create($user);
    if ($id_societe <= 0) {
        fwrite(STDERR, 'thirdparty error: ' . implode(', ', $societe->errors) . "\n");
        exit(1);
    }

    $facture = new Facture($db);
    $facture->socid = $id_societe;
    $facture->type = Facture::TYPE_STANDARD;
    $facture->date = dol_now();
    $id_facture = $facture->create($user);
    if ($id_facture <= 0) {
        fwrite(STDERR, 'invoice error: ' . implode(', ', $facture->errors) . "\n");
        exit(1);
    }

    $result = $facture->addline('E2E line', (float) $price, 1, 19);
    if ($result <= 0) {
        fwrite(STDERR, 'invoice line error: ' . implode(', ', $facture->errors) . "\n");
        exit(1);
    }

    echo $id_facture . "\n";
}

/**
 * Prints one field of an invoice (as Dolibarr's Facture object holds it,
 * e.g. fk_statut, paye, total_ttc).
 *
 * @param string $id_facture Invoice id.
 * @param string $field      Property name.
 * @return void
 */
function billmysales_e2e_get_invoice_field(string $id_facture, string $field): void
{
    global $db;

    $facture = new Facture($db);
    if ($facture->fetch((int) $id_facture) <= 0) {
        fwrite(STDERR, "invoice {$id_facture} not found\n");
        exit(1);
    }
    echo $facture->{$field} . "\n";
}

/**
 * Sets a Configuration value (the module's own settings are plain
 * Configuration values; see Settings).
 *
 * @param string $key   Configuration key.
 * @param string $value Raw value.
 * @return void
 */
function billmysales_e2e_set_config(string $key, string $value): void
{
    global $db, $conf;

    dolibarr_set_const($db, $key, $value, 'chaine', 0, '', $conf->entity);
    echo "ok\n";
}

/**
 * Prints a Configuration value.
 *
 * @param string $key Configuration key.
 * @return void
 */
function billmysales_e2e_get_config(string $key): void
{
    global $conf;

    echo (string) ($conf->global->{$key} ?? '') . "\n";
}

/**
 * Enables a Dolibarr module (e.g. "modFacture", "modBillmysales"), the same
 * way Home > Setup > Modules does. Safe to repeat.
 *
 * @param string $name The module's class name (its mod<Name>.class.php file).
 * @return void
 */
function billmysales_e2e_enable_module(string $name): void
{
    $result = activateModule($name);
    if (!empty($result['errors'])) {
        fwrite(STDERR, 'enable-module error: ' . implode(', ', $result['errors']) . "\n");
        exit(1);
    }
    echo "ok\n";
}

/**
 * Grants a user every permission of every enabled module (Home > Users >
 * [user] > Permissions > "All"), so a fresh development admin can validate
 * and pay invoices. Safe to repeat.
 *
 * @param string $login User login.
 * @return void
 */
function billmysales_e2e_grant_all_rights(string $login): void
{
    global $db, $conf;

    $target = new User($db);
    if ($target->fetch(0, $login) <= 0) {
        fwrite(STDERR, "user {$login} not found\n");
        exit(1);
    }
    $result = $target->addrights(0, 'allmodules', '', $conf->entity);
    if ($result < 0) {
        fwrite(STDERR, 'grant-all-rights error: ' . $target->error . "\n");
        exit(1);
    }
    echo "ok\n";
}

/**
 * Prints the rowid of the module's scheduled job (Cronjob), so run.sh can
 * force it to run regardless of Dolibarr's own "next run" schedule.
 *
 * @return void
 */
function billmysales_e2e_cron_job_id(): void
{
    global $db;

    $result = $db->query('SELECT rowid FROM ' . MAIN_DB_PREFIX . 'cronjob WHERE label = "BillMySalesProcessDueDeliveries"');
    $row = $result ? $db->fetch_array($result) : null;
    if (!$row) {
        fwrite(STDERR, "cron job not found\n");
        exit(1);
    }
    echo $row['rowid'] . "\n";
}

/**
 * Prints how many of the module's own tables still exist (0 once
 * uninstalled).
 *
 * @return void
 */
function billmysales_e2e_count_tables(): void
{
    global $db;

    $count = 0;
    foreach (['billmysales_delivery_queue', 'billmysales_order_status'] as $table) {
        $result = $db->query('SHOW TABLES LIKE "' . MAIN_DB_PREFIX . $table . '"');
        if ($result && $db->fetch_array($result)) {
            ++$count;
        }
    }
    echo $count . "\n";
}

/**
 * Prints how many BILLMYSALES_* Configuration rows still exist (0 once
 * uninstalled).
 *
 * @return void
 */
function billmysales_e2e_count_config(): void
{
    global $db;

    $result = $db->query('SELECT COUNT(*) AS c FROM ' . MAIN_DB_PREFIX . 'const WHERE name LIKE "BILLMYSALES%"');
    $row = $result ? $db->fetch_array($result) : null;
    echo (int) ($row['c'] ?? 0) . "\n";
}

/**
 * Makes a queued delivery due right now, instead of waiting for its retry
 * delay to elapse.
 *
 * @param string $id_facture Invoice id.
 * @return void
 */
function billmysales_e2e_force_retry_now(string $id_facture): void
{
    global $db;

    // PHP's own now (not SQL's NOW()): the queue compares next_attempt_at
    // against a PHP-generated timestamp, and the database server's
    // timezone isn't necessarily the same as PHP's.
    $db->query(sprintf(
        'UPDATE %sbillmysales_delivery_queue SET next_attempt_at = "%s" WHERE id_facture = %d',
        MAIN_DB_PREFIX,
        $db->escape(date('Y-m-d H:i:s')),
        (int) $id_facture
    ));
    echo "ok\n";
}

$argv = $_SERVER['argv'] ?? [];
$command = $argv[1] ?? '';
$args = array_slice($argv, 2);

switch ($command) {
    case 'create-invoice':
        billmysales_e2e_create_invoice($args[0] ?? 'E2E thirdparty', $args[1] ?? '9990');
        break;
    case 'get-invoice-field':
        billmysales_e2e_get_invoice_field($args[0] ?? '0', $args[1] ?? 'fk_statut');
        break;
    case 'set-config':
        billmysales_e2e_set_config($args[0] ?? '', $args[1] ?? '');
        break;
    case 'get-config':
        billmysales_e2e_get_config($args[0] ?? '');
        break;
    case 'enable-module':
        billmysales_e2e_enable_module($args[0] ?? '');
        break;
    case 'grant-all-rights':
        billmysales_e2e_grant_all_rights($args[0] ?? '');
        break;
    case 'cron-job-id':
        billmysales_e2e_cron_job_id();
        break;
    case 'count-tables':
        billmysales_e2e_count_tables();
        break;
    case 'count-config':
        billmysales_e2e_count_config();
        break;
    case 'force-retry-now':
        billmysales_e2e_force_retry_now($args[0] ?? '0');
        break;
    default:
        fwrite(STDERR, "unknown command: {$command}\n");
        exit(1);
}
