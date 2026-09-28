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
 * The module's settings page: the notification URL, the shared secret and
 * the events that notify.
 *
 * @package BillMySales\Dolibarr
 */

// Load the Dolibarr environment.
$res = 0;
if (!$res && !empty($_SERVER['CONTEXT_DOCUMENT_ROOT'])) {
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

require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once __DIR__ . '/../lib/billmysales.lib.php';

$langs->loadLangs(['admin', 'billmysales@billmysales']);

if (!$user->admin) {
    accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');

if ('update' === $action) {
    billmysalesSaveSettings();
    setEventMessages($langs->trans('SetupSaved'), null);
    $action = '';
}

$form = new Form($db);
$page_name = 'BillMySalesSetup';

llxHeader('', $langs->trans($page_name), '', '', 0, 0, [dol_buildpath('/billmysales/assets/js/admin.js', 1)]);

$linkback = '<a href="' . ($backtopage ?: DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1') . '">' . $langs->trans('BackToModuleList') . '</a>';
print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

$head = billmysalesAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans($page_name), -1, 'billmysales@billmysales');

print '<span class="opacitymedium">' . $langs->trans('BillMySalesSetupPage') . '</span><br><br>';

if ('edit' === $action) {
    print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '">';
    print '<input type="hidden" name="token" value="' . newToken() . '">';
    print '<input type="hidden" name="action" value="update">';
    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre"><td class="titlefield">' . $langs->trans('Parameter') . '</td><td>' . $langs->trans('Value') . '</td></tr>';
    foreach (billmysalesSettingsFields() as $field) {
        print '<tr class="oddeven"><td>';
        print $form->textwithpicto($field['label'], $field['tooltip']);
        print '</td><td>';
        if ('yesno' === $field['type']) {
            print $form->selectyesno($field['key'], $field['value'] ? 1 : 0, 1);
        } elseif ('securekey' === $field['type']) {
            print '<input required="required" type="password" class="flat" id="' . $field['key'] . '" name="' . $field['key'] . '" value="' . dol_escape_htmltag((string) $field['value']) . '" size="40" autocomplete="off">';
        } else {
            print '<input name="' . $field['key'] . '" class="flat minwidth500" value="' . dol_escape_htmltag((string) $field['value']) . '">';
        }
        print '</td></tr>';
    }
    print '</table>';
    print '<br><div class="center"><input class="button button-save" type="submit" value="' . $langs->trans('Save') . '"></div>';
    print '</form><br>';
} else {
    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre"><td class="titlefield">' . $langs->trans('Parameter') . '</td><td>' . $langs->trans('Value') . '</td></tr>';
    foreach (billmysalesSettingsFields() as $field) {
        print '<tr class="oddeven"><td>';
        print $form->textwithpicto($field['label'], $field['tooltip']);
        print '</td><td>';
        if ('yesno' === $field['type']) {
            print yn($field['value'] ? 1 : 0);
        } elseif ('securekey' === $field['type']) {
            print '' === (string) $field['value'] ? '' : str_repeat('*', 8);
        } else {
            print dol_escape_htmltag((string) $field['value']);
        }
        print '</td></tr>';
    }
    print '</table>';
    print '<div class="tabsAction"><a class="butAction" href="' . $_SERVER['PHP_SELF'] . '?action=edit">' . $langs->trans('Modify') . '</a></div>';
}

print dol_get_fiche_end();

llxFooter();
$db->close();
