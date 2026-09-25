<?php
declare(strict_types=1);

use App\Domain\GearItem;

/**
 * @var string $serial @var string $lotCode
 * @var ?GearItem $item
 * @var list<array<string, mixed>> $gearJobs
 * @var list<array<string, mixed>> $lots
 * @var list<array<string, mixed>> $lotJobs
 */
?>
<h1>Trace</h1>
<div class="trace-forms">
    <form method="get" action="/trace" class="inline-form">
        <label>Gear serial <input name="serial" value="<?= e($serial) ?>" placeholder="e.g. CDC-0007" autofocus></label>
        <button type="submit">Trace serial</button>
    </form>
    <form method="get" action="/trace" class="inline-form">
        <label>Consumable lot <input name="lot" value="<?= e($lotCode) ?>" placeholder="e.g. PWR-250225-096"></label>
        <button type="submit">Trace lot</button>
    </form>
</div>
<p class="muted">From a job, open the job page to see everything that went.</p>

<?php if ($serial !== ''): ?>
    <?php if ($item === null): ?>
        <p class="form-error">No gear item with serial <?= e($serial) ?>.</p>
    <?php else: ?>
        <h2><?= e($item->serial) ?> · <?= e($item->typeName) ?></h2>
        <dl class="facts">
            <dt>Status now</dt><dd><span class="badge status-<?= e($item->status) ?>"><?= e(ucfirst($item->status)) ?></span></dd>
            <dt>Acquired</dt><dd><?= e($item->acquiredOn) ?></dd>
            <dt>History</dt><dd><?= e(count($gearJobs)) ?> <?= count($gearJobs) === 1 ? "job" : "jobs" ?>, <?= e($item->movementCount) ?> ledger rows · <a href="/ledger?serial=<?= e(urlencode($item->serial)) ?>">view in ledger</a></dd>
        </dl>
        <table class="grid">
            <thead><tr><th>Job date</th><th>Job</th><th>Kit</th><th>Out</th><th>Back</th><th>Faulty note</th></tr></thead>
            <tbody>
            <?php foreach ($gearJobs as $job): ?>
                <tr class="<?= $job['faulty_note'] !== null ? 'is-faulty-row' : '' ?>">
                    <td><?= e($job['job_date']) ?></td>
                    <td><a href="/jobs/<?= e($job['id']) ?>"><?= e($job['name']) ?></a></td>
                    <td><?= e($job['template_name']) ?></td>
                    <td><?= e(substr($job['out_at'], 0, 16)) ?></td>
                    <td><?= e($job['back_at'] === null ? 'still out' : substr($job['back_at'], 0, 16)) ?></td>
                    <td><?= e($job['faulty_note']) ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    <?php endif ?>
<?php elseif ($lotCode !== ''): ?>
    <?php if ($lots === []): ?>
        <p class="form-error">No lot with code <?= e($lotCode) ?>.</p>
    <?php elseif (count($lots) > 1): ?>
        <p>Lot <?= e($lotCode) ?> exists for more than one item. Pick one:</p>
        <ul>
            <?php foreach ($lots as $lot): ?>
                <li><a href="/trace?lot=<?= e(urlencode($lotCode)) ?>&amp;consumable=<?= e($lot['consumable_id']) ?>"><?= e($lot['name']) ?></a></li>
            <?php endforeach ?>
        </ul>
    <?php else: $lot = $lots[0] ?>
        <h2>Lot <?= e($lot['lot_code']) ?> · <?= e($lot['name']) ?></h2>
        <dl class="facts">
            <dt>Received</dt><dd><?= e($lot['received_on']) ?> · <?= e($lot['received_qty']) ?> <?= e($lot['unit']) ?></dd>
            <dt>On hand</dt><dd><?= e($lot['on_hand']) ?> <?= e($lot['unit']) ?></dd>
            <dt>Packed into</dt><dd><?= e(count($lotJobs)) ?> <?= count($lotJobs) === 1 ? "job" : "jobs" ?></dd>
        </dl>
        <table class="grid">
            <thead><tr><th>Job date</th><th>Job</th><th>Kit</th><th class="num">Qty from this lot</th></tr></thead>
            <tbody>
            <?php foreach ($lotJobs as $job): ?>
                <tr>
                    <td><?= e($job['job_date']) ?></td>
                    <td><a href="/jobs/<?= e($job['id']) ?>"><?= e($job['name']) ?></a></td>
                    <td><?= e($job['template_name']) ?></td>
                    <td class="num"><?= e($job['qty']) ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    <?php endif ?>
<?php endif ?>
