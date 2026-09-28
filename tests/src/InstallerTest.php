<?php

declare(strict_types=1);

/**
 * Tests for Installer.
 *
 * @package BillMySales\TestsDolibarr
 */

namespace BillMySales\TestsDolibarr;

use BillMySales\Dolibarr\Installer;
use BillMySales\Dolibarr\Settings;
use PHPUnit\Framework\TestCase;

/**
 * @covers \BillMySales\Dolibarr\Installer
 */
final class InstallerTest extends TestCase
{
    /**
     * @return void
     */
    public function test_config_keys(): void
    {
        self::assertSame([
            Settings::KEY_ACTIVE,
            Settings::KEY_URL,
            Settings::KEY_SECRET,
            Settings::KEY_EVENTS,
        ], Installer::CONFIG_KEYS);
    }

    /**
     * @return void
     */
    public function test_uninstall_sql(): void
    {
        $statements = Installer::uninstall_sql('llx_');

        self::assertSame([
            'DROP TABLE IF EXISTS llx_billmysales_delivery_queue',
            'DROP TABLE IF EXISTS llx_billmysales_order_status',
        ], $statements);
    }
}
