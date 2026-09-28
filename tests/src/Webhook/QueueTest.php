<?php

declare(strict_types=1);

/**
 * Tests for Queue.
 *
 * @package BillMySales\TestsDolibarr
 */

namespace BillMySales\TestsDolibarr\Webhook;

use BillMySales\Dolibarr\Webhook\Queue;
use PHPUnit\Framework\TestCase;

/**
 * @covers \BillMySales\Dolibarr\Webhook\Queue
 */
final class QueueTest extends TestCase
{
    /**
     * @return void
     */
    public function test_enqueue(): void
    {
        $executed = [];
        $queue = new Queue(
            static fn (): array => [],
            static function (string $sql) use (&$executed): void {
                $executed[] = $sql;
            },
            'llx_'
        );

        $queue->enqueue(5, 'invoice.paid', 'uuid-1', '2026-01-01 10:00:00');

        self::assertCount(1, $executed);
        self::assertStringContainsString('INSERT INTO `llx_billmysales_delivery_queue`', $executed[0]);
        self::assertStringContainsString('5', $executed[0]);
        self::assertStringContainsString("'invoice.paid'", $executed[0]);
        self::assertStringContainsString("'uuid-1'", $executed[0]);
        self::assertStringContainsString("'2026-01-01 10:00:00'", $executed[0]);
    }

    /**
     * @return void
     */
    public function test_due(): void
    {
        $selected = [];
        $queue = new Queue(
            static function (string $sql) use (&$selected): array {
                $selected[] = $sql;
                return [
                    ['id_queue' => '1', 'id_facture' => '5', 'event' => 'invoice.paid', 'delivery_id' => 'uuid-1', 'attempt' => '0'],
                ];
            },
            static fn (): array => [],
            'llx_'
        );

        $rows = $queue->due('2026-01-01 10:00:00', 20);

        self::assertStringContainsString('SELECT', $selected[0]);
        self::assertStringContainsString("<= '2026-01-01 10:00:00'", $selected[0]);
        self::assertStringContainsString('LIMIT 20', $selected[0]);
        self::assertSame([
            ['id_queue' => 1, 'id_facture' => 5, 'event' => 'invoice.paid', 'delivery_id' => 'uuid-1', 'attempt' => 0],
        ], $rows);
    }

    /**
     * @return void
     */
    public function test_reschedule(): void
    {
        $executed = [];
        $queue = new Queue(
            static fn (): array => [],
            static function (string $sql) use (&$executed): void {
                $executed[] = $sql;
            },
            'llx_'
        );

        $queue->reschedule(3, '2026-01-01 11:00:00', 2);

        self::assertStringContainsString('UPDATE `llx_billmysales_delivery_queue`', $executed[0]);
        self::assertStringContainsString("'2026-01-01 11:00:00'", $executed[0]);
        self::assertStringContainsString('`attempt` = 2', $executed[0]);
        self::assertStringContainsString('`id_queue` = 3', $executed[0]);
    }

    /**
     * @return void
     */
    public function test_delete(): void
    {
        $executed = [];
        $queue = new Queue(
            static fn (): array => [],
            static function (string $sql) use (&$executed): void {
                $executed[] = $sql;
            },
            'llx_'
        );

        $queue->delete(7);

        self::assertStringContainsString('DELETE FROM `llx_billmysales_delivery_queue`', $executed[0]);
        self::assertStringContainsString('`id_queue` = 7', $executed[0]);
    }

    /**
     * @return void
     */
    public function test_count_pending(): void
    {
        $queue = new Queue(
            static fn (): array => [['c' => '3']],
            static fn (): array => [],
            'llx_'
        );

        self::assertSame(3, $queue->count_pending());
    }

    /**
     * @return void
     */
    public function test_count_pending_with_no_rows(): void
    {
        $queue = new Queue(
            static fn (): array => [],
            static fn (): array => [],
            'llx_'
        );

        self::assertSame(0, $queue->count_pending());
    }

    /**
     * @return void
     */
    public function test_default_prefix_is_empty(): void
    {
        $executed = [];
        $queue = new Queue(
            static fn (): array => [],
            static function (string $sql) use (&$executed): void {
                $executed[] = $sql;
            }
        );

        $queue->delete(1);

        self::assertStringContainsString('DELETE FROM `billmysales_delivery_queue`', $executed[0]);
    }
}
