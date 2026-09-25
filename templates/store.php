<?php
declare(strict_types=1);
/**
 * @var list<array<string, mixed>> $gear
 * @var list<array<string, mixed>> $consumables
 */
$category = null;
?>
<div class="page-head">
    <h1>Store</h1>
    <label class="filter">
        <span class="visually-hidden">Filter</span>
        <input id="store-filter" type="search" placeholder="Filter gear and consumables" autocomplete="off">
        <kbd>/</kbd>
    </label>
</div>

<h2>Consumables</h2>
<table class="grid" id="consumables" data-filterable>
    <thead>
    <tr>
        <th>Code</th><th>Item</th><th class="num">On hand</th><th class="num">Reorder at</th>
        <th class="num">Open lots</th><th>Oldest open lot</th><th>Status</th><th></th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($consumables as $item): ?>
        <tr data-id="<?= e($item['id']) ?>" data-name="<?= e($item['name']) ?>"
            data-search="<?= e(strtolower($item['code'] . ' ' . $item['name'])) ?>"
            class="<?= $item['below_reorder'] ? 'is-low' : '' ?>">
            <td class="mono"><a href="/ledger?consumable=<?= e($item['id']) ?>"><?= e($item['code']) ?></a></td>
            <td><?= e($item['name']) ?></td>
            <td class="num" data-col="on_hand"><?= e(number_format($item['on_hand'])) ?> <span class="unit"><?= e($item['unit']) ?></span></td>
            <td class="num"><?= e(number_format($item['reorder_point'])) ?></td>
            <td class="num" data-col="open_lots"><?= e($item['open_lots']) ?></td>
            <td data-col="oldest_open_lot"><?= e($item['oldest_open_lot'] ?? '—') ?></td>
            <td data-col="status"><span class="badge"><?= $item['below_reorder'] ? 'Below reorder' : 'OK' ?></span></td>
            <td class="actions"><button type="button" data-receive>Receive</button></td>
        </tr>
    <?php endforeach ?>
    </tbody>
</table>

<h2>Serialised gear</h2>
<table class="grid" id="gear" data-filterable>
    <thead>
    <tr><th>Code</th><th>Gear type</th><th class="num">In</th><th class="num">Out</th><th class="num">Faulty</th><th class="num">Total</th></tr>
    </thead>
    <tbody>
    <?php foreach ($gear as $type): ?>
        <?php if ($type['category'] !== $category): $category = $type['category'] ?>
            <tr class="group" data-group="<?= e($category) ?>"><th colspan="6"><?= e($category) ?></th></tr>
        <?php endif ?>
        <tr data-search="<?= e(strtolower($type['code'] . ' ' . $type['name'] . ' ' . $type['category'])) ?>" data-in-group="<?= e($category) ?>">
            <td class="mono"><a href="/gear/<?= e($type['id']) ?>"><?= e($type['code']) ?></a></td>
            <td><?= e($type['name']) ?></td>
            <td class="num"><?= e($type['available']) ?></td>
            <td class="num<?= $type['out'] > 0 ? ' is-out' : '' ?>"><?= e($type['out']) ?></td>
            <td class="num<?= $type['faulty'] > 0 ? ' is-faulty' : '' ?>"><?= e($type['faulty']) ?></td>
            <td class="num muted"><?= e($type['total']) ?></td>
        </tr>
    <?php endforeach ?>
    </tbody>
</table>

<dialog id="receive-dialog" aria-labelledby="receive-title">
    <form id="receive-form" novalidate>
        <h2 id="receive-title">Receive</h2>
        <input type="hidden" name="consumable_id">
        <label>Supplier lot code
            <input name="lot_code" required maxlength="40" autocomplete="off">
            <span class="field-error" data-error-for="lot_code"></span>
        </label>
        <label>Quantity
            <input name="qty" type="number" min="1" step="1" required inputmode="numeric">
            <span class="field-error" data-error-for="qty"></span>
        </label>
        <label>Received on
            <input name="received_on" type="date" required>
            <span class="field-error" data-error-for="received_on"></span>
        </label>
        <p class="form-error" id="receive-error" role="alert"></p>
        <div class="buttons">
            <button type="button" id="receive-cancel">Cancel</button>
            <button type="submit" class="primary">Receive lot</button>
        </div>
    </form>
</dialog>
