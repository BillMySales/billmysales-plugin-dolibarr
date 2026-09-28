<?php

declare(strict_types=1);

/**
 * Installation and removal: default settings.
 *
 * @package BillMySales\Dolibarr
 */

namespace BillMySales\Dolibarr;

/**
 * Pure data for install()/remove(). The module's own database schema is
 * created from plugin/sql (Dolibarr's own convention, loaded by
 * DolibarrModules::_load_tables()); DolibarrModules::_remove() runs no
 * SQL of its own, so this class also builds the DROP TABLE statements for
 * removal, keeping the two table names in one place with the tests.
 */
final class Installer
{
    /**
     * Configuration keys the module adds, removed when the module is
     * disabled.
     *
     * @var string[]
     */
    public const CONFIG_KEYS = [
        Settings::KEY_ACTIVE,
        Settings::KEY_URL,
        Settings::KEY_SECRET,
        Settings::KEY_EVENTS,
    ];

    /**
     * Statements that drop the module's tables.
     *
     * @param string $db_prefix The instance's table prefix (e.g. "llx_").
     * @return string[]
     */
    public static function uninstall_sql(string $db_prefix): array
    {
        return [
            'DROP TABLE IF EXISTS ' . $db_prefix . 'billmysales_delivery_queue',
            'DROP TABLE IF EXISTS ' . $db_prefix . 'billmysales_order_status',
        ];
    }
}
