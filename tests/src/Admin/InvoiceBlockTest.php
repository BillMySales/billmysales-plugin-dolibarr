<?php

declare(strict_types=1);

/**
 * Tests for InvoiceBlock.
 *
 * @package BillMySales\TestsDolibarr
 */

namespace BillMySales\TestsDolibarr\Admin;

use BillMySales\Dolibarr\Admin\InvoiceBlock;
use BillMySales\Dolibarr\Webhook\Delivery;
use PHPUnit\Framework\TestCase;

/**
 * @covers \BillMySales\Dolibarr\Admin\InvoiceBlock
 */
final class InvoiceBlockTest extends TestCase
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
    public function test_view_with_no_status_yet(): void
    {
        $view = InvoiceBlock::view($this->translate(), null, true);

        self::assertNull($view['label']);
        self::assertSame('', $view['detail']);
        self::assertTrue($view['can_resend']);
    }

    /**
     * @return void
     */
    public function test_view_with_a_delivered_status(): void
    {
        $status = [
            'status' => Delivery::RESULT_DELIVERED,
            'detail' => 'HTTP 200',
            'event' => 'invoice.paid',
            'delivery_id' => 'uuid-1',
            'attempts' => 1,
            'updated_at' => '2026-01-01 10:00:00',
        ];

        $view = InvoiceBlock::view($this->translate(), $status, true);

        self::assertSame('BillMySalesStatusSent', $view['label']);
        self::assertSame('HTTP 200', $view['detail']);
        self::assertSame('2026-01-01 10:00:00', $view['updated_at']);
    }

    /**
     * @return void
     */
    public function test_view_translates_every_known_status(): void
    {
        $known = [
            Delivery::RESULT_DELIVERED => 'BillMySalesStatusSent',
            Delivery::RESULT_RETRY => 'BillMySalesStatusRetrying',
            Delivery::RESULT_GIVEN_UP => 'BillMySalesStatusGivenUp',
            Delivery::RESULT_REJECTED => 'BillMySalesStatusRejected',
            Delivery::RESULT_GONE => 'BillMySalesStatusGone',
        ];
        foreach ($known as $status => $label) {
            $view = InvoiceBlock::view($this->translate(), [
                'status' => $status,
                'detail' => '',
                'event' => 'invoice.paid',
                'delivery_id' => 'uuid-1',
                'attempts' => 1,
                'updated_at' => '2026-01-01 10:00:00',
            ], true);
            self::assertSame($label, $view['label']);
        }
    }

    /**
     * @return void
     */
    public function test_view_with_an_unknown_status(): void
    {
        $view = InvoiceBlock::view($this->translate(), [
            'status' => 'something_else',
            'detail' => '',
            'event' => 'invoice.paid',
            'delivery_id' => 'uuid-1',
            'attempts' => 1,
            'updated_at' => '2026-01-01 10:00:00',
        ], false);

        self::assertSame('something_else', $view['label']);
        self::assertFalse($view['can_resend']);
    }
}
