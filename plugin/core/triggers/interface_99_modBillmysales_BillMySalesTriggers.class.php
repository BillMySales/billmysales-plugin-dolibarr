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
 * Trigger: queues a delivery when an invoice reaches a selected event.
 *
 * @package BillMySales\Dolibarr
 */

require_once DOL_DOCUMENT_ROOT . '/core/triggers/dolibarrtriggers.class.php';
require_once __DIR__ . '/../../lib/billmysales.lib.php';

use BillMySales\Dolibarr\Settings;

/**
 * Listens for the invoice trigger action codes the settings can select
 * (BILL_VALIDATE, BILL_PAYED): when the action is one of them and the
 * event is configured to notify, queues a delivery (never sends it in the
 * same request, so validating or paying an invoice never waits for
 * BillMySales).
 */
class InterfaceBillMySalesTriggers extends DolibarrTriggers
{
    /**
     * Event names sent with each notification, one per trigger action code
     * this class listens for.
     *
     * @var array<string, string>
     */
    private const EVENT_NAMES = [
        'BILL_VALIDATE' => 'invoice.validated',
        'BILL_PAYED' => 'invoice.paid',
    ];

    /**
     * Constructor.
     *
     * @param DoliDB $db Database handler.
     */
    public function __construct($db)
    {
        $this->db = $db;
        $this->name = preg_replace('/^Interface/i', '', get_class($this));
        $this->family = 'billmysales';
        $this->description = 'Queues a delivery to BillMySales when an invoice is validated or paid.';
        $this->version = 'dolibarr';
        $this->picto = 'billmysales@billmysales';
    }

    /**
     * Trigger name.
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Trigger description.
     *
     * @return string
     */
    public function getDesc()
    {
        return $this->description;
    }

    /**
     * Called for every Dolibarr business event: queues a delivery when
     * $action is one this module listens for and its event is selected in
     * the settings.
     *
     * @param string       $action Event action code (e.g. "BILL_PAYED").
     * @param CommonObject $object The invoice.
     * @param User         $user   Acting user.
     * @param Translate    $langs  Translations.
     * @param Conf         $conf   Configuration.
     * @return int Negative on error, 0 when not handled, positive on success.
     */
    public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
    {
        if (!isset(self::EVENT_NAMES[$action]) || empty($object->id)) {
            return 0;
        }
        $settings = billmysalesSettings();
        if (!Settings::notifies($settings, $action)) {
            return 0;
        }
        billmysalesDelivery()->enqueue((int) $object->id, self::EVENT_NAMES[$action]);
        return 1;
    }
}
