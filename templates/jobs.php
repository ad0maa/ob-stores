<?php
declare(strict_types=1);
/** @var list<array<string, mixed>> $jobs @var int $page @var int $pages */
?>
<div class="page-head">
    <h1>Jobs</h1>
    <a href="/planner">Plan and pack a kit →</a>
</div>

<table class="grid">
    <thead>
    <tr><th class="num">#</th><th>Date</th><th>Job</th><th>Kit</th><th class="num">Positions</th><th class="num">Gear</th><th>Status</th></tr>
    </thead>
    <tbody>
    <?php foreach ($jobs as $job): ?>
        <tr>
            <td class="num muted"><?= e($job['id']) ?></td>
            <td><?= e($job['job_date']) ?></td>
            <td><a href="/jobs/<?= e($job['id']) ?>"><?= e($job['name']) ?></a></td>
            <td><?= e($job['template_name']) ?></td>
            <td class="num"><?= e($job['positions']) ?></td>
            <td class="num"><?= e($job['gear_count']) ?></td>
            <td>
                <?php if ($job['returned_at'] === null): ?>
                    <span class="badge badge-out">Out</span>
                <?php else: ?>
                    <span class="badge">Returned</span>
                    <?php if ($job['faulty_count'] > 0): ?><span class="badge badge-faulty"><?= e($job['faulty_count']) ?> faulty</span><?php endif ?>
                <?php endif ?>
            </td>
        </tr>
    <?php endforeach ?>
    </tbody>
</table>

<nav class="pager" aria-label="Pages">
    <?php if ($page > 1): ?><a href="?page=<?= e($page - 1) ?>" rel="prev">← Newer</a><?php endif ?>
    <span>Page <?= e($page) ?> of <?= e($pages) ?></span>
    <?php if ($page < $pages): ?><a href="?page=<?= e($page + 1) ?>" rel="next">Older →</a><?php endif ?>
</nav>
