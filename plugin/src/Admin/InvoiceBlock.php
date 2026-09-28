<?php

declare(strict_types=1);

/**
 * The "BillMySales" block on the invoice detail page.
 *
 * @package BillMySales\Dolibarr
 */

namespace BillMySales\Dolibarr\Admin;

use BillMySales\Dolibarr\Webhook\Delivery;

/**
 * Shapes the invoice's last known delivery status (and whether "Send to
 * BillMySales" should show) into plain data the invoice page prints.
 */
final class InvoiceBlock
{
    /**
     * The block's data.
     *
     * @param callable                                                                                          $translate       Translates a label (Dolibarr's $langs->trans()).
     * @param array{status: string, detail: string, event: string, delivery_id: string, attempts: int, updated_at: string}|null $delivery_status Last known delivery status, or null if never attempted.
     * @param bool                                                                                               $can_resend      Whether deliveries are configured (settings ready).
     * @return array{label: string|null, detail: string, updated_at: string, can_resend: bool}
     */
    public static function view(callable $translate, ?array $delivery_status, bool $can_resend): array
    {
        if (null === $delivery_status) {
            return [
                'label' => null,
                'detail' => '',
                'updated_at' => '',
                'can_resend' => $can_resend,
            ];
        }
        $labels = [
            Delivery::RESULT_DELIVERED => $translate('BillMySalesStatusSent'),
            Delivery::RESULT_RETRY => $translate('BillMySalesStatusRetrying'),
            Delivery::RESULT_GIVEN_UP => $translate('BillMySalesStatusGivenUp'),
            Delivery::RESULT_REJECTED => $translate('BillMySalesStatusRejected'),
            Delivery::RESULT_GONE => $translate('BillMySalesStatusGone'),
        ];
        return [
            'label' => $labels[$delivery_status['status']] ?? $delivery_status['status'],
            'detail' => $delivery_status['detail'],
            'updated_at' => $delivery_status['updated_at'],
            'can_resend' => $can_resend,
        ];
    }
}
