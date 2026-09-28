<?php

declare(strict_types=1);

/**
 * Tests for Headers.
 *
 * @package BillMySales\TestsDolibarr
 */

namespace BillMySales\TestsDolibarr\Webhook;

use BillMySales\Dolibarr\Webhook\Headers;
use PHPUnit\Framework\TestCase;

/**
 * @covers \BillMySales\Dolibarr\Webhook\Headers
 */
final class HeadersTest extends TestCase
{
    /**
     * @return void
     */
    public function test_signature(): void
    {
        $signature = Headers::signature('body', 'secret');

        self::assertSame(base64_encode(hash_hmac('sha256', 'body', 'secret', true)), $signature);
    }

    /**
     * @return void
     */
    public function test_build(): void
    {
        $headers = Headers::build('body', 'secret', 'invoice.paid', 'delivery-1', 'https://example.com', '24.0.1', '2.0.0');

        $signature = Headers::signature('body', 'secret');
        self::assertSame([
            'Content-Type' => 'application/json; charset=utf-8',
            'User-Agent' => 'BillMySales-dolibarr/2.0.0',
            'X-BillMySales-Signature' => $signature,
            'X-BillMySales-Platform' => 'dolibarr',
            'X-BillMySales-Platform-Version' => '24.0.1',
            'X-BillMySales-Plugin-Version' => '2.0.0',
            'X-BillMySales-Source' => 'https://example.com',
            'X-BillMySales-Event' => 'invoice.paid',
            'X-BillMySales-Delivery' => 'delivery-1',
            'X-DolibarrBMS-Hmac-Sha256' => $signature,
        ], $headers);
    }
}
