<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\PlanLine;
use App\Service\RecipeExploder;
use App\Service\ValidationError;
use Tests\DatabaseTestCase;

/**
 * Fixture: "Test box" =
 *   1 codec, 2 gaffer rolls                       (fixed)
 *   + per position: 1 × commentary position        (sub-kit)
 *       1 headset, 1 lip mic, 2 XLR
 *       + 2 × battery pack                         (sub-sub-kit)
 *           2 AA
 */
final class RecipeExploderTest extends DatabaseTestCase
{
    private int $templateId;

    protected function setUp(): void
    {
        parent::setUp();

        $codec = $this->gearType('CODEC');
        $headset = $this->gearType('HEADSET');
        $lipMic = $this->gearType('LIP');
        $xlr = $this->consumable('XLR');
        $tape = $this->consumable('TAPE');
        $aa = $this->consumable('AA');

        $batteryPack = $this->template('BATT-PACK', true);
        $this->line($batteryPack, 2, consumable: $aa);

        $position = $this->template('POSITION', true);
        $this->line($position, 1, gearType: $headset);
        $this->line($position, 1, gearType: $lipMic);
        $this->line($position, 2, consumable: $xlr);
        $this->line($position, 2, child: $batteryPack);

        $this->templateId = $this->template('TEST-BOX', false);
        $this->line($this->templateId, 1, gearType: $codec);
        $this->line($this->templateId, 2, consumable: $tape);
        $this->line($this->templateId, 1, child: $position, perPosition: true);

        // Stock: 2 codecs, 2 headsets (one of them out), 10 AA across two lots, no lip mics.
        $this->gearItems($codec, 2);
        [$headsetIn, $headsetOut] = $this->gearItems($headset, 2);
        $this->insert('movements', ['type' => 'checkout', 'qty' => -1, 'gear_item_id' => $headsetOut]);
        $this->lot($aa, 'AA-1', 6);
        $this->lot($aa, 'AA-2', 4);
        $this->lot($tape, 'T-1', 5);
        $this->lot($xlr, 'X-1', 20);
    }

    public function testThreePositionsScaleOnlyThePerPositionBranch(): void
    {
        $plan = $this->byCode(new RecipeExploder($this->db)->explode($this->templateId, 3));

        // code => [required, available, shortfall]
        self::assertSame([
            'AA' => [12, 10, 2],       // 3 positions × 2 packs × 2 AA
            'CODEC' => [1, 2, 0],      // fixed line: not multiplied
            'HEADSET' => [3, 1, 2],    // one of the two is checked out
            'LIP' => [3, 0, 3],
            'TAPE' => [2, 5, 0],
            'XLR' => [6, 20, 0],
        ], $plan);
    }

    public function testOnePosition(): void
    {
        $plan = $this->byCode(new RecipeExploder($this->db)->explode($this->templateId, 1));

        self::assertSame([1, 4], [$plan['CODEC'][0], $plan['AA'][0]]);
        self::assertSame([1, 1, 0], $plan['HEADSET']);
    }

    public function testGearIsListedBeforeConsumables(): void
    {
        $kinds = array_map(fn (PlanLine $line): string => $line->kind, new RecipeExploder($this->db)->explode($this->templateId, 1));

        self::assertSame(['gear', 'gear', 'gear', 'consumable', 'consumable', 'consumable'], $kinds);
    }

    public function testPositionsMustBeInRange(): void
    {
        $this->expectException(ValidationError::class);
        new RecipeExploder($this->db)->explode($this->templateId, 0);
    }

    /** @param list<PlanLine> $plan @return array<string, array{int, int, int}> */
    private function byCode(array $plan): array
    {
        $rows = [];
        foreach ($plan as $line) {
            $rows[$line->code] = [$line->required, $line->available, $line->shortfall];
        }
        ksort($rows);

        return $rows;
    }

    private function gearType(string $code): int
    {
        return $this->insert('gear_types', ['code' => $code, 'name' => $code, 'category' => 'Test']);
    }

    private function consumable(string $code): int
    {
        return $this->insert('consumables', ['code' => $code, 'name' => $code, 'unit' => 'each', 'reorder_point' => 0]);
    }

    private function template(string $code, bool $isSubkit): int
    {
        return $this->insert('kit_templates', ['code' => $code, 'name' => $code, 'is_subkit' => (int) $isSubkit]);
    }

    private function line(int $template, int $qty, ?int $gearType = null, ?int $consumable = null, ?int $child = null, bool $perPosition = false): void
    {
        $this->insert('template_lines', [
            'template_id' => $template, 'qty' => $qty, 'per_position' => (int) $perPosition,
            'gear_type_id' => $gearType, 'consumable_id' => $consumable, 'child_template_id' => $child,
        ]);
    }

    /** @return list<int> */
    private function gearItems(int $gearType, int $count): array
    {
        $ids = [];
        for ($i = 1; $i <= $count; $i++) {
            $ids[] = $id = $this->insert('gear_items', ['gear_type_id' => $gearType, 'serial' => "{$gearType}-{$i}", 'acquired_on' => '2026-01-01']);
            $this->insert('movements', ['type' => 'receipt', 'qty' => 1, 'gear_item_id' => $id]);
        }

        return $ids;
    }

    private function lot(int $consumable, string $code, int $qty): void
    {
        $lot = $this->insert('lots', ['consumable_id' => $consumable, 'lot_code' => $code, 'received_on' => '2026-01-01']);
        $this->insert('movements', ['type' => 'receipt', 'qty' => $qty, 'lot_id' => $lot]);
    }
}
