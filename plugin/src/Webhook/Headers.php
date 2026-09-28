<?php

declare(strict_types=1);

/**
 * HTTP headers of a delivery.
 *
 * @package BillMySales\Dolibarr
 */

namespace BillMySales\Dolibarr\Webhook;

/**
 * Builds the headers of a delivery: the standard BillMySales headers plus
 * the one BillMySales' Dolibarr datasource reads today (the signature
 * header the module already sent).
 */
final class Headers
{
    /**
     * Platform code sent in the X-BillMySales-Platform header.
     */
    public const PLATFORM = 'dolibarr';

    /**
     * Signature of a body: base64 of its HMAC-SHA256 with the secret.
     *
     * @param string $body   Request body.
     * @param string $secret Shared secret.
     * @return string
     */
    public static function signature(string $body, string $secret): string
    {
        return base64_encode(hash_hmac('sha256', $body, $secret, true));
    }

    /**
     * Headers of a delivery.
     *
     * @param string $body             Request body.
     * @param string $secret           Shared secret.
     * @param string $event            Event, e.g. "invoice.paid".
     * @param string $delivery_id      UUID of the notification (the same on retries).
     * @param string $source           Instance URL.
     * @param string $platform_version Dolibarr version.
     * @param string $plugin_version   Module version.
     * @return array<string, string>
     */
    public static function build(
        string $body,
        string $secret,
        string $event,
        string $delivery_id,
        string $source,
        string $platform_version,
        string $plugin_version
    ): array {
        $signature = self::signature($body, $secret);
        return [
            'Content-Type' => 'application/json; charset=utf-8',
            'User-Agent' => 'BillMySales-' . self::PLATFORM . '/' . $plugin_version,
            'X-BillMySales-Signature' => $signature,
            'X-BillMySales-Platform' => self::PLATFORM,
            'X-BillMySales-Platform-Version' => $platform_version,
            'X-BillMySales-Plugin-Version' => $plugin_version,
            'X-BillMySales-Source' => $source,
            'X-BillMySales-Event' => $event,
            'X-BillMySales-Delivery' => $delivery_id,
            // Read by BillMySales' Dolibarr datasource (the module's own,
            // original signature header).
            'X-DolibarrBMS-Hmac-Sha256' => $signature,
        ];
    }
}
