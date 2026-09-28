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
 * Description and activation file for the BillMySales module.
 *
 * @package BillMySales\Dolibarr
 */

require_once DOL_DOCUMENT_ROOT . '/core/modules/DolibarrModules.class.php';
require_once __DIR__ . '/../../lib/billmysales.lib.php';

/**
 * Description and activation class for the BillMySales module: sends
 * invoices to BillMySales when they reach a selected event (validated,
 * paid).
 */
class modBillmysales extends DolibarrModules
{
    /**
     * Constructor: defines the module's metadata, hooks, triggers and
     * cron job.
     *
     * @param DoliDB $db Database handler.
     */
    public function __construct($db)
    {
        global $conf;
        $this->db = $db;

        $this->numero = 483001;
        $this->rights_class = 'billmysales';
        $this->family = 'financial';
        $this->module_position = '01';
        $this->name = preg_replace('/^mod/i', '', get_class($this));
        $this->description = 'Sends invoices to BillMySales when they reach a selected event.';
        $this->descriptionlong = 'Sends invoices to BillMySales when they are validated or paid, so BillMySales can turn them into the official billing document. The URL, the shared secret and the events that notify are configured in the module\'s settings.';

        $this->editor_name = 'BillMySales';
        $this->editor_url = 'https://www.billmysales.com';

        $this->version = '2.0.0';
        $this->const_name = 'MAIN_MODULE_' . strtoupper($this->name);
        $this->picto = 'billmysales@billmysales';

        $this->module_parts = [
            'triggers' => 1,
            'hooks' => [
                'data' => ['invoicecard'],
            ],
        ];

        $this->config_page_url = ['setup.php@billmysales'];

        $this->hidden = false;
        $this->depends = [];
        $this->requiredby = [];
        $this->conflictwith = [];

        $this->langfiles = ['billmysales@billmysales'];

        // The lowest PHP and Dolibarr the module supports (also tested on
        // the latest of each).
        $this->phpmin = [7, 4];
        $this->need_dolibarr_version = [19, 0];

        $this->warnings_activation = [];
        $this->warnings_activation_ext = [];

        $this->const = [];

        if (!isset($conf->billmysales) || !isset($conf->billmysales->enabled)) {
            $conf->billmysales = new stdClass();
            $conf->billmysales->enabled = 0;
        }

        $this->tabs = [];
        $this->dictionaries = [];
        $this->boxes = [];

        $this->cronjobs = [
            [
                'label' => 'BillMySalesProcessDueDeliveries',
                'jobtype' => 'method',
                'class' => '/billmysales/class/billmysalescron.class.php',
                'objectname' => 'BillMySalesCron',
                'method' => 'run',
                'parameters' => '',
                'comment' => 'Sends the invoices queued for BillMySales',
                'frequency' => 5,
                'unitfrequency' => 60,
                'status' => 1,
                'test' => '$conf->billmysales->enabled',
                'priority' => 50,
            ],
        ];

        $this->rights = [];
    }

    /**
     * Called when the module is enabled: creates the module's tables
     * (plugin/sql).
     *
     * @param string $options Options when enabling the module ('', 'noboxes').
     * @return int 1 if OK, <0 if KO.
     */
    public function init($options = '')
    {
        $result = $this->_load_tables('/billmysales/sql/');
        if (!$result) {
            return -1;
        }
        return $this->_init([], $options);
    }

    /**
     * Called when the module is disabled: removes the module's
     * Configuration values and drops its tables.
     *
     * @param string $options Options when disabling the module.
     * @return int 1 if OK, <0 if KO.
     */
    public function remove($options = '')
    {
        global $conf;
        foreach (\BillMySales\Dolibarr\Installer::CONFIG_KEYS as $key) {
            dolibarr_del_const($this->db, $key, $conf->entity);
        }
        $sql = \BillMySales\Dolibarr\Installer::uninstall_sql(MAIN_DB_PREFIX);
        return $this->_remove($sql, $options);
    }
}
