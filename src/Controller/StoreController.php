<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Response;
use App\Repo\StockRepo;
use PDO;

/** The legacy screen: rendered on the server, enhanced with jQuery. */
final class StoreController
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function index(): Response
    {
        $stock = new StockRepo($this->db);

        return page('Store', 'store', [
            'gear' => $stock->gearSummary(),
            'consumables' => $stock->consumables(),
        ], head: '<script src="/assets/vendor/jquery.min.js" defer></script><script src="/assets/legacy/store.js" defer></script>');
    }
}
