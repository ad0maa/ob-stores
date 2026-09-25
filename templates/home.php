<?php
declare(strict_types=1);
/** @var string $mysqlVersion */
?>
<h1>ob-stores</h1>
<p>Equipment store for the outside broadcast team.</p>
<dl class="facts">
    <dt>PHP</dt><dd><?= e(PHP_VERSION) ?></dd>
    <dt>MySQL</dt><dd><?= e($mysqlVersion) ?></dd>
    <dt>Vue</dt><dd id="planner">not mounted (run <code>npm run build</code>)</dd>
</dl>
