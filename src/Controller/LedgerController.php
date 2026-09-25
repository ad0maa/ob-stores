<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Response;
use App\Repo\LedgerRepo;
use PDO;

final class LedgerController
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function index(): Response
    {
        $serial = trim((string) ($_GET['serial'] ?? '')) ?: null;
        $consumableId = filter_var($_GET['consumable'] ?? null, FILTER_VALIDATE_INT) ?: null;
        $page = max(1, (int) ($_GET['page'] ?? 1));

        $ledger = new LedgerRepo($this->db);
        $result = $ledger->page($serial, $serial === null ? $consumableId : null, $page);

        return page('Ledger', 'ledger', [
            'rows' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / LedgerRepo::PER_PAGE)),
            'serial' => $serial,
            'consumableId' => $consumableId,
            'consumables' => $ledger->consumableOptions(),
        ]);
    }
}
