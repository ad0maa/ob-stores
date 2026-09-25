<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\MovementType;
use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Bring a job's gear back in. Every item gets a `return` row; an item marked
 * faulty also gets a `faulty` row with the note, which takes it out of the
 * available pool. Consumables were used up on the job and don't come back.
 */
final class ReturnJob
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @param array<int, string> $faulty gear_item_id => note */
    public function return(int $jobId, array $faulty = [], ?DateTimeImmutable $at = null): void
    {
        $stamp = Clock::stamp($at);

        $this->db->beginTransaction();
        try {
            $job = $this->db->prepare('SELECT returned_at FROM jobs WHERE id = ? FOR UPDATE');
            $job->execute([$jobId]);
            $returnedAt = $job->fetchColumn();
            if ($returnedAt === false) {
                throw new ValidationError(['job' => 'No such job.']);
            }
            if ($returnedAt !== null) {
                throw new ValidationError(['job' => 'This job has already been returned.']);
            }

            // Same lock as packing takes, so a return and a pack of the same item serialise.
            $items = $this->db->prepare(<<<'SQL'
                SELECT gi.id
                FROM gear_items gi
                WHERE gi.id IN (SELECT gear_item_id FROM movements WHERE job_id = ? AND type = 'checkout')
                ORDER BY gi.id
                FOR UPDATE
                SQL);
            $items->execute([$jobId]);
            $itemIds = $items->fetchAll(PDO::FETCH_COLUMN);

            $notOnJob = array_diff(array_keys($faulty), $itemIds);
            if ($notOnJob !== []) {
                throw new ValidationError(['faulty' => 'Only items packed for this job can be marked faulty.']);
            }

            $insert = $this->db->prepare('INSERT INTO movements (type, qty, gear_item_id, job_id, note, created_at) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($itemIds as $itemId) {
                $insert->execute([MovementType::Return->value, 1, $itemId, $jobId, null, $stamp]);
                if (array_key_exists($itemId, $faulty)) {
                    $note = mb_substr(trim($faulty[$itemId]), 0, 255) ?: 'Reported faulty on return';
                    $insert->execute([MovementType::Faulty->value, 0, $itemId, $jobId, $note, $stamp]);
                }
            }

            $this->db->prepare('UPDATE jobs SET returned_at = ? WHERE id = ?')->execute([$stamp, $jobId]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
