<?php

declare(strict_types=1);

namespace App\Repo;

use PDO;

final class TemplateRepo
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** Templates a job can be packed from. Sub-kits only exist inside other templates. @return list<array<string, mixed>> */
    public function packable(): array
    {
        return $this->db->query('SELECT id, code, name, description FROM kit_templates WHERE NOT is_subkit ORDER BY name')->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT id, code, name, description FROM kit_templates WHERE id = ? AND NOT is_subkit');
        $statement->execute([$id]);

        return $statement->fetch() ?: null;
    }
}
