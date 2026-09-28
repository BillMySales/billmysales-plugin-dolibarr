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
 * Shared functions: wiring plugin/src's classes to Dolibarr's own runtime
 * ($db, Configuration, curl), used by the trigger, the hooks, the cron job
 * and the admin pages.
 *
 * @package BillMySales\Dolibarr
 */

use BillMySales\Dolibarr\Admin\InvoiceBlock;
use BillMySales\Dolibarr\Admin\SettingsPage;
use BillMySales\Dolibarr\Settings;
use BillMySales\Dolibarr\Webhook\Delivery;
use BillMySales\Dolibarr\Webhook\OrderStatus;
use BillMySales\Dolibarr\Webhook\Queue;

if (!class_exists(Settings::class, false)) {
    spl_autoload_register(
        static function (string $class_name): void {
            $prefix = 'BillMySales\\Dolibarr\\';
            if (0 !== strpos($class_name, $prefix)) {
                return;
            }
            $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class_name, strlen($prefix))) . '.php';
            if (is_readable($path)) {
                require $path;
            }
        }
    );
}

/**
 * Prepares the admin pages' tab header.
 *
 * @return array<int, array{0: string, 1: string, 2: string}>
 */
function billmysalesAdminPrepareHead()
{
    global $langs, $conf;

    $langs->load('billmysales@billmysales');

    $head = [];
    $head[0] = [dol_buildpath('/billmysales/admin/setup.php', 1), $langs->trans('Settings'), 'settings'];
    $head[1] = [dol_buildpath('/billmysales/admin/about.php', 1), $langs->trans('About'), 'about'];

    $h = count($head);
    complete_head_from_modules($conf, $langs, null, $head, $h, 'billmysales@billmysales');

    return $head;
}

/**
 * Reads the current settings from Configuration.
 *
 * @return array{url: string, secret: string, events: string[], active: bool}
 */
function billmysalesSettings()
{
    global $conf;
    return Settings::from_raw(
        $conf->global->{Settings::KEY_URL},
        $conf->global->{Settings::KEY_SECRET},
        $conf->global->{Settings::KEY_EVENTS},
        $conf->global->{Settings::KEY_ACTIVE}
    );
}

/**
 * Saves the settings form (setup.php's own "update" action).
 *
 * @return void
 */
function billmysalesSaveSettings()
{
    global $db, $conf;

    $input = [
        Settings::KEY_ACTIVE => GETPOST(Settings::KEY_ACTIVE, 'alpha'),
        Settings::KEY_URL => GETPOST(Settings::KEY_URL, 'alpha'),
        // 'password': the secret must survive as typed (it signs every
        // delivery byte for byte), and 'alpha' rewrites "\x" to "/x" and
        // drops quotes; it is never echoed unescaped (dol_escape_htmltag(),
        // masked with asterisks) so this is safe.
        Settings::KEY_SECRET => GETPOST(Settings::KEY_SECRET, 'password'),
    ];
    foreach (Settings::EVENTS as $action) {
        $input[Settings::KEY_EVENTS . '_' . $action] = GETPOST(Settings::KEY_EVENTS . '_' . $action, 'alpha');
    }
    $settings = Settings::sanitize($input);

    dolibarr_set_const($db, Settings::KEY_ACTIVE, $settings['active'] ? '1' : '0', 'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, Settings::KEY_URL, $settings['url'], 'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, Settings::KEY_SECRET, $settings['secret'], 'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, Settings::KEY_EVENTS, json_encode($settings['events']), 'chaine', 0, '', $conf->entity);
}

/**
 * The pending-deliveries queue, wired to Dolibarr's database.
 *
 * @return Queue
 */
function billmysalesQueue()
{
    global $db;
    return new Queue(
        static function (string $sql) use ($db): array {
            $rows = [];
            $result = $db->query($sql);
            if ($result) {
                while ($row = $db->fetch_array($result)) {
                    $rows[] = $row;
                }
            }
            return $rows;
        },
        static function (string $sql) use ($db): void {
            $db->query($sql);
        },
        MAIN_DB_PREFIX
    );
}

/**
 * The last known delivery status per invoice, wired to Dolibarr's database.
 *
 * @return OrderStatus
 */
function billmysalesOrderStatus()
{
    global $db;
    return new OrderStatus(
        static function (string $sql) use ($db): array {
            $rows = [];
            $result = $db->query($sql);
            if ($result) {
                while ($row = $db->fetch_array($result)) {
                    $rows[] = $row;
                }
            }
            return $rows;
        },
        static function (string $sql) use ($db): void {
            $db->query($sql);
        },
        MAIN_DB_PREFIX
    );
}

/**
 * The delivery manager, wired to Dolibarr's database, clock and a random
 * UUID generator.
 *
 * @return Delivery
 */
function billmysalesDelivery()
{
    return new Delivery(
        billmysalesQueue(),
        static fn (): string => date('Y-m-d H:i:s'),
        'billmysalesUuid'
    );
}

/**
 * A random UUID (v4).
 *
 * @return string
 */
function billmysalesUuid()
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * The context sent with each delivery: the instance's URL and versions.
 *
 * @return array{source: string, platform_version: string, plugin_version: string}
 */
function billmysalesContext()
{
    global $conf, $db;

    // Not necessarily loaded yet: main.inc.php includes every enabled
    // module's descriptor while building the menu, but a CLI bootstrap
    // (the scheduled job, this module's own e2e fixtures) skips that.
    require_once __DIR__ . '/../core/modules/modBillmysales.class.php';
    $module = new modBillmysales($db);
    $source = !empty($conf->global->MAIN_URL_ROOT) ? $conf->global->MAIN_URL_ROOT : DOL_MAIN_URL_ROOT;

    return [
        'source' => rtrim((string) $source, '/'),
        'platform_version' => DOL_VERSION,
        'plugin_version' => $module->version,
    ];
}

/**
 * Plain invoice data, as the module has sent it since its first version, or
 * null if the invoice no longer exists.
 *
 * @param int $id_facture Invoice id.
 * @return array<string, mixed>|null
 */
function billmysalesFactureData($id_facture)
{
    global $db;

    require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
    $facture = new Facture($db);
    if ($facture->fetch($id_facture) <= 0) {
        return null;
    }
    return get_object_vars($facture);
}

/**
 * Plain customer (thirdparty) data of an invoice's customer.
 *
 * @param int $id_facture Invoice id.
 * @return array<string, mixed>
 */
function billmysalesSocieteData($id_facture)
{
    global $db;

    require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
    require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
    $facture = new Facture($db);
    $facture->fetch($id_facture);
    $societe = new Societe($db);
    $societe->fetch($facture->socid);
    return get_object_vars($societe);
}

/**
 * Posts the payload to BillMySales.
 *
 * @param string                 $url     Notification URL.
 * @param string                 $body    Request body.
 * @param array<string, string>  $headers Request headers.
 * @return array{int, string} The HTTP status (or -1 on a network error) and the response body/error.
 */
function billmysalesHttpPost($url, $body, $headers)
{
    $header_lines = [];
    foreach ($headers as $name => $value) {
        $header_lines[] = $name . ': ' . $value;
    }

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $header_lines,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($curl);
    if (false === $response) {
        $error = curl_error($curl);
        curl_close($curl);
        return [-1, $error];
    }
    $code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    return [$code, (string) $response];
}

/**
 * Records the outcome of a delivery attempt: the shop's own log, and the
 * invoice's last known status.
 *
 * @param int    $id_facture  Invoice id.
 * @param string $event       Event of the delivery.
 * @param string $delivery_id UUID of the delivery.
 * @param string $result      Result ("delivered", "retry", "rejected", "given_up" or "gone").
 * @param string $detail      Detail (e.g. the HTTP status).
 * @param int    $attempts    Attempts made so far.
 * @return void
 */
function billmysalesRecordResult($id_facture, $event, $delivery_id, $result, $detail, $attempts)
{
    $level = Delivery::RESULT_DELIVERED === $result ? LOG_INFO : LOG_WARNING;
    dol_syslog(sprintf('BillMySales: invoice #%d %s (%s, %s): %s', $id_facture, $result, $event, $delivery_id, $detail), $level);
    billmysalesOrderStatus()->record($id_facture, $result, $detail, $event, $delivery_id, $attempts, date('Y-m-d H:i:s'));
}

/**
 * Sends every delivery due by now.
 *
 * @param int $limit Maximum deliveries sent in this run.
 * @return void
 */
function billmysalesProcessDueDeliveries($limit = 20)
{
    billmysalesDelivery()->process_due(
        billmysalesSettings(),
        billmysalesContext(),
        'billmysalesFactureData',
        'billmysalesSocieteData',
        'billmysalesHttpPost',
        'billmysalesRecordResult',
        $limit
    );
}

/**
 * The invoice detail page's "BillMySales" block.
 *
 * @param int $id_facture Invoice id.
 * @return array{label: string|null, detail: string, updated_at: string, can_resend: bool}
 */
function billmysalesInvoiceBlockView($id_facture)
{
    global $langs;

    $langs->load('billmysales@billmysales');
    $settings = billmysalesSettings();
    return InvoiceBlock::view(
        [$langs, 'trans'],
        billmysalesOrderStatus()->get($id_facture),
        Settings::is_ready($settings)
    );
}

/**
 * The settings form's field definitions.
 *
 * @return array<int, array{key: string, label: string, tooltip: string, type: string, value: mixed}>
 */
function billmysalesSettingsFields()
{
    global $langs;

    $langs->load('billmysales@billmysales');
    return SettingsPage::fields([$langs, 'trans'], billmysalesSettings());
}
