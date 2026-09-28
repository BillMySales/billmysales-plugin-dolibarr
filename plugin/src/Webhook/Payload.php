<?php

declare(strict_types=1);

/**
 * The invoice payload sent to BillMySales.
 *
 * @package BillMySales\Dolibarr
 */

namespace BillMySales\Dolibarr\Webhook;

/**
 * Builds the payload: the plain data of the invoice and its customer, as
 * assembled by the module from Facture and Societe (the shape BillMySales'
 * Dolibarr datasource has parsed since the module's first version). Neither
 * class declares a password, token or API key field (checked in Dolibarr's
 * own source): the only property worth stripping is the database handle
 * every Dolibarr object (and each of its nested objects, e.g. an invoice's
 * lines) carries as a public "db" property, which exposes the database
 * host, user and port, and the object's last raw SQL query.
 */
final class Payload
{
    /**
     * Builds the payload of an invoice.
     *
     * @param array<string, mixed> $facture Plain invoice data, as assembled by the module.
     * @param array<string, mixed> $societe Plain customer (thirdparty) data, as assembled by the module.
     * @return array<string, mixed>
     */
    public static function build(array $facture, array $societe): array
    {
        return [
            'facture' => self::sanitize($facture),
            'societe' => self::sanitize($societe),
        ];
    }

    /**
     * Recursively removes every "db" property/key (an object's database
     * handle), including inside nested objects (still plain PHP objects at
     * this point: get_object_vars() doesn't convert them) and arrays (e.g.
     * an invoice's lines).
     *
     * @param mixed $value A value from the invoice or customer data.
     * @return mixed
     */
    private static function sanitize($value)
    {
        if (is_object($value)) {
            $value = get_object_vars($value);
        }
        if (is_array($value)) {
            unset($value['db']);
            foreach ($value as $key => $item) {
                $value[$key] = self::sanitize($item);
            }
        }
        return $value;
    }
}
