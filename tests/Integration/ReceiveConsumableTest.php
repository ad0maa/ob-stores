<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Service\ReceiveConsumable;
use App\Service\ValidationError;
use PDOException;
use Tests\DatabaseTestCase;

final class ReceiveConsumableTest extends DatabaseTestCase
{
    private int $batteries;

    protected function setUp(): void
    {
        parent::setUp();
        $this->batteries = $this->insert('consumables', ['code' => 'AA', 'name' => 'AA battery', 'unit' => 'each', 'reorder_point' => 40]);
    }

    public function testReceiptCreatesALotAndOneLedgerRow(): void
    {
        $lotId = new ReceiveConsumable($this->db)->receive($this->batteries, 'BX-1001', 48, '2026-09-01');

        $movement = $this->db->query('SELECT type, qty, lot_id FROM movements')->fetch();
        self::assertSame(['type' => 'receipt', 'qty' => 48, 'lot_id' => $lotId], $movement);
        self::assertSame(48, (int) $this->db->query("SELECT on_hand FROM lot_balances WHERE lot_id = {$lotId}")->fetchColumn());
    }

    public function testDuplicateLotCodeIsRejectedAndWritesNothing(): void
    {
        $receiving = new ReceiveConsumable($this->db);
        $receiving->receive($this->batteries, 'BX-1001', 48, '2026-09-01');

        try {
            $receiving->receive($this->batteries, 'BX-1001', 12, '2026-09-02');
            self::fail('Expected ValidationError');
        } catch (ValidationError $e) {
            self::assertArrayHasKey('lot_code', $e->errors);
        }
        self::assertSame(1, $this->rowCount('lots'));
        self::assertSame(1, $this->rowCount('movements'));
    }

    public function testInvalidInputIsRejectedPerField(): void
    {
        try {
            new ReceiveConsumable($this->db)->receive($this->batteries, ' ', 0, '2026-02-30');
            self::fail('Expected ValidationError');
        } catch (ValidationError $e) {
            self::assertSame(['lot_code', 'qty', 'received_on'], array_keys($e->errors));
        }
        self::assertSame(0, $this->rowCount('movements'));
    }

    public function testLedgerRowsCannotBeEdited(): void
    {
        new ReceiveConsumable($this->db)->receive($this->batteries, 'BX-1001', 48, '2026-09-01');

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('append-only');
        $this->db->exec('UPDATE movements SET qty = 480');
    }
}
