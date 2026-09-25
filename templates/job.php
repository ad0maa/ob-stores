<?php
declare(strict_types=1);
/**
 * @var array<string, mixed> $job
 * @var list<array<string, mixed>> $gear
 * @var list<array<string, mixed>> $consumables
 * @var array<string, string> $errors
 */
$isOut = $job['returned_at'] === null;
?>
<div class="page-head">
    <h1><?= e($job['name']) ?></h1>
    <?php if ($isOut): ?><span class="badge badge-out">Out</span><?php else: ?><span class="badge">Returned</span><?php endif ?>
</div>

<dl class="facts">
    <dt>Job date</dt><dd><?= e($job['job_date']) ?></dd>
    <dt>Kit</dt><dd><a href="/planner?template=<?= e($job['template_id']) ?>&amp;positions=<?= e($job['positions']) ?>"><?= e($job['positions']) ?> × <?= e($job['template_name']) ?></a></dd>
    <dt>Packed</dt><dd><?= e(substr($job['packed_at'], 0, 16)) ?></dd>
    <dt>Returned</dt><dd><?= e($isOut ? '—' : substr($job['returned_at'], 0, 16)) ?></dd>
</dl>

<?php foreach ($errors as $message): ?>
    <p class="form-error" role="alert"><?= e($message) ?></p>
<?php endforeach ?>

<form method="post" action="/jobs/<?= e($job['id']) ?>/return">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <h2>Gear (<?= e(count($gear)) ?>)</h2>
    <table class="grid">
        <thead>
        <tr><th>Serial</th><th>Code</th><th>Gear</th><th><?= $isOut ? 'Faulty?' : 'Came back' ?></th><th>Note</th></tr>
        </thead>
        <tbody>
        <?php foreach ($gear as $item): ?>
            <tr>
                <td class="mono"><a href="/trace?serial=<?= e(urlencode($item['serial'])) ?>"><?= e($item['serial']) ?></a></td>
                <td class="mono muted"><?= e($item['code']) ?></td>
                <td><?= e($item['name']) ?></td>
                <?php if ($isOut): ?>
                    <td><label class="check"><input type="checkbox" name="faulty[<?= e($item['id']) ?>]" value="1"> Faulty</label></td>
                    <td><input name="note[<?= e($item['id']) ?>]" maxlength="255" placeholder="What went wrong?" class="note-input"></td>
                <?php else: ?>
                    <td><?= $item['faulty_note'] !== null ? '<span class="badge badge-faulty">Faulty</span>' : '<span class="badge">OK</span>' ?></td>
                    <td class="muted"><?= e($item['faulty_note']) ?></td>
                <?php endif ?>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
    <?php if ($isOut): ?>
        <p class="buttons"><button type="submit" class="primary">Return all gear</button></p>
    <?php endif ?>
</form>

<h2>Consumables packed</h2>
<table class="grid">
    <thead><tr><th>Code</th><th>Item</th><th>Lot</th><th>Lot received</th><th class="num">Qty</th></tr></thead>
    <tbody>
    <?php foreach ($consumables as $row): ?>
        <tr>
            <td class="mono muted"><?= e($row['code']) ?></td>
            <td><?= e($row['name']) ?></td>
            <td class="mono"><a href="/trace?lot=<?= e(urlencode($row['lot_code'])) ?>&amp;consumable=<?= e($row['consumable_id']) ?>"><?= e($row['lot_code']) ?></a></td>
            <td><?= e($row['received_on']) ?></td>
            <td class="num"><?= e($row['qty']) ?> <span class="unit"><?= e($row['unit']) ?></span></td>
        </tr>
    <?php endforeach ?>
    </tbody>
</table>
