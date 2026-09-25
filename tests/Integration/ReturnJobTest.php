<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Service\PackJob;
use App\Service\ReturnJob;
use App\Service\ValidationError;
use PDO;
use Tests\DatabaseTestCase;

final class ReturnJobTest extends DatabaseTestCase
{
    private int $jobId;

    /** @var list<int> */
    private array $mics = [];

    protected function setUp(): void
    {
        parent::setUp();

        $micType = $this->insert('gear_types', ['code' => 'MIC', 'name' => 'Mic', 'category' => 'Test']);
        foreach ([1, 2, 3] as $n) {
            $this->mics[] = $id = $this->insert('gear_items', ['gear_type_id' => $micType, 'serial' => "MIC-{$n}", 'acquired_on' => '2026-01-01']);
            $this->insert('movements', ['type' => 'receipt', 'qty' => 1, 'gear_item_id' => $id]);
        }
        $template = $this->insert('kit_templates', ['code' => 'PAIR', 'name' => 'Pair of mics', 'is_subkit' => 0]);
        $this->insert('template_lines', ['template_id' => $template, 'qty' => 1, 'per_position' => 1, 'gear_type_id' => $micType]);

        $this->jobId = new PackJob($this->db)->pack($template, 2, 'Interview', '2026-09-27');
    }

    public function testReturnBringsGearBackAndFaultyItemsLeaveThePool(): void
    {
        new ReturnJob($this->db)->return($this->jobId, [$this->mics[1] => 'Crackles when moved']);

        $status = $this->db->query('SELECT gear_item_id, status FROM gear_item_status ORDER BY gear_item_id')->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertSame([$this->mics[0] => 'available', $this->mics[1] => 'faulty', $this->mics[2] => 'available'], $status);

        $faultyRow = $this->db->query("SELECT qty, note FROM movements WHERE type = 'faulty'")->fetch();
        self::assertSame(['qty' => 0, 'note' => 'Crackles when moved'], $faultyRow);
        self::assertNotNull($this->db->query("SELECT returned_at FROM jobs WHERE id = {$this->jobId}")->fetchColumn());
    }

    public function testAJobCanOnlyBeReturnedOnce(): void
    {
        $returning = new ReturnJob($this->db);
        $returning->return($this->jobId);
        $movements = $this->rowCount('movements');

        $this->expectException(ValidationError::class);
        try {
            $returning->return($this->jobId);
        } finally {
            self::assertSame($movements, $this->rowCount('movements'));
        }
    }

    public function testFlaggingAnItemThatWasNotOnTheJobWritesNothing(): void
    {
        $movements = $this->rowCount('movements');

        try {
            new ReturnJob($this->db)->return($this->jobId, [$this->mics[2] => 'Not on this job']);
            self::fail('Expected ValidationError');
        } catch (ValidationError $e) {
            self::assertArrayHasKey('faulty', $e->errors);
        }
        self::assertSame($movements, $this->rowCount('movements'));
        self::assertNull($this->db->query("SELECT returned_at FROM jobs WHERE id = {$this->jobId}")->fetchColumn());
    }
}
