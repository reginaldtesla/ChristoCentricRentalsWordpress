<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit('CLI only'); }
require dirname(__DIR__) . '/wp-load.php';
switch_theme('christocentric');
echo "Active theme: christocentric\n";
echo "Visit: http://christocentric-wp.localhost/\n";
