<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Response;
use App\Repo\TraceRepo;
use PDO;

/** "That codec dropped out on air: where else has it been?" and "Bad batteries: which jobs got them?" */
final class TraceController
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function index(): Response
    {
        $serial = trim((string) ($_GET['serial'] ?? ''));
        $lotCode = trim((string) ($_GET['lot'] ?? ''));
        $consumableId = filter_var($_GET['consumable'] ?? null, FILTER_VALIDATE_INT) ?: null;
        $trace = new TraceRepo($this->db);

        $vars = ['serial' => $serial, 'lotCode' => $lotCode, 'item' => null, 'gearJobs' => [], 'lots' => [], 'lotJobs' => []];

        if ($serial !== '') {
            $vars['item'] = $trace->gearItem($serial);
            $vars['gearJobs'] = $vars['item'] === null ? [] : $trace->jobsForGear($vars['item']->id);
        } elseif ($lotCode !== '') {
            $vars['lots'] = $trace->lots($lotCode, $consumableId);
            $vars['lotJobs'] = count($vars['lots']) === 1 ? $trace->jobsForLot($vars['lots'][0]['lot_id']) : [];
        }

        return page('Trace', 'trace', $vars);
    }
}
