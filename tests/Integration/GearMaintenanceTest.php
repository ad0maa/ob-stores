<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Service\GearMaintenance;
use App\Service\PackJob;
use App\Service\ValidationError;
use Tests\DatabaseTestCase;

final class GearMaintenanceTest extends DatabaseTestCase
{
    private int $template;
    private int $codec;

    protected function setUp(): void
    {
        parent::setUp();

        $type = $this->insert('gear_types', ['code' => 'CODEC', 'name' => 'Codec', 'category' => 'Test']);
        $this->codec = $this->insert('gear_items', ['gear_type_id' => $type, 'serial' => 'CDC-1', 'acquired_on' => '2026-01-01']);
        $this->insert('movements', ['type' => 'receipt', 'qty' => 1, 'gear_item_id' => $this->codec]);

        $this->template = $this->insert('kit_templates', ['code' => 'ONE', 'name' => 'One codec', 'is_subkit' => 0]);
        $this->insert('template_lines', ['template_id' => $this->template, 'qty' => 1, 'per_position' => 0, 'gear_type_id' => $type]);
    }

    public function testAFaultFoundOnTheShelfTakesTheItemOutOfThePool(): void
    {
        new GearMaintenance($this->db)->reportFault($this->codec, 'Hangs on boot');

        self::assertSame(['faulty', 'Hangs on boot'], $this->latest());
        self::assertSame('faulty', $this->codecStatus());
    }

    public function testARepairedItemIsAvailableAndCanBePackedAgain(): void
    {
        $maintenance = new GearMaintenance($this->db);
        $maintenance->reportFault($this->codec, 'Hangs on boot');
        $maintenance->markRepaired($this->codec, 'Reflashed firmware');

        self::assertSame(['repaired', 'Reflashed firmware'], $this->latest());
        self::assertSame('available', $this->codecStatus());

        $jobId = new PackJob($this->db)->pack($this->template, 1, 'After repair', '2026-09-27');
        self::assertSame($jobId, (int) $this->db->query("SELECT job_id FROM gear_item_status WHERE gear_item_id = {$this->codec}")->fetchColumn());
    }

    public function testOnlyAFaultyItemCanBeMarkedRepaired(): void
    {
        $this->expectRejected(fn () => new GearMaintenance($this->db)->markRepaired($this->codec, ''));
    }

    public function testAFaultCannotBeReportedOnAnItemThatIsOutOnAJob(): void
    {
        new PackJob($this->db)->pack($this->template, 1, 'Out', '2026-09-27');

        $this->expectRejected(fn () => new GearMaintenance($this->db)->reportFault($this->codec, 'Crackle'));
    }

    public function testAFaultNeedsANote(): void
    {
        $this->expectRejected(fn () => new GearMaintenance($this->db)->reportFault($this->codec, '   '));
    }

    private function expectRejected(callable $action): void
    {
        $before = $this->rowCount('movements');
        try {
            $action();
            self::fail('Expected ValidationError');
        } catch (ValidationError) {
            self::assertSame($before, $this->rowCount('movements'));
        }
    }

    /** @return array{string, ?string} type and note of the codec's latest movement */
    private function latest(): array
    {
        return $this->db->query("SELECT type, note FROM movements WHERE gear_item_id = {$this->codec} ORDER BY id DESC LIMIT 1")->fetch(\PDO::FETCH_NUM);
    }

    private function codecStatus(): string
    {
        return (string) $this->db->query("SELECT status FROM gear_item_status WHERE gear_item_id = {$this->codec}")->fetchColumn();
    }
}
