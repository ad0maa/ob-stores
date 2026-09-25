<?php

declare(strict_types=1);

namespace App\Seed;

use App\Service\InsufficientStock;
use App\Service\PackJob;
use App\Service\ReceiveConsumable;
use App\Service\ReturnJob;
use DateTimeImmutable;
use PDO;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Deterministic demo data. A seeded Mt19937 engine means every run produces
 * the same serials, lots and history, so measured numbers are repeatable.
 */
final class DemoSeeder
{
    private readonly Randomizer $random;

    /** @var array<string, int> code => id */
    private array $gearTypeIds = [];

    /** @var array<string, int> code => id */
    private array $consumableIds = [];

    /** @param array<string, array<string, array<mixed>>> $catalogue */
    public function __construct(
        private readonly PDO $db,
        private readonly array $catalogue,
        private readonly DateTimeImmutable $historyStart,
    ) {
        $this->random = new Randomizer(new Mt19937(1976));
    }

    public function catalogue(): void
    {
        $insertType = $this->db->prepare('INSERT INTO gear_types (code, name, category) VALUES (?, ?, ?)');
        $insertItem = $this->db->prepare('INSERT INTO gear_items (gear_type_id, serial, acquired_on) VALUES (?, ?, ?)');
        $receiveItem = $this->db->prepare("INSERT INTO movements (type, qty, gear_item_id, created_at) VALUES ('receipt', 1, ?, ?)");

        $this->db->beginTransaction();
        foreach ($this->catalogue['gear'] as $code => [$name, $category, $prefix, $count]) {
            $insertType->execute([$code, $name, $category]);
            $typeId = $this->gearTypeIds[$code] = (int) $this->db->lastInsertId();

            for ($i = 1; $i <= $count; $i++) {
                $acquired = $this->historyStart->modify('-' . $this->random->getInt(30, 900) . ' days');
                $insertItem->execute([$typeId, sprintf('%s-%04d', $prefix, $i * 7 + $this->random->getInt(0, 6)), $acquired->format('Y-m-d')]);
                $receiveItem->execute([(int) $this->db->lastInsertId(), $acquired->format('Y-m-d 09:00:00')]);
            }
        }

        $insertConsumable = $this->db->prepare('INSERT INTO consumables (code, name, unit, reorder_point) VALUES (?, ?, ?, ?)');
        foreach ($this->catalogue['consumables'] as $code => [$name, $unit, $reorderPoint]) {
            $insertConsumable->execute([$code, $name, $unit, $reorderPoint]);
            $this->consumableIds[$code] = (int) $this->db->lastInsertId();
        }

        $insertTemplate = $this->db->prepare('INSERT INTO kit_templates (code, name, description, is_subkit) VALUES (?, ?, ?, ?)');
        $insertLine = $this->db->prepare(
            'INSERT INTO template_lines (template_id, qty, per_position, gear_type_id, consumable_id, child_template_id) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $templateIds = [];
        foreach ($this->catalogue['templates'] as $code => [$name, $description, $isSubkit, $lines]) {
            $insertTemplate->execute([$code, $name, $description, (int) $isSubkit]);
            $templateId = $templateIds[$code] = (int) $this->db->lastInsertId();

            foreach ($lines as [$target, $qty, $perPosition]) {
                [$kind, $targetCode] = explode(':', $target);
                $insertLine->execute([
                    $templateId,
                    $qty,
                    (int) $perPosition,
                    $kind === 'gear' ? $this->gearTypeIds[$targetCode] : null,
                    $kind === 'item' ? $this->consumableIds[$targetCode] : null,
                    $kind === 'kit' ? $templateIds[$targetCode] : null,
                ]);
            }
        }
        $this->db->commit();
    }

    /** Opening stock: three deliveries of every consumable in the month before history starts. */
    public function openingStock(): void
    {
        foreach ($this->catalogue['consumables'] as $code => $consumable) {
            foreach ([28, 18, 7] as $daysBefore) {
                $this->deliver($code, $consumable, $this->historyStart->modify("-{$daysBefore} days"));
            }
        }
    }

    /**
     * Simulate day-by-day use from historyStart to $until through the real
     * PackJob and ReturnJob services, so every seeded ledger row went through
     * the same locked transaction a user's pack would.
     *
     * @return array{packed: int, skipped: int, faulty: int, deliveries: int}
     */
    public function history(DateTimeImmutable $until): array
    {
        $packJob = new PackJob($this->db);
        $returnJob = new ReturnJob($this->db);
        $templates = $this->db->query('SELECT code, id FROM kit_templates WHERE NOT is_subkit')->fetchAll(PDO::FETCH_KEY_PAIR);
        $stats = ['packed' => 0, 'skipped' => 0, 'faulty' => 0, 'deliveries' => 0];
        /** @var array<int, DateTimeImmutable> $dueBack job id => return date */
        $dueBack = [];
        $round = 0;

        for ($day = $this->historyStart; $day < $until; $day = $day->modify('+1 day')) {
            // Evening returns from earlier jobs; a handful of items come back faulty.
            foreach ($dueBack as $jobId => $returnOn) {
                if ($returnOn > $day) {
                    continue;
                }
                $faulty = [];
                foreach ($this->db->query("SELECT gear_item_id FROM movements WHERE job_id = {$jobId} AND type = 'checkout'")->fetchAll(PDO::FETCH_COLUMN) as $itemId) {
                    if ($this->random->getInt(1, 700) === 1) {
                        $faulty[$itemId] = self::FAULTS[$this->random->getInt(0, count(self::FAULTS) - 1)];
                    }
                }
                $returnJob->return($jobId, $faulty, $day->setTime(18, $this->random->getInt(0, 59)));
                $stats['faulty'] += count($faulty);
                unset($dueBack[$jobId]);
            }

            // Morning deliveries for anything below its reorder point.
            foreach ($this->belowReorder() as $code) {
                $this->deliver($code, $this->catalogue['consumables'][$code], $day);
                $stats['deliveries']++;
            }

            // Busier at weekends, when most sport happens.
            $isWeekend = (int) $day->format('N') >= 6;
            $jobsToday = $isWeekend ? $this->random->getInt(1, 3) : $this->random->getInt(0, 2);
            if ($day->format('N') === '6') {
                $round++;
            }
            for ($n = 0; $n < $jobsToday; $n++) {
                [$code, $positions, $name] = $this->pickJob($day, $round, $n);
                try {
                    $jobId = $packJob->pack($templates[$code], $positions, $name, $day->format('Y-m-d'), $day->setTime(7 + $n, $this->random->getInt(0, 59)));
                } catch (InsufficientStock) {
                    $stats['skipped']++;
                    continue;
                }
                $dueBack[$jobId] = $day->modify('+' . $this->random->getInt(0, 2) . ' days');
                $stats['packed']++;
            }
        }

        return $stats;
    }

    // Generic enough to fit any gear type; kept at seven so the seeded history doesn't shift.
    private const array FAULTS = [
        'Intermittent fault, worse when moved',
        'Dropped out on air, twice',
        'Cracked housing',
        'Won\'t power on',
        'Loose connector',
        'Water damage after a wet match',
        'Damaged in transit',
    ];

    /** @return array{string, int, string} template code, positions, job name */
    private function pickJob(DateTimeImmutable $day, int $round, int $n): array
    {
        $month = (int) $day->format('n');
        $isSummer = $month >= 5 && $month <= 8;
        $options = $isSummer
            ? ['CRICKET-BOX' => 3, 'STREET-PACK' => 3, 'LIVE-MUSIC' => 2, 'BREAKFAST-OB' => 2, 'RACE-DAY' => 2, 'FOOTY-BOX' => 1]
            : ['FOOTY-BOX' => 5, 'STREET-PACK' => 3, 'BREAKFAST-OB' => 2, 'RACE-DAY' => 2, 'LIVE-MUSIC' => 1];

        $ticket = $this->random->getInt(1, array_sum($options));
        foreach ($options as $code => $weight) {
            if (($ticket -= $weight) <= 0) {
                break;
            }
        }

        return match ($code) {
            'FOOTY-BOX' => ['FOOTY-BOX', $this->random->getInt(2, 4), "Footy round {$round}: " . ($n % 2 === 0 ? 'home game' : 'away game')],
            'CRICKET-BOX' => ['CRICKET-BOX', $this->random->getInt(3, 5), 'Cricket: day ' . $this->random->getInt(1, 4) . ' commentary'],
            'STREET-PACK' => ['STREET-PACK', $this->random->getInt(1, 3), 'Street talk: ' . ['market day', 'commuter vox pops', 'school gates', 'high street', 'late-night shift'][$this->random->getInt(0, 4)]],
            'BREAKFAST-OB' => ['BREAKFAST-OB', $this->random->getInt(2, 4), 'Breakfast roadshow: ' . $day->format('D j M')],
            'LIVE-MUSIC' => ['LIVE-MUSIC', $this->random->getInt(2, 5), 'Acoustic session ' . $day->format('j M')],
            'RACE-DAY' => ['RACE-DAY', $this->random->getInt(2, 4), 'Race day: ' . ['afternoon card', 'evening meeting', 'feature race'][$this->random->getInt(0, 2)]],
        };
    }

    /** @return list<string> consumable codes currently below their reorder point */
    private function belowReorder(): array
    {
        return $this->db->query(<<<'SQL'
            SELECT c.code, c.reorder_point
            FROM consumables c
            LEFT JOIN lot_balances b ON b.consumable_id = c.id
            GROUP BY c.id
            HAVING COALESCE(SUM(b.on_hand), 0) < c.reorder_point
            SQL)->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @param array{string, string, int, int, string} $consumable */
    private function deliver(string $code, array $consumable, DateTimeImmutable $on): void
    {
        [, , , $deliverySize, $lotPrefix] = $consumable;
        $lotCode = sprintf('%s-%s-%03d', $lotPrefix, $on->format('ymd'), $this->random->getInt(1, 999));

        new ReceiveConsumable($this->db)->receive(
            $this->consumableIds[$code],
            $lotCode,
            $deliverySize,
            $on->format('Y-m-d'),
            $on->setTime(8, 30),
        );
    }
}
