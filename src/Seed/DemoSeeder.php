<?php

declare(strict_types=1);

namespace App\Seed;

use App\Service\ReceiveConsumable;
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
