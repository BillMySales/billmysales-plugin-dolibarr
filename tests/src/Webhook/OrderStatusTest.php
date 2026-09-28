<?php

declare(strict_types=1);

/**
 * Tests for OrderStatus.
 *
 * @package BillMySales\TestsDolibarr
 */

namespace BillMySales\TestsDolibarr\Webhook;

use BillMySales\Dolibarr\Webhook\OrderStatus;
use PHPUnit\Framework\TestCase;

/**
 * @covers \BillMySales\Dolibarr\Webhook\OrderStatus
 */
final class OrderStatusTest extends TestCase
{
    /**
     * @return void
     */
    public function test_record(): void
    {
        $executed = [];
        $status = new OrderStatus(
            static fn (): array => [],
            static function (string $sql) use (&$executed): void {
                $executed[] = $sql;
            },
            'llx_'
        );

        $status->record(5, 'delivered', "HTTP 200: it's fine", 'invoice.paid', 'uuid-1', 1, '2026-01-01 10:00:00');

        self::assertStringContainsString('REPLACE INTO `llx_billmysales_order_status`', $executed[0]);
        self::assertStringContainsString("'delivered'", $executed[0]);
        self::assertStringContainsString("HTTP 200: it\\'s fine", $executed[0]);
        self::assertStringContainsString("'invoice.paid'", $executed[0]);
        self::assertStringContainsString("'uuid-1'", $executed[0]);
        self::assertStringContainsString('5', $executed[0]);
    }

    /**
     * @return void
     */
    public function test_get(): void
    {
        $status = new OrderStatus(
            static fn (): array => [[
                'status' => 'delivered',
                'detail' => 'HTTP 200',
                'event' => 'invoice.paid',
                'delivery_id' => 'uuid-1',
                'attempts' => '1',
                'updated_at' => '2026-01-01 10:00:00',
            ]],
            static fn (): array => [],
            'llx_'
        );

        self::assertSame([
            'status' => 'delivered',
            'detail' => 'HTTP 200',
            'event' => 'invoice.paid',
            'delivery_id' => 'uuid-1',
            'attempts' => 1,
            'updated_at' => '2026-01-01 10:00:00',
        ], $status->get(5));
    }

    /**
     * @return void
     */
    public function test_get_returns_null_when_missing(): void
    {
        $status = new OrderStatus(static fn (): array => [], static fn (): array => [], 'llx_');

        self::assertNull($status->get(5));
    }

    /**
     * @return void
     */
    public function test_delete(): void
    {
        $executed = [];
        $status = new OrderStatus(
            static fn (): array => [],
            static function (string $sql) use (&$executed): void {
                $executed[] = $sql;
            },
            'llx_'
        );

        $status->delete(5);

        self::assertStringContainsString('DELETE FROM `llx_billmysales_order_status`', $executed[0]);
        self::assertStringContainsString('`id_facture` = 5', $executed[0]);
    }
}
