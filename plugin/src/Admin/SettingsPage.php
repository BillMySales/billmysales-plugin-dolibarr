<?php

declare(strict_types=1);

/**
 * The settings page (Home > Setup > Modules > BillMySales > Settings).
 *
 * @package BillMySales\Dolibarr
 */

namespace BillMySales\Dolibarr\Admin;

use BillMySales\Dolibarr\Settings;

/**
 * Builds the settings form's field definitions from the current settings,
 * so the admin page only has to render them (Dolibarr has no HTML form
 * builder class to hand this to, unlike its own object cards).
 */
final class SettingsPage
{
    /**
     * The form's fields, in the order they are shown.
     *
     * @param callable                                                              $translate Translates a label (Dolibarr's $langs->trans()).
     * @param array{url: string, secret: string, events: string[], active: bool}     $settings  Current settings.
     * @return array<int, array{key: string, label: string, tooltip: string, type: string, value: mixed}>
     */
    public static function fields(callable $translate, array $settings): array
    {
        $fields = [
            [
                'key' => Settings::KEY_ACTIVE,
                'label' => $translate('BillMySalesActive'),
                'tooltip' => $translate('BillMySalesActiveTooltip'),
                'type' => 'yesno',
                'value' => $settings['active'],
            ],
            [
                'key' => Settings::KEY_URL,
                'label' => $translate('BillMySalesWebhook'),
                'tooltip' => $translate('BillMySalesWebhookTooltip'),
                'type' => 'string',
                'value' => $settings['url'],
            ],
            [
                'key' => Settings::KEY_SECRET,
                'label' => $translate('BillMySalesToken'),
                'tooltip' => $translate('BillMySalesTokenTooltip'),
                'type' => 'securekey',
                'value' => $settings['secret'],
            ],
        ];
        foreach (Settings::EVENTS as $action) {
            $fields[] = [
                'key' => Settings::KEY_EVENTS . '_' . $action,
                'label' => $translate('BillMySalesEvent' . $action),
                'tooltip' => '',
                'type' => 'yesno',
                'value' => in_array($action, $settings['events'], true),
            ];
        }
        return $fields;
    }
}
