<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\PlanLine;
use App\Http\HttpError;
use App\Http\Response;
use App\Repo\TemplateRepo;
use App\Service\RecipeExploder;
use PDO;

/** The modern screen: PHP renders the shell, Vue owns everything inside #planner. */
final class PlannerController
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function index(): Response
    {
        return page('Planner', 'planner', head: vite_tags('frontend/planner/main.js'));
    }

    public function templates(): Response
    {
        return Response::json(new TemplateRepo($this->db)->packable());
    }

    public function plan(int $id): Response
    {
        $template = new TemplateRepo($this->db)->find($id) ?? throw new HttpError(404, 'Template not found');
        $positions = ApiController::int($_GET, 'positions');
        $lines = new RecipeExploder($this->db)->explode($id, $positions);
        $short = array_values(array_filter($lines, fn (PlanLine $line): bool => $line->shortfall > 0));

        return Response::json([
            'template' => $template,
            'positions' => $positions,
            'lines' => $lines,
            'shortfalls' => count($short),
        ]);
    }
}
