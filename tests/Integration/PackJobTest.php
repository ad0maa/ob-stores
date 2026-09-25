<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Service\InsufficientStock;
use App\Service\PackJob;
use App\Service\ValidationError;
use Tests\DatabaseTestCase;

/**
 * Fixture: template = 2 codecs (fixed) + 7 AA per position.
 * Stock: 3 codecs; AA in an older lot of 5 and a newer lot of 10.
 */
final class PackJobTest extends DatabaseTestCase
{
    private int $templateId;
    private int $olderLot;
    private int $newerLot;

    /** @var list<int> */
    private array $codecs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $codecType = $this->insert('gear_types', ['code' => 'CODEC', 'name' => 'Codec', 'category' => 'Test']);
        foreach ([1, 2, 3] as $n) {
            $this->codecs[] = $id = $this->insert('gear_items', ['gear_type_id' => $codecType, 'serial' => "CDC-{$n}", 'acquired_on' => '2026-01-01']);
            $this->insert('movements', ['type' => 'receipt', 'qty' => 1, 'gear_item_id' => $id]);
        }

        $aa = $this->insert('consumables', ['code' => 'AA', 'name' => 'AA', 'unit' => 'each', 'reorder_point' => 0]);
        $this->olderLot = $this->insert('lots', ['consumable_id' => $aa, 'lot_code' => 'OLD', 'received_on' => '2026-01-01']);
        $this->newerLot = $this->insert('lots', ['consumable_id' => $aa, 'lot_code' => 'NEW', 'received_on' => '2026-02-01']);
        $this->insert('movements', ['type' => 'receipt', 'qty' => 10, 'lot_id' => $this->newerLot]);
        $this->insert('movements', ['type' => 'receipt', 'qty' => 5, 'lot_id' => $this->olderLot]);

        $this->templateId = $this->insert('kit_templates', ['code' => 'BOX', 'name' => 'Box', 'is_subkit' => 0]);
        $this->insert('template_lines', ['template_id' => $this->templateId, 'qty' => 2, 'per_position' => 0, 'gear_type_id' => $codecType]);
        $this->insert('template_lines', ['template_id' => $this->templateId, 'qty' => 7, 'per_position' => 1, 'consumable_id' => $aa]);
    }

    public function testPackChecksOutSpecificItemsAndConsumesOldestLotFirst(): void
    {
        $jobId = new PackJob($this->db)->pack($this->templateId, 1, 'Cup replay', '2026-09-27');

        $out = $this->db->query("SELECT gear_item_id FROM gear_item_status WHERE status = 'out' AND job_id = {$jobId} ORDER BY gear_item_id")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame([$this->codecs[0], $this->codecs[1]], $out);

        $consumed = $this->db->query("SELECT lot_id, qty FROM movements WHERE type = 'consume' AND job_id = {$jobId} ORDER BY id")->fetchAll(\PDO::FETCH_KEY_PAIR);
        self::assertSame([$this->olderLot => -5, $this->newerLot => -2], $consumed);
    }

    public function testShortPackThrowsEveryShortfallAndWritesNothing(): void
    {
        new PackJob($this->db)->pack($this->templateId, 1, 'First', '2026-09-27'); // takes 2 of 3 codecs, 7 of 15 AA
        $movementsBefore = $this->rowCount('movements');

        try {
            new PackJob($this->db)->pack($this->templateId, 2, 'Second', '2026-09-28'); // needs 2 codecs, 14 AA
            self::fail('Expected InsufficientStock');
        } catch (InsufficientStock $e) {
            $short = [];
            foreach ($e->shortfalls as $line) {
                $short[$line->code] = [$line->required, $line->available];
            }
            self::assertSame(['CODEC' => [2, 1], 'AA' => [14, 8]], $short);
        }

        self::assertSame(1, $this->rowCount('jobs'));
        self::assertSame($movementsBefore, $this->rowCount('movements'));
    }

    public function testAnItemAlreadyOutIsNeverPackedAgain(): void
    {
        $first = new PackJob($this->db)->pack($this->templateId, 1, 'First', '2026-09-27');

        $this->expectException(InsufficientStock::class);
        try {
            new PackJob($this->db)->pack($this->templateId, 1, 'Second', '2026-09-27');
        } finally {
            $stillOnFirst = (int) $this->db->query("SELECT COUNT(*) FROM gear_item_status WHERE job_id = {$first}")->fetchColumn();
            self::assertSame(2, $stillOnFirst);
        }
    }

    public function testInputIsValidated(): void
    {
        try {
            new PackJob($this->db)->pack(999, 1, '  ', '27/09/2026');
            self::fail('Expected ValidationError');
        } catch (ValidationError $e) {
            self::assertSame(['template_id', 'name', 'job_date'], array_keys($e->errors));
        }
        self::assertSame(0, $this->rowCount('jobs'));
    }
}
