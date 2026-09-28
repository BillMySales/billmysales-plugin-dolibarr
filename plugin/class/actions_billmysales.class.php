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
 * Hooks: the invoice detail page's "BillMySales" block, the "Send to
 * BillMySales" button and its action.
 *
 * @package BillMySales\Dolibarr
 */

require_once __DIR__ . '/../lib/billmysales.lib.php';

use BillMySales\Dolibarr\Settings;

/**
 * Implements the module's hooks, registered for the "invoicecard" context
 * (module_parts['hooks'] in the module descriptor).
 */
class ActionsBillmysales
{
    /**
     * Database handler.
     *
     * @var DoliDB
     */
    public $db;

    /**
     * Errors.
     *
     * @var string[]
     */
    public $errors = [];

    /**
     * Values a hook can return to the caller.
     *
     * @var array<string, mixed>
     */
    public $results = [];

    /**
     * A hook's own HTML output, appended to HookManager::resPrint by the
     * caller (formObjectOptions only; addMoreActionsButtons prints
     * directly).
     *
     * @var string
     */
    public $resprints = '';

    /**
     * Constructor.
     *
     * @param DoliDB $db Database handler.
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Handles the "Send to BillMySales" button: queues a new delivery for
     * the current invoice, whatever the events selected in the settings.
     *
     * @param array<string, mixed> $parameters  Hook parameters.
     * @param CommonObject         $object      The invoice.
     * @param string               $action      Current action.
     * @param HookManager          $hookmanager Hook manager.
     * @return int
     */
    public function doActions($parameters, &$object, &$action, $hookmanager)
    {
        unset($parameters, $hookmanager);
        if ('billmysales_resend' !== $action || empty($object->id)) {
            return 0;
        }
        billmysalesDelivery()->enqueue((int) $object->id, 'invoice.resent');
        setEventMessages($GLOBALS['langs']->trans('BillMySalesResendRequested'), null);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . ((int) $object->id));
        exit;
    }

    /**
     * Adds the "BillMySales" row (delivery status) to the invoice's
     * details table.
     *
     * @param array<string, mixed> $parameters  Hook parameters.
     * @param CommonObject         $object      The invoice.
     * @param string               $action      Current action.
     * @param HookManager          $hookmanager Hook manager.
     * @return int
     */
    public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
    {
        unset($parameters, $action, $hookmanager);
        if (empty($object->id)) {
            return 0;
        }
        $view = billmysalesInvoiceBlockView((int) $object->id);

        $html = '<tr><td>' . $GLOBALS['langs']->trans('BillMySalesStatus') . '</td><td>';
        if (null === $view['label']) {
            $html .= $GLOBALS['langs']->trans('BillMySalesStatusNone');
        } else {
            $html .= dol_escape_htmltag($view['label']);
            if ('' !== $view['detail']) {
                $html .= ' <span class="opacitymedium">(' . dol_escape_htmltag($view['detail']) . ')</span>';
            }
        }
        $html .= '</td></tr>';

        $this->resprints = $html;
        return 0;
    }

    /**
     * Adds the "Send to BillMySales" button, when deliveries are
     * configured.
     *
     * @param array<string, mixed> $parameters  Hook parameters.
     * @param CommonObject         $object      The invoice.
     * @param string               $action      Current action.
     * @param HookManager          $hookmanager Hook manager.
     * @return int
     */
    public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
    {
        unset($parameters, $action, $hookmanager);
        $settings = billmysalesSettings();
        if (empty($object->id) || !Settings::is_ready($settings)) {
            return 0;
        }
        print '<a class="butAction" href="' . $_SERVER['PHP_SELF'] . '?id=' . ((int) $object->id) . '&action=billmysales_resend&token=' . newToken() . '">';
        print $GLOBALS['langs']->trans('BillMySalesSendAgain');
        print '</a>';
        return 0;
    }
}
