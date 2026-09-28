<?php

declare(strict_types=1);

/**
 * Tests for Delivery.
 *
 * @package BillMySales\TestsDolibarr
 */

namespace BillMySales\TestsDolibarr\Webhook;

use BillMySales\Dolibarr\Webhook\Delivery;
use BillMySales\Dolibarr\Webhook\Queue;
use PHPUnit\Framework\TestCase;

/**
 * @covers \BillMySales\Dolibarr\Webhook\Delivery
 * @uses \BillMySales\Dolibarr\Settings
 * @uses \BillMySales\Dolibarr\Webhook\Queue
 * @uses \BillMySales\Dolibarr\Webhook\Headers
 * @uses \BillMySales\Dolibarr\Webhook\Payload
 */
final class DeliveryTest extends TestCase
{
    /**
     * A queue whose due() returns one fixed row, recording every executed
     * statement (delete/reschedule) for assertions.
     *
     * @param array<int, array{id_queue: int, id_facture: int, event: string, delivery_id: string, attempt: int}> $rows Rows due() returns.
     * @param array<int, string>                                                                                   $executed Reference filled with every executed statement.
     * @return Queue
     */
    private function queue_with(array $rows, array &$executed): Queue
    {
        return new Queue(
            static fn (): array => $rows,
            static function (string $sql) use (&$executed): void {
                $executed[] = $sql;
            },
            'llx_'
        );
    }

    /**
     * @return array{url: string, secret: string, events: string[], active: bool}
     */
    private function settings(): array
    {
        return ['url' => 'https://example.com/hook', 'secret' => 'secret', 'events' => ['BILL_PAYED'], 'active' => true];
    }

    /**
     * @return array{source: string, platform_version: string, plugin_version: string}
     */
    private function context(): array
    {
        return ['source' => 'https://example.com', 'platform_version' => '24.0.1', 'plugin_version' => '2.0.0'];
    }

    /**
     * @return callable
     */
    private function fixed_now(): callable
    {
        return static fn (): string => '2026-01-01 10:00:00';
    }

    /**
     * @param string $uuid UUID to return.
     * @return callable
     */
    private function fixed_uuid(string $uuid = 'uuid-1'): callable
    {
        return static fn (): string => $uuid;
    }

    /**
     * A recorder for on_result's calls.
     *
     * @param array<int, array<int, mixed>> $results Reference filled with each call's arguments.
     * @return callable
     */
    private function record_result(array &$results): callable
    {
        return static function (int $id_facture, string $event, string $delivery_id, string $result, string $detail, int $attempts) use (&$results): void {
            $results[] = [$id_facture, $event, $delivery_id, $result, $detail, $attempts];
        };
    }

    /**
     * @return void
     */
    public function test_enqueue(): void
    {
        $executed = [];
        $queue = $this->queue_with([], $executed);
        $delivery = new Delivery($queue, $this->fixed_now(), $this->fixed_uuid());

        $delivery->enqueue(5, 'invoice.paid');

        self::assertStringContainsString('INSERT INTO', $executed[0]);
        self::assertStringContainsString('5', $executed[0]);
        self::assertStringContainsString("'invoice.paid'", $executed[0]);
        self::assertStringContainsString("'uuid-1'", $executed[0]);
    }

    /**
     * @return void
     */
    public function test_process_due_does_nothing_when_not_ready(): void
    {
        $executed = [];
        $queue = $this->queue_with([['id_queue' => 1, 'id_facture' => 5, 'event' => 'invoice.paid', 'delivery_id' => 'uuid-1', 'attempt' => 0]], $executed);
        $delivery = new Delivery($queue, $this->fixed_now(), $this->fixed_uuid());
        $results = [];

        $delivery->process_due(
            ['url' => '', 'secret' => '', 'events' => [], 'active' => false],
            $this->context(),
            static fn (int $id_facture): array => [],
            static fn (int $id_facture): array => [],
            $this->never_called(),
            $this->record_result($results)
        );

        self::assertSame([], $results);
    }

    /**
     * @return void
     */
    public function test_process_due_drops_an_invoice_that_is_gone(): void
    {
        $executed = [];
        $queue = $this->queue_with([['id_queue' => 1, 'id_facture' => 5, 'event' => 'invoice.paid', 'delivery_id' => 'uuid-1', 'attempt' => 0]], $executed);
        $delivery = new Delivery($queue, $this->fixed_now(), $this->fixed_uuid());
        $results = [];

        $delivery->process_due(
            $this->settings(),
            $this->context(),
            static fn (int $id_facture): ?array => null,
            $this->never_called(),
            $this->never_called(),
            $this->record_result($results)
        );

        self::assertStringContainsString('DELETE FROM', $executed[0]);
        self::assertSame([[5, 'invoice.paid', 'uuid-1', Delivery::RESULT_GONE, 'invoice no longer exists', 0]], $results);
    }

    /**
     * @return void
     */
    public function test_process_due_delivers_successfully(): void
    {
        $executed = [];
        $queue = $this->queue_with([['id_queue' => 1, 'id_facture' => 5, 'event' => 'invoice.paid', 'delivery_id' => 'uuid-1', 'attempt' => 0]], $executed);
        $delivery = new Delivery($queue, $this->fixed_now(), $this->fixed_uuid());
        $results = [];

        $delivery->process_due(
            $this->settings(),
            $this->context(),
            static fn (int $id_facture): array => ['id' => $id_facture],
            static fn (int $id_facture): array => ['id' => 9],
            static fn (string $url, string $body, array $headers): array => [200, 'ok'],
            $this->record_result($results)
        );

        self::assertStringContainsString('DELETE FROM', $executed[0]);
        self::assertSame([[5, 'invoice.paid', 'uuid-1', Delivery::RESULT_DELIVERED, 'HTTP 200', 1]], $results);
    }

    /**
     * @return void
     */
    public function test_process_due_retries_a_retryable_failure(): void
    {
        $executed = [];
        $queue = $this->queue_with([['id_queue' => 1, 'id_facture' => 5, 'event' => 'invoice.paid', 'delivery_id' => 'uuid-1', 'attempt' => 0]], $executed);
        $delivery = new Delivery($queue, $this->fixed_now(), $this->fixed_uuid());
        $results = [];

        $delivery->process_due(
            $this->settings(),
            $this->context(),
            static fn (int $id_facture): array => [],
            static fn (int $id_facture): array => [],
            static fn (string $url, string $body, array $headers): array => [503, 'busy'],
            $this->record_result($results)
        );

        self::assertStringContainsString('UPDATE', $executed[0]);
        self::assertStringContainsString("'2026-01-01 10:01:00'", $executed[0]);
        self::assertSame([[5, 'invoice.paid', 'uuid-1', Delivery::RESULT_RETRY, 'HTTP 503: busy', 1]], $results);
    }

    /**
     * @return void
     */
    public function test_process_due_retries_a_network_error(): void
    {
        $executed = [];
        $queue = $this->queue_with([['id_queue' => 1, 'id_facture' => 5, 'event' => 'invoice.paid', 'delivery_id' => 'uuid-1', 'attempt' => 0]], $executed);
        $delivery = new Delivery($queue, $this->fixed_now(), $this->fixed_uuid());
        $results = [];

        $delivery->process_due(
            $this->settings(),
            $this->context(),
            static fn (int $id_facture): array => [],
            static fn (int $id_facture): array => [],
            static fn (string $url, string $body, array $headers): array => [-1, 'Connection timed out'],
            $this->record_result($results)
        );

        self::assertSame([[5, 'invoice.paid', 'uuid-1', Delivery::RESULT_RETRY, 'Connection timed out', 1]], $results);
    }

    /**
     * @return void
     */
    public function test_process_due_gives_up_after_the_last_retry(): void
    {
        $executed = [];
        $last_attempt = count(Delivery::RETRY_DELAYS);
        $queue = $this->queue_with([['id_queue' => 1, 'id_facture' => 5, 'event' => 'invoice.paid', 'delivery_id' => 'uuid-1', 'attempt' => $last_attempt]], $executed);
        $delivery = new Delivery($queue, $this->fixed_now(), $this->fixed_uuid());
        $results = [];

        $delivery->process_due(
            $this->settings(),
            $this->context(),
            static fn (int $id_facture): array => [],
            static fn (int $id_facture): array => [],
            static fn (string $url, string $body, array $headers): array => [500, 'still failing'],
            $this->record_result($results)
        );

        self::assertStringContainsString('DELETE FROM', $executed[0]);
        self::assertSame([[5, 'invoice.paid', 'uuid-1', Delivery::RESULT_GIVEN_UP, 'HTTP 500: still failing', $last_attempt + 1]], $results);
    }

    /**
     * @return void
     */
    public function test_process_due_rejects_a_client_error(): void
    {
        $executed = [];
        $queue = $this->queue_with([['id_queue' => 1, 'id_facture' => 5, 'event' => 'invoice.paid', 'delivery_id' => 'uuid-1', 'attempt' => 0]], $executed);
        $delivery = new Delivery($queue, $this->fixed_now(), $this->fixed_uuid());
        $results = [];

        $delivery->process_due(
            $this->settings(),
            $this->context(),
            static fn (int $id_facture): array => [],
            static fn (int $id_facture): array => [],
            static fn (string $url, string $body, array $headers): array => [422, 'bad signature'],
            $this->record_result($results)
        );

        self::assertStringContainsString('DELETE FROM', $executed[0]);
        self::assertSame([[5, 'invoice.paid', 'uuid-1', Delivery::RESULT_REJECTED, 'HTTP 422: bad signature', 1]], $results);
    }

    /**
     * @return void
     */
    public function test_process_due_rejects_with_no_body(): void
    {
        $executed = [];
        $queue = $this->queue_with([['id_queue' => 1, 'id_facture' => 5, 'event' => 'invoice.paid', 'delivery_id' => 'uuid-1', 'attempt' => 0]], $executed);
        $delivery = new Delivery($queue, $this->fixed_now(), $this->fixed_uuid());
        $results = [];

        $delivery->process_due(
            $this->settings(),
            $this->context(),
            static fn (int $id_facture): array => [],
            static fn (int $id_facture): array => [],
            static fn (string $url, string $body, array $headers): array => [401, '  '],
            $this->record_result($results)
        );

        self::assertSame([[5, 'invoice.paid', 'uuid-1', Delivery::RESULT_REJECTED, 'HTTP 401', 1]], $results);
    }

    /**
     * @return void
     */
    public function test_is_retryable(): void
    {
        self::assertTrue(Delivery::is_retryable(-1));
        self::assertTrue(Delivery::is_retryable(408));
        self::assertTrue(Delivery::is_retryable(429));
        self::assertTrue(Delivery::is_retryable(500));
        self::assertTrue(Delivery::is_retryable(503));
        self::assertFalse(Delivery::is_retryable(200));
        self::assertFalse(Delivery::is_retryable(401));
        self::assertFalse(Delivery::is_retryable(422));
    }

    /**
     * A callable that fails the test if it is ever called.
     *
     * @return callable
     */
    private function never_called(): callable
    {
        return function (): void {
            self::fail('Should not have been called.');
        };
    }
}
