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
 * The scheduled job (Home > Setup > Modules > Scheduled jobs) that sends
 * the invoices queued for BillMySales.
 *
 * @package BillMySales\Dolibarr
 */

require_once __DIR__ . '/../lib/billmysales.lib.php';

/**
 * Dolibarr's cron runner instantiates this class and calls run(), as
 * registered in the module descriptor's $this->cronjobs.
 */
class BillMySalesCron
{
    /**
     * Database handler.
     *
     * @var DoliDB
     */
    public $db;

    /**
     * Error message, if run() returns < 0.
     *
     * @var string
     */
    public $error = '';

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
     * Sends every delivery due by now.
     *
     * @return int 0 on success, < 0 on error.
     */
    public function run()
    {
        try {
            billmysalesProcessDueDeliveries();
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
            return -1;
        }
        return 0;
    }
}
