<?php
declare(strict_types=1);
/** @var string $title @var string $content @var string $head */
$current = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$nav = ['/store' => 'Store', '/planner' => 'Planner', '/jobs' => 'Jobs', '/trace' => 'Trace', '/ledger' => 'Ledger'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($title) ?> · ob-stores</title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Crect width='24' height='24' rx='4' fill='%231c1d1f'/%3E%3Cpath d='M5 18V10m3.5 8V6m3.5 12v-6m3.5 6V8M19 18v-3' stroke='%235fd3a6' stroke-width='2' stroke-linecap='round'/%3E%3C/svg%3E">
    <link rel="stylesheet" href="/assets/app.css">
    <script src="/assets/keys.js" defer></script>
    <?= $head ?>
</head>
<body>
<header class="topbar">
    <a class="brand" href="/">
        <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M4 18V9m4 9V5m4 13v-7m4 7V7m4 11v-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none"/></svg>
        ob-stores
    </a>
    <nav>
        <?php foreach ($nav as $href => $label): ?>
            <a href="<?= e($href) ?>"<?= str_starts_with($current, $href) ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
        <?php endforeach ?>
    </nav>
</header>
<main>
<?= $content ?>
</main>
<footer class="keys-hint"><kbd>j</kbd> <kbd>k</kbd> move between rows · <kbd>Enter</kbd> open · <kbd>/</kbd> filter the store</footer>
</body>
</html>
