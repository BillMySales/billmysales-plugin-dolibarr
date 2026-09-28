<?php

declare(strict_types=1);

/**
 * Tests for Settings.
 *
 * @package BillMySales\TestsDolibarr
 */

namespace BillMySales\TestsDolibarr;

use BillMySales\Dolibarr\Settings;
use PHPUnit\Framework\TestCase;

/**
 * @covers \BillMySales\Dolibarr\Settings
 */
final class SettingsTest extends TestCase
{
    /**
     * @return void
     */
    public function test_from_raw_with_valid_values(): void
    {
        $settings = Settings::from_raw('https://example.com/hook', 'secret', '["BILL_VALIDATE","BILL_PAYED"]', '1');

        self::assertSame([
            'url' => 'https://example.com/hook',
            'secret' => 'secret',
            'events' => ['BILL_VALIDATE', 'BILL_PAYED'],
            'active' => true,
        ], $settings);
    }

    /**
     * @return void
     */
    public function test_from_raw_falls_back_to_defaults(): void
    {
        $settings = Settings::from_raw(false, false, false, false);

        self::assertSame(Settings::DEFAULTS, $settings);
    }

    /**
     * @return void
     */
    public function test_from_raw_drops_unknown_events(): void
    {
        $settings = Settings::from_raw('https://example.com', 'secret', '["BILL_VALIDATE","BILL_DELETE"]', '1');

        self::assertSame(['BILL_VALIDATE'], $settings['events']);
    }

    /**
     * @return void
     */
    public function test_from_raw_with_non_json_events(): void
    {
        $settings = Settings::from_raw('https://example.com', 'secret', 'not json', '1');

        self::assertSame([], $settings['events']);
    }

    /**
     * @return void
     */
    public function test_is_ready(): void
    {
        self::assertTrue(Settings::is_ready(['url' => 'https://example.com', 'secret' => 's', 'events' => [], 'active' => true]));
        self::assertFalse(Settings::is_ready(['url' => '', 'secret' => 's', 'events' => [], 'active' => true]));
        self::assertFalse(Settings::is_ready(['url' => 'https://example.com', 'secret' => '', 'events' => [], 'active' => true]));
        self::assertFalse(Settings::is_ready(['url' => 'https://example.com', 'secret' => 's', 'events' => [], 'active' => false]));
    }

    /**
     * @return void
     */
    public function test_notifies(): void
    {
        $settings = [
            'url' => 'https://example.com',
            'secret' => 's',
            'events' => ['BILL_PAYED'],
            'active' => true,
        ];

        self::assertTrue(Settings::notifies($settings, 'BILL_PAYED'));
        self::assertFalse(Settings::notifies($settings, 'BILL_VALIDATE'));
    }

    /**
     * @return void
     */
    public function test_notifies_when_not_ready(): void
    {
        $settings = [
            'url' => '',
            'secret' => '',
            'events' => ['BILL_PAYED'],
            'active' => true,
        ];

        self::assertFalse(Settings::notifies($settings, 'BILL_PAYED'));
    }

    /**
     * @return void
     */
    public function test_sanitize_with_valid_input(): void
    {
        $input = [
            Settings::KEY_ACTIVE => '1',
            Settings::KEY_URL => '  https://example.com  ',
            Settings::KEY_SECRET => 'secret',
            Settings::KEY_EVENTS . '_BILL_VALIDATE' => '1',
        ];

        self::assertSame([
            'url' => 'https://example.com',
            'secret' => 'secret',
            'events' => ['BILL_VALIDATE'],
            'active' => true,
        ], Settings::sanitize($input));
    }

    /**
     * @return void
     */
    public function test_sanitize_with_missing_input(): void
    {
        self::assertSame(Settings::DEFAULTS, Settings::sanitize([]));
    }
}
