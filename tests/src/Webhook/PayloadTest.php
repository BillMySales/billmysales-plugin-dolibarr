<?php

declare(strict_types=1);

/**
 * Tests for Payload.
 *
 * @package BillMySales\TestsDolibarr
 */

namespace BillMySales\TestsDolibarr\Webhook;

use BillMySales\Dolibarr\Webhook\Payload;
use PHPUnit\Framework\TestCase;

/**
 * @covers \BillMySales\Dolibarr\Webhook\Payload
 */
final class PayloadTest extends TestCase
{
    /**
     * @return void
     */
    public function test_build(): void
    {
        $facture = ['id' => 1, 'ref' => 'FA2601-0001'];
        $societe = ['id' => 2, 'nom' => 'Acme'];

        self::assertSame([
            'facture' => $facture,
            'societe' => $societe,
        ], Payload::build($facture, $societe));
    }

    /**
     * @return void
     */
    public function test_build_strips_the_database_handle_at_every_level(): void
    {
        $line = new \stdClass();
        $line->db = 'a database handle';
        $line->total_ht = 100;

        $facture = [
            'id' => 1,
            'db' => 'a database handle',
            'lines' => [$line],
        ];
        $societe = ['id' => 2, 'nom' => 'Acme'];

        self::assertSame([
            'facture' => [
                'id' => 1,
                'lines' => [
                    ['total_ht' => 100],
                ],
            ],
            'societe' => $societe,
        ], Payload::build($facture, $societe));
    }
}
