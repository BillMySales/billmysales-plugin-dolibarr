<?php

declare(strict_types=1);

/**
 * The pending-deliveries queue.
 *
 * @package BillMySales\Dolibarr
 */

namespace BillMySales\Dolibarr\Webhook;

/**
 * One row per pending delivery (removed once it's no longer pending: not a
 * growing log). Reads and writes plain arrays through injected callables
 * (Dolibarr's own database class isn't touched here), so this class stays
 * unit-testable.
 */
final class Queue
{
    /**
     * Table name, without the prefix.
     */
    public const TABLE = 'billmysales_delivery_queue';

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
     * @param callable $select   Runs a SELECT, returns every row as an array.
     * @param callable $execute  Runs an INSERT/UPDATE/DELETE.
     * @param string   $db_prefix The instance's table prefix (e.g. "llx_").
     */
    public function __construct(callable $select, callable $execute, string $db_prefix = '')
    {
        $this->select = $select;
        $this->execute = $execute;
        $this->table = $db_prefix . self::TABLE;
    }

    /**
     * Queues a new delivery, due immediately.
     *
     * @param int    $id_facture  Invoice id.
     * @param string $event       Event, e.g. "invoice.paid".
     * @param string $delivery_id UUID of the notification.
     * @param string $now         Current date/time ("Y-m-d H:i:s").
     * @return void
     */
    public function enqueue(int $id_facture, string $event, string $delivery_id, string $now): void
    {
        ($this->execute)(sprintf(
            "INSERT INTO `%s` (`id_facture`, `event`, `delivery_id`, `attempt`, `next_attempt_at`, `created_at`) VALUES (%d, '%s', '%s', 0, '%s', '%s')",
            $this->table,
            $id_facture,
            $event,
            $delivery_id,
            $now,
            $now
        ));
    }

    /**
     * The deliveries due by now, oldest first.
     *
     * @param string $now   Current date/time ("Y-m-d H:i:s").
     * @param int    $limit Maximum rows.
     * @return array<int, array{id_queue: int, id_facture: int, event: string, delivery_id: string, attempt: int}>
     */
    public function due(string $now, int $limit): array
    {
        $rows = (array) ($this->select)(sprintf(
            "SELECT `id_queue`, `id_facture`, `event`, `delivery_id`, `attempt` FROM `%s` WHERE `next_attempt_at` <= '%s' ORDER BY `next_attempt_at` ASC LIMIT %d",
            $this->table,
            $now,
            $limit
        ));
        return array_map(
            static fn (array $row): array => [
                'id_queue' => (int) $row['id_queue'],
                'id_facture' => (int) $row['id_facture'],
                'event' => (string) $row['event'],
                'delivery_id' => (string) $row['delivery_id'],
                'attempt' => (int) $row['attempt'],
            ],
            $rows
        );
    }

    /**
     * Schedules the next attempt.
     *
     * @param int    $id_queue        Queue row id.
     * @param string $next_attempt_at Next attempt's date/time ("Y-m-d H:i:s").
     * @param int    $attempt         Attempts done so far.
     * @return void
     */
    public function reschedule(int $id_queue, string $next_attempt_at, int $attempt): void
    {
        ($this->execute)(sprintf(
            "UPDATE `%s` SET `next_attempt_at` = '%s', `attempt` = %d WHERE `id_queue` = %d",
            $this->table,
            $next_attempt_at,
            $attempt,
            $id_queue
        ));
    }

    /**
     * Removes a delivery (delivered, rejected or given up).
     *
     * @param int $id_queue Queue row id.
     * @return void
     */
    public function delete(int $id_queue): void
    {
        ($this->execute)(sprintf('DELETE FROM `%s` WHERE `id_queue` = %d', $this->table, $id_queue));
    }

    /**
     * How many deliveries are pending.
     *
     * @return int
     */
    public function count_pending(): int
    {
        $rows = (array) ($this->select)(sprintf('SELECT COUNT(*) AS c FROM `%s`', $this->table));
        return isset($rows[0]['c']) ? (int) $rows[0]['c'] : 0;
    }
}
