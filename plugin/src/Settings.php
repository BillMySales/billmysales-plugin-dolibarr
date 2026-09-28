<?php

declare(strict_types=1);

/**
 * Delivery settings: endpoint URL, secret, events that notify.
 *
 * @package BillMySales\Dolibarr
 */

namespace BillMySales\Dolibarr;

/**
 * Reads, validates and shapes the delivery settings. Storage itself
 * (reading/writing the constants table) is left to the caller (the
 * module), so this class never touches Dolibarr's runtime directly and
 * stays unit-testable.
 */
final class Settings
{
    /**
     * Configuration key of the "active" switch.
     */
    public const KEY_ACTIVE = 'BILLMYSALES_ACTIVE';

    /**
     * Configuration key of the notification URL.
     */
    public const KEY_URL = 'BILLMYSALES_WEBHOOK';

    /**
     * Configuration key of the shared secret.
     */
    public const KEY_SECRET = 'BILLMYSALES_TOKEN';

    /**
     * Configuration key of the events that notify (JSON array of trigger
     * action codes, e.g. ["BILL_VALIDATE","BILL_PAYED"]).
     */
    public const KEY_EVENTS = 'BILLMYSALES_NOTIFY_EVENTS';

    /**
     * Trigger action codes an invoice can notify on: validated (the amount
     * and lines are final) and paid. Other invoice triggers (BILL_CREATE:
     * still a draft; BILL_MODIFY: fires too often to mean "ready to bill";
     * BILL_CANCEL, BILL_UNVALIDATE, BILL_UNPAYED, BILL_DELETE: undo a
     * previous state, not a new one to bill) are never offered.
     */
    public const EVENTS = ['BILL_VALIDATE', 'BILL_PAYED'];

    /**
     * Default values.
     *
     * @var array{url: string, secret: string, events: string[], active: bool}
     */
    public const DEFAULTS = [
        'url' => '',
        'secret' => '',
        'events' => [],
        'active' => false,
    ];

    /**
     * Builds the settings array from raw Configuration values.
     *
     * @param string|false $url    Raw BILLMYSALES_WEBHOOK value.
     * @param string|false $secret Raw BILLMYSALES_TOKEN value.
     * @param string|false $events Raw BILLMYSALES_NOTIFY_EVENTS value (JSON).
     * @param string|false $active Raw BILLMYSALES_ACTIVE value.
     * @return array{url: string, secret: string, events: string[], active: bool}
     */
    public static function from_raw($url, $secret, $events, $active): array
    {
        $decoded = json_decode(is_string($events) ? $events : '', true);
        $valid = is_array($decoded) ? array_intersect(array_map('strval', $decoded), self::EVENTS) : [];
        return [
            'url' => is_string($url) ? $url : self::DEFAULTS['url'],
            'secret' => is_string($secret) ? $secret : self::DEFAULTS['secret'],
            'events' => array_values($valid),
            'active' => !empty($active),
        ];
    }

    /**
     * Whether deliveries are enabled and fully configured.
     *
     * @param array{url: string, secret: string, events: string[], active: bool} $settings Settings.
     * @return bool
     */
    public static function is_ready(array $settings): bool
    {
        return $settings['active'] && '' !== $settings['url'] && '' !== $settings['secret'];
    }

    /**
     * Whether a trigger action code notifies, given the settings.
     *
     * @param array{url: string, secret: string, events: string[], active: bool} $settings Settings.
     * @param string                                                             $action   Trigger action code (e.g. "BILL_PAYED").
     * @return bool
     */
    public static function notifies(array $settings, string $action): bool
    {
        return self::is_ready($settings) && in_array($action, $settings['events'], true);
    }

    /**
     * Validates the settings form.
     *
     * @param array<string, mixed> $input Raw form data.
     * @return array{url: string, secret: string, events: string[], active: bool}
     */
    public static function sanitize(array $input): array
    {
        $url = isset($input[self::KEY_URL]) ? trim((string) $input[self::KEY_URL]) : '';
        $secret = isset($input[self::KEY_SECRET]) ? (string) $input[self::KEY_SECRET] : '';

        $events = [];
        foreach (self::EVENTS as $action) {
            if (!empty($input[self::KEY_EVENTS . '_' . $action])) {
                $events[] = $action;
            }
        }

        return [
            'url' => $url,
            'secret' => $secret,
            'events' => $events,
            'active' => !empty($input[self::KEY_ACTIVE]),
        ];
    }
}
