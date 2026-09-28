<?php

declare(strict_types=1);

/**
 * Tests for SettingsPage.
 *
 * @package BillMySales\TestsDolibarr
 */

namespace BillMySales\TestsDolibarr\Admin;

use BillMySales\Dolibarr\Admin\SettingsPage;
use BillMySales\Dolibarr\Settings;
use PHPUnit\Framework\TestCase;

/**
 * @covers \BillMySales\Dolibarr\Admin\SettingsPage
 */
final class SettingsPageTest extends TestCase
{
    /**
     * @return callable
     */
    private function translate(): callable
    {
        return static fn (string $key): string => $key;
    }

    /**
     * @return void
     */
    public function test_fields(): void
    {
        $settings = [
            'url' => 'https://example.com/hook',
            'secret' => 'secret',
            'events' => ['BILL_PAYED'],
            'active' => true,
        ];

        $fields = SettingsPage::fields($this->translate(), $settings);

        self::assertSame(Settings::KEY_ACTIVE, $fields[0]['key']);
        self::assertTrue($fields[0]['value']);
        self::assertSame(Settings::KEY_URL, $fields[1]['key']);
        self::assertSame('https://example.com/hook', $fields[1]['value']);
        self::assertSame('string', $fields[1]['type']);
        self::assertSame(Settings::KEY_SECRET, $fields[2]['key']);
        self::assertSame('securekey', $fields[2]['type']);
        self::assertSame(Settings::KEY_EVENTS . '_BILL_VALIDATE', $fields[3]['key']);
        self::assertFalse($fields[3]['value']);
        self::assertSame(Settings::KEY_EVENTS . '_BILL_PAYED', $fields[4]['key']);
        self::assertTrue($fields[4]['value']);
    }

    /**
     * @return void
     */
    public function test_fields_count_matches_settings_and_events(): void
    {
        $fields = SettingsPage::fields($this->translate(), Settings::DEFAULTS);

        self::assertCount(3 + count(Settings::EVENTS), $fields);
    }
}
