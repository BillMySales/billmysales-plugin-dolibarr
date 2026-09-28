<?php

declare(strict_types=1);

/**
 * Asynchronous delivery of invoices to BillMySales, with retries.
 *
 * @package BillMySales\Dolibarr
 */

namespace BillMySales\Dolibarr\Webhook;

use BillMySales\Dolibarr\Settings;

/**
 * Queues a delivery when an invoice reaches a selected event (or the
 * merchant asks to send it again), and sends it from the module's cron
 * job or opportunistically, so the trigger and the invoice page never wait
 * for BillMySales. Failed deliveries are retried on network errors,
 * timeouts, rate limits and server errors; other HTTP errors are recorded,
 * not retried. The payload is built when the delivery runs, from the
 * invoice's data at that moment, not when it was queued.
 */
final class Delivery
{
    /**
     * Delay (seconds) before each retry; after the last one, the delivery
     * is given up.
     */
    public const RETRY_DELAYS = [60, 300, 1800, 7200, 43200];

    /**
     * The invoice was accepted by BillMySales.
     */
    public const RESULT_DELIVERED = 'delivered';

    /**
     * A retryable error: another attempt is scheduled.
     */
    public const RESULT_RETRY = 'retry';

    /**
     * A retryable error, but every retry is already used.
     */
    public const RESULT_GIVEN_UP = 'given_up';

    /**
     * A non-retryable error: BillMySales rejected the invoice.
     */
    public const RESULT_REJECTED = 'rejected';

    /**
     * The invoice no longer exists (deleted before its delivery ran).
     */
    public const RESULT_GONE = 'gone';

    /**
     * The pending-deliveries queue.
     */
    private Queue $queue;

    /**
     * Current date/time ("Y-m-d H:i:s"), injected so tests control it.
     *
     * @var callable
     */
    private $now;

    /**
     * Generates a UUID for a new delivery.
     *
     * @var callable
     */
    private $uuid;

    /**
     * @param Queue    $queue Pending-deliveries queue.
     * @param callable $now   (): string Current date/time ("Y-m-d H:i:s").
     * @param callable $uuid  (): string A new UUID.
     */
    public function __construct(Queue $queue, callable $now, callable $uuid)
    {
        $this->queue = $queue;
        $this->now = $now;
        $this->uuid = $uuid;
    }

    /**
     * Queues a new delivery, due immediately.
     *
     * @param int    $id_facture Invoice id.
     * @param string $event      Event, e.g. "invoice.paid".
     * @return void
     */
    public function enqueue(int $id_facture, string $event): void
    {
        $this->queue->enqueue($id_facture, $event, ($this->uuid)(), ($this->now)());
    }

    /**
     * Sends every delivery due by now.
     *
     * @param array{url: string, secret: string, events: string[], active: bool} $settings      Settings.
     * @param array{source: string, platform_version: string, plugin_version: string} $context Delivery context.
     * @param callable                                                           $facture_data  (int $id_facture): ?array<string, mixed> Plain invoice data, or null if it no longer exists.
     * @param callable                                                           $societe_data  (int $id_facture): array<string, mixed> Plain customer data.
     * @param callable                                                           $http_post     (string $url, string $body, array<string, string> $headers): array{int, string} HTTP status (or -1 on a network error) and the response body/error.
     * @param callable                                                           $on_result     (int $id_facture, string $event, string $delivery_id, string $result, string $detail, int $attempts): void Called after each attempt.
     * @param int                                                                $limit         Maximum deliveries sent in this run.
     * @return void
     */
    public function process_due(
        array $settings,
        array $context,
        callable $facture_data,
        callable $societe_data,
        callable $http_post,
        callable $on_result,
        int $limit = 20
    ): void {
        if (!Settings::is_ready($settings)) {
            return;
        }
        foreach ($this->queue->due(($this->now)(), $limit) as $row) {
            $this->process_one($row, $settings, $context, $facture_data, $societe_data, $http_post, $on_result);
        }
    }

    /**
     * Whether an HTTP status is worth retrying: a network error (-1),
     * timeouts, rate limits and server errors. Other client errors won't
     * change on a retry.
     *
     * @param int $code HTTP status, or -1 for a network error.
     * @return bool
     */
    public static function is_retryable(int $code): bool
    {
        return -1 === $code || 408 === $code || 429 === $code || $code >= 500;
    }

    /**
     * Sends one due delivery.
     *
     * @param array{id_queue: int, id_facture: int, event: string, delivery_id: string, attempt: int} $row Queue row.
     * @param array{url: string, secret: string, events: string[], active: bool}                       $settings Settings.
     * @param array{source: string, platform_version: string, plugin_version: string}                  $context  Delivery context.
     * @param callable                                                                                 $facture_data Builds the invoice's plain data.
     * @param callable                                                                                 $societe_data Builds the customer's plain data.
     * @param callable                                                                                 $http_post    Posts the payload.
     * @param callable                                                                                 $on_result    Called with the outcome.
     * @return void
     */
    private function process_one(
        array $row,
        array $settings,
        array $context,
        callable $facture_data,
        callable $societe_data,
        callable $http_post,
        callable $on_result
    ): void {
        $facture = ($facture_data)($row['id_facture']);
        if (null === $facture) {
            $this->queue->delete($row['id_queue']);
            $on_result($row['id_facture'], $row['event'], $row['delivery_id'], self::RESULT_GONE, 'invoice no longer exists', $row['attempt']);
            return;
        }

        $societe = ($societe_data)($row['id_facture']);
        $body = (string) json_encode(Payload::build($facture, $societe));
        $headers = Headers::build(
            $body,
            $settings['secret'],
            $row['event'],
            $row['delivery_id'],
            $context['source'],
            $context['platform_version'],
            $context['plugin_version']
        );
        [$code, $response] = $http_post($settings['url'], $body, $headers);

        if ($code >= 200 && $code < 300) {
            $this->queue->delete($row['id_queue']);
            $on_result($row['id_facture'], $row['event'], $row['delivery_id'], self::RESULT_DELIVERED, 'HTTP ' . $code, $row['attempt'] + 1);
            return;
        }

        if (self::is_retryable($code) && isset(self::RETRY_DELAYS[$row['attempt']])) {
            $delay = self::RETRY_DELAYS[$row['attempt']];
            $next_attempt_at = date('Y-m-d H:i:s', strtotime(($this->now)()) + $delay);
            $this->queue->reschedule($row['id_queue'], $next_attempt_at, $row['attempt'] + 1);
            $on_result($row['id_facture'], $row['event'], $row['delivery_id'], self::RESULT_RETRY, $this->detail($code, $response), $row['attempt'] + 1);
            return;
        }

        $this->queue->delete($row['id_queue']);
        $result = self::is_retryable($code) ? self::RESULT_GIVEN_UP : self::RESULT_REJECTED;
        $on_result($row['id_facture'], $row['event'], $row['delivery_id'], $result, $this->detail($code, $response), $row['attempt'] + 1);
    }

    /**
     * Formats an attempt's detail: the network error message as is, or the
     * HTTP status plus a truncated body for a 4xx/5xx response.
     *
     * @param int    $code     HTTP status, or -1 for a network error.
     * @param string $response Response body, or the network error message.
     * @return string
     */
    private function detail(int $code, string $response): string
    {
        if ($code < 0) {
            return $response;
        }
        $body = trim(substr($response, 0, 300));
        return 'HTTP ' . $code . ('' !== $body ? ': ' . $body : '');
    }
}
