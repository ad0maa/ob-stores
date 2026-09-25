<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\MovementType;
use App\Domain\PlanLine;
use App\Repo\TemplateRepo;
use DateTimeImmutable;
use PDO;
use PDOException;
use Throwable;

/**
 * Pack a kit for a job: create the job, check out specific serialised items
 * and consume consumables oldest lot first, all in one transaction.
 *
 * Why the locks: two crews packing at the same moment must not both get the
 * same codec. Each gear type's items (and each consumable's lots) are locked
 * FOR UPDATE before availability is read, so the second packer waits until the
 * first commits, then sees what's actually left.
 *
 * Why FOR SHARE on the availability reads: InnoDB's default isolation is
 * REPEATABLE READ, where a plain SELECT reads from a snapshot taken at the
 * transaction's first read. A snapshot taken while locking the first gear type
 * would be stale by the second. A locking read always sees the latest
 * committed rows.
 *
 * Deadlocks: locks are always taken in the same order (gear types by id, then
 * consumables by id), so two packers can't deadlock each other. If MySQL still
 * reports one, the whole transaction is retried once.
 */
final class PackJob
{
    private const string DEADLOCK = '40001';

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return int the new job id */
    public function pack(int $templateId, int $positions, string $name, string $jobDate, ?DateTimeImmutable $at = null): int
    {
        $name = trim($name);
        $errors = [];
        if (new TemplateRepo($this->db)->find($templateId) === null) {
            $errors['template_id'] = 'Choose a kit template.';
        }
        if ($name === '' || mb_strlen($name) > 120) {
            $errors['name'] = 'Job name is required (120 characters max).';
        }
        if (!Clock::isDate($jobDate)) {
            $errors['job_date'] = 'Job date must be a real date (YYYY-MM-DD).';
        }
        if ($errors !== []) {
            throw new ValidationError($errors);
        }

        // The recipe itself doesn't change under us; only availability does, and that is re-read under lock.
        $plan = new RecipeExploder($this->db)->explode($templateId, $positions);

        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->packInTransaction($plan, $templateId, $positions, $name, $jobDate, Clock::stamp($at));
            } catch (PDOException $e) {
                if ($attempt === 1 && $e->getCode() === self::DEADLOCK) {
                    continue;
                }
                throw $e;
            }
        }
    }

    /** @param list<PlanLine> $plan */
    private function packInTransaction(array $plan, int $templateId, int $positions, string $name, string $jobDate, string $stamp): int
    {
        $this->db->beginTransaction();
        try {
            $this->db->prepare('INSERT INTO jobs (name, job_date, template_id, positions, packed_at) VALUES (?, ?, ?, ?, ?)')
                ->execute([$name, $jobDate, $templateId, $positions, $stamp]);
            $jobId = (int) $this->db->lastInsertId();

            $shortfalls = [];
            foreach ($this->inLockOrder($plan, 'gear') as $line) {
                $shortfalls[] = $this->checkOutGear($line, $jobId, $stamp);
            }
            foreach ($this->inLockOrder($plan, 'consumable') as $line) {
                $shortfalls[] = $this->consumeOldestFirst($line, $jobId, $stamp);
            }

            $shortfalls = array_values(array_filter($shortfalls));
            if ($shortfalls !== []) {
                throw new InsufficientStock($shortfalls);
            }

            $this->db->commit();

            return $jobId;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** @return PlanLine|null the shortfall, if any */
    private function checkOutGear(PlanLine $line, int $jobId, string $stamp): ?PlanLine
    {
        $lock = $this->db->prepare('SELECT id FROM gear_items WHERE gear_type_id = ? ORDER BY id FOR UPDATE');
        $lock->execute([$line->itemId]);

        $available = $this->db->prepare(<<<'SQL'
            SELECT gi.id
            FROM gear_items gi
            WHERE gi.gear_type_id = :type
              AND (SELECT m.type FROM movements m
                   WHERE m.gear_item_id = gi.id
                   ORDER BY m.id DESC LIMIT 1
                   FOR SHARE) IN ('receipt', 'return', 'repaired')
            ORDER BY gi.id
            LIMIT :needed
            SQL);
        $available->bindValue('type', $line->itemId, PDO::PARAM_INT);
        $available->bindValue('needed', $line->required, PDO::PARAM_INT);
        $available->execute();
        $itemIds = $available->fetchAll(PDO::FETCH_COLUMN);

        if (count($itemIds) < $line->required) {
            return $this->short($line, count($itemIds));
        }

        $checkout = $this->db->prepare('INSERT INTO movements (type, qty, gear_item_id, job_id, created_at) VALUES (?, -1, ?, ?, ?)');
        foreach ($itemIds as $itemId) {
            $checkout->execute([MovementType::Checkout->value, $itemId, $jobId, $stamp]);
        }

        return null;
    }

    /** @return PlanLine|null the shortfall, if any */
    private function consumeOldestFirst(PlanLine $line, int $jobId, string $stamp): ?PlanLine
    {
        $lock = $this->db->prepare('SELECT id FROM lots WHERE consumable_id = ? ORDER BY received_on, id FOR UPDATE');
        $lock->execute([$line->itemId]);

        $balances = $this->db->prepare(<<<'SQL'
            SELECT l.id,
                   (SELECT CAST(COALESCE(SUM(m.qty), 0) AS SIGNED) FROM movements m
                    WHERE m.lot_id = l.id
                    FOR SHARE) AS on_hand
            FROM lots l
            WHERE l.consumable_id = ?
            ORDER BY l.received_on, l.id
            SQL);
        $balances->execute([$line->itemId]);
        $lots = array_filter($balances->fetchAll(PDO::FETCH_KEY_PAIR), fn (int $onHand): bool => $onHand > 0);

        $total = array_sum($lots);
        if ($total < $line->required) {
            return $this->short($line, $total);
        }

        $consume = $this->db->prepare('INSERT INTO movements (type, qty, lot_id, job_id, created_at) VALUES (?, ?, ?, ?, ?)');
        $remaining = $line->required;
        foreach ($lots as $lotId => $onHand) {
            $take = min($onHand, $remaining);
            $consume->execute([MovementType::Consume->value, -$take, $lotId, $jobId, $stamp]);
            $remaining -= $take;
            if ($remaining === 0) {
                break;
            }
        }

        return null;
    }

    /** @param list<PlanLine> $plan @return list<PlanLine> */
    private function inLockOrder(array $plan, string $kind): array
    {
        $lines = array_values(array_filter($plan, fn (PlanLine $line): bool => $line->kind === $kind));
        usort($lines, fn (PlanLine $a, PlanLine $b): int => $a->itemId <=> $b->itemId);

        return $lines;
    }

    private function short(PlanLine $line, int $available): PlanLine
    {
        return new PlanLine($line->kind, $line->itemId, $line->code, $line->name, $line->unit, $line->required, $available);
    }
}
