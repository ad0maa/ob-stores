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
    <link rel="stylesheet" href="/assets/app.css">
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
</body>
</html>
