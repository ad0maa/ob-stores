<?php
declare(strict_types=1);
/**
 * @var array<string, mixed> $type
 * @var list<array<string, mixed>> $items
 * @var array<string, int> $counts
 * @var array<string, string> $errors
 * @var ?int $errorItem
 */
?>
<p class="crumbs"><a href="/store">Store</a> / <?= e($type['category']) ?></p>
<div class="page-head">
    <h1><?= e($type['name']) ?> <span class="mono muted"><?= e($type['code']) ?></span></h1>
    <p class="counts">
        <span class="badge status-available"><?= e($counts['available']) ?> in</span>
        <span class="badge status-out"><?= e($counts['out']) ?> out</span>
        <span class="badge status-faulty"><?= e($counts['faulty']) ?> faulty</span>
    </p>
</div>

<table class="grid gear-items">
    <thead>
    <tr><th>Serial</th><th>Status</th><th>Where / why</th><th class="num">Jobs</th><th>Action</th></tr>
    </thead>
    <tbody>
    <?php foreach ($items as $item): ?>
        <tr id="item-<?= e($item['id']) ?>" class="<?= $item['status'] === 'faulty' ? 'is-faulty-row' : '' ?>">
            <td class="mono"><a href="/trace?serial=<?= e(urlencode($item['serial'])) ?>"><?= e($item['serial']) ?></a></td>
            <td><span class="badge status-<?= e($item['status']) ?>"><?= e(ucfirst($item['status'])) ?></span></td>
            <td>
                <?php if ($item['status'] === 'out'): ?>
                    <a href="/jobs/<?= e($item['job_id']) ?>"><?= e($item['job_name']) ?></a>
                <?php elseif ($item['status'] === 'faulty'): ?>
                    <?= e($item['fault_note']) ?> <span class="muted">· since <?= e(substr($item['since'], 0, 10)) ?></span>
                <?php else: ?>
                    <span class="muted">In store</span>
                <?php endif ?>
            </td>
            <td class="num"><?= e($item['job_count']) ?></td>
            <td class="actions-cell">
                <?php if ($item['status'] !== 'out'): $isFaulty = $item['status'] === 'faulty' ?>
                    <form method="post" action="/gear/<?= e($type['id']) ?>/items/<?= e($item['id']) ?>/<?= $isFaulty ? 'repair' : 'fault' ?>" class="row-form">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input name="note" maxlength="255" aria-label="Note for <?= e($item['serial']) ?>"
                               placeholder="<?= $isFaulty ? 'What was fixed? (optional)' : 'What is wrong?' ?>"<?= $isFaulty ? '' : ' required' ?>>
                        <button type="submit"<?= $isFaulty ? ' class="primary"' : '' ?>><?= $isFaulty ? 'Mark repaired' : 'Report fault' ?></button>
                    </form>
                    <?php if ($errorItem === $item['id']): ?>
                        <?php foreach ($errors as $message): ?><p class="form-error" role="alert"><?= e($message) ?></p><?php endforeach ?>
                    <?php endif ?>
                <?php endif ?>
            </td>
        </tr>
    <?php endforeach ?>
    </tbody>
</table>
