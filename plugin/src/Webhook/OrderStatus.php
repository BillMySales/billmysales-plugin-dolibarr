<?php

declare(strict_types=1);

/**
 * The last known delivery status of an invoice.
 *
 * @package BillMySales\Dolibarr
 */

namespace BillMySales\Dolibarr\Webhook;

/**
 * One row per invoice that has ever had a delivery attempt (replaced on
 * each new attempt, bounded by the shop's invoice count, not by attempts).
 * Shown on the invoice detail page. Reads and writes plain arrays through
 * injected callables, so this class stays unit-testable.
 */
final class OrderStatus
{
    /**
     * Table name, without the prefix.
     */
    public const TABLE = 'billmysales_order_status';

    /**
     * Runs a SELECT and returns every row.
     *
     * @var callable
     */
    private $select;

    /**
     * Runs an INSERT/UPDATE/DELETE.
     *
     * @var callable
     */
    private $execute;

    /**
     * The table's real name, with the shop's prefix.
     */
    private string $table;

    /**
     * @param callable $select    Runs a SELECT, returns every row as an array.
     * @param callable $execute   Runs an INSERT/UPDATE/DELETE.
     * @param string   $db_prefix The instance's table prefix (e.g. "llx_").
     */
    public function __construct(callable $select, callable $execute, string $db_prefix = '')
    {
        $this->select = $select;
        $this->execute = $execute;
        $this->table = $db_prefix . self::TABLE;
    }

    /**
     * Records the outcome of a delivery attempt (replaces any previous one
     * for this invoice).
     *
     * @param int    $id_facture  Invoice id.
     * @param string $status      Result ("delivered", "retry", "rejected" or "given_up").
     * @param string $detail      Detail (e.g. the HTTP status and a truncated body).
     * @param string $event       Event of the delivery.
     * @param string $delivery_id UUID of the delivery.
     * @param int    $attempts    Attempts made so far.
     * @param string $updated_at  Date/time of this attempt ("Y-m-d H:i:s").
     * @return void
     */
    public function record(
        int $id_facture,
        string $status,
        string $detail,
        string $event,
        string $delivery_id,
        int $attempts,
        string $updated_at
    ): void {
        ($this->execute)(sprintf(
            "REPLACE INTO `%s` (`id_facture`, `status`, `detail`, `event`, `delivery_id`, `attempts`, `updated_at`) VALUES (%d, '%s', '%s', '%s', '%s', %d, '%s')",
            $this->table,
            $id_facture,
            $status,
            addslashes($detail),
            $event,
            $delivery_id,
            $attempts,
            $updated_at
        ));
    }

    /**
     * The last known delivery status of an invoice.
     *
     * @param int $id_facture Invoice id.
     * @return array{status: string, detail: string, event: string, delivery_id: string, attempts: int, updated_at: string}|null
     */
    public function get(int $id_facture): ?array
    {
        $rows = (array) ($this->select)(sprintf(
            'SELECT `status`, `detail`, `event`, `delivery_id`, `attempts`, `updated_at` FROM `%s` WHERE `id_facture` = %d',
            $this->table,
            $id_facture
        ));
        if (!isset($rows[0])) {
            return null;
        }
        $row = $rows[0];
        return [
            'status' => (string) $row['status'],
            'detail' => (string) $row['detail'],
            'event' => (string) $row['event'],
            'delivery_id' => (string) $row['delivery_id'],
            'attempts' => (int) $row['attempts'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /**
     * Removes the delivery status of an invoice (its status is deleted).
     *
     * @param int $id_facture Invoice id.
     * @return void
     */
    public function delete(int $id_facture): void
    {
        ($this->execute)(sprintf('DELETE FROM `%s` WHERE `id_facture` = %d', $this->table, $id_facture));
    }
}
