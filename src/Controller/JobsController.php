<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\HttpError;
use App\Http\Response;
use App\Repo\JobRepo;
use App\Service\PackJob;
use App\Service\ReturnJob;
use App\Service\ValidationError;
use PDO;

final class JobsController
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function index(): Response
    {
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $result = new JobRepo($this->db)->page($page);

        return page('Jobs', 'jobs', [
            'jobs' => $result['rows'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / JobRepo::PER_PAGE)),
        ]);
    }

    /** @param array<string, string> $errors */
    public function show(int $id, array $errors = []): Response
    {
        $jobs = new JobRepo($this->db);
        $job = $jobs->find($id) ?? throw new HttpError(404, 'Job not found');

        return page($job['name'], 'job', [
            'job' => $job,
            'gear' => $jobs->gear($id),
            'consumables' => $jobs->consumables($id),
            'errors' => $errors,
        ])->withStatus($errors === [] ? 200 : 422);
    }

    /** POST /api/jobs from the Vue planner. */
    public function pack(): Response
    {
        $input = json_body();
        $jobId = new PackJob($this->db)->pack(
            ApiController::int($input, 'template_id'),
            ApiController::int($input, 'positions'),
            is_string($input['name'] ?? null) ? $input['name'] : '',
            is_string($input['job_date'] ?? null) ? $input['job_date'] : '',
        );

        return Response::json(['id' => $jobId, 'url' => "/jobs/{$jobId}"], 201);
    }

    /** POST /jobs/{id}/return: a plain HTML form, no JavaScript at all. */
    public function return(int $id): Response
    {
        $notes = is_array($_POST['note'] ?? null) ? $_POST['note'] : [];
        $faulty = [];
        foreach (is_array($_POST['faulty'] ?? null) ? $_POST['faulty'] : [] as $itemId => $_) {
            $faulty[(int) $itemId] = is_string($notes[$itemId] ?? null) ? $notes[$itemId] : '';
        }

        try {
            new ReturnJob($this->db)->return($id, $faulty);
        } catch (ValidationError $e) {
            return $this->show($id, $e->errors);
        }

        return Response::redirect("/jobs/{$id}");
    }
}
