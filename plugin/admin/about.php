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
 * The module's "About" page: renders its long description (README.md).
 *
 * @package BillMySales\Dolibarr
 */

// Load the Dolibarr environment.
$res = 0;
if (!empty($_SERVER['CONTEXT_DOCUMENT_ROOT'])) {
    $res = @include $_SERVER['CONTEXT_DOCUMENT_ROOT'] . '/main.inc.php';
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] === $tmp2[$j]) {
    --$i;
    --$j;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, $i + 1) . '/main.inc.php')) {
    $res = @include substr($tmp, 0, $i + 1) . '/main.inc.php';
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, $i + 1)) . '/main.inc.php')) {
    $res = @include dirname(substr($tmp, 0, $i + 1)) . '/main.inc.php';
}
if (!$res && file_exists('../../main.inc.php')) {
    $res = @include '../../main.inc.php';
}
if (!$res && file_exists('../../../main.inc.php')) {
    $res = @include '../../../main.inc.php';
}
if (!$res) {
    die('Include of main fails');
}

/**
 * Dolibarr's environment, defined by main.inc.php.
 *
 * @var DoliDB    $db
 * @var Translate $langs
 * @var User      $user
 */
global $db, $langs, $user;

require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once __DIR__ . '/../lib/billmysales.lib.php';

$langs->loadLangs(['admin', 'billmysales@billmysales']);

if (!$user->admin) {
    accessforbidden();
}

$backtopage = GETPOST('backtopage', 'alpha');

$page_name = 'BillMySalesAbout';

llxHeader('', $langs->trans($page_name));

$linkback = '<a href="' . ($backtopage ?: DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1') . '">' . $langs->trans('BackToModuleList') . '</a>';
print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

$head = billmysalesAdminPrepareHead();
print dol_get_fiche_head($head, 'about', $langs->trans($page_name), -1, 'billmysales@billmysales');

dol_include_once('/billmysales/core/modules/modBillmysales.class.php');
$module = new modBillmysales($db);
print $module->getDescLong();

print dol_get_fiche_end();

llxFooter();
$db->close();
