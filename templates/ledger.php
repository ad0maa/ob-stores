<?php
declare(strict_types=1);

use App\Domain\MovementType;

/**
 * @var list<array<string, mixed>> $rows
 * @var list<array<string, mixed>> $consumables
 * @var int $total @var int $page @var int $pages
 * @var ?string $serial @var ?int $consumableId
 */
$query = fn (int $toPage): string => '?' . http_build_query(array_filter([
    'serial' => $serial, 'consumable' => $consumableId, 'page' => $toPage,
]));
?>
<div class="page-head">
    <h1>Ledger</h1>
    <form class="inline-form" method="get" action="/ledger">
        <label>Serial <input name="serial" value="<?= e($serial) ?>" placeholder="e.g. CDC-0007" size="12"></label>
        <label>Consumable
            <select name="consumable">
                <option value="">All</option>
                <?php foreach ($consumables as $option): ?>
                    <option value="<?= e($option['id']) ?>"<?= $option['id'] === $consumableId ? ' selected' : '' ?>><?= e($option['name']) ?></option>
                <?php endforeach ?>
            </select>
        </label>
        <button type="submit">Filter</button>
        <?php if ($serial !== null || $consumableId !== null): ?><a href="/ledger">Clear</a><?php endif ?>
    </form>
</div>

<p class="muted">
    <?= e(number_format($total)) ?> movements, newest first. Read-only: stock only ever changes by adding a row.
    Balance is the running total for that serial or consumable.
</p>

<table class="grid">
    <thead>
    <tr><th class="num">#</th><th>When</th><th>Movement</th><th>Item</th><th>Serial / lot</th><th class="num">Qty</th><th class="num">Balance</th><th>Job</th><th>Note</th></tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $row): ?>
        <tr>
            <td class="num muted"><?= e($row['id']) ?></td>
            <td><?= e(substr($row['created_at'], 0, 16)) ?></td>
            <td><span class="move move-<?= e($row['type']) ?>"><?= e(MovementType::from($row['type'])->label()) ?></span></td>
            <td><?= e($row['gear_name'] ?? $row['consumable_name']) ?></td>
            <td class="mono">
                <?php if ($row['serial'] !== null): ?>
                    <a href="/trace?serial=<?= e(urlencode($row['serial'])) ?>"><?= e($row['serial']) ?></a>
                <?php else: ?>
                    <a href="/trace?lot=<?= e(urlencode($row['lot_code'])) ?>&amp;consumable=<?= e($row['consumable_id']) ?>"><?= e($row['lot_code']) ?></a>
                <?php endif ?>
            </td>
            <td class="num"><?= e(sprintf('%+d', $row['qty'])) ?></td>
            <td class="num"><?= e(number_format((int) $row['balance'])) ?></td>
            <td><?php if ($row['job_id'] !== null): ?><a href="/jobs/<?= e($row['job_id']) ?>"><?= e($row['job_name']) ?></a><?php endif ?></td>
            <td class="muted"><?= e($row['note']) ?></td>
        </tr>
    <?php endforeach ?>
    </tbody>
</table>

<nav class="pager" aria-label="Pages">
    <?php if ($page > 1): ?><a href="<?= e($query($page - 1)) ?>" rel="prev">← Newer</a><?php endif ?>
    <span>Page <?= e($page) ?> of <?= e(number_format($pages)) ?></span>
    <?php if ($page < $pages): ?><a href="<?= e($query($page + 1)) ?>" rel="next">Older →</a><?php endif ?>
</nav>
