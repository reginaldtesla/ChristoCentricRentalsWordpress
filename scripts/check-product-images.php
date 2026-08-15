<?php
require dirname(__DIR__) . '/wp-load.php';

$ok = 0;
$bad = 0;
$none = 0;

foreach (wc_get_products(['limit' => -1, 'status' => 'publish']) as $p) {
    $id = $p->get_image_id();
    if (! $id) {
        $none++;
        echo 'NO THUMB: ' . $p->get_name() . PHP_EOL;
        continue;
    }
    $file = get_attached_file($id);
    if ($file && is_file($file)) {
        $ok++;
    } else {
        $bad++;
        echo 'BROKEN: ' . $p->get_name() . ' file=' . ($file ?: 'none') . PHP_EOL;
    }
}

echo "ok_files={$ok} broken={$bad} no_thumb={$none}\n";

$sample = wc_get_products(['limit' => 5, 'status' => 'publish', 'orderby' => 'title', 'order' => 'ASC']);
foreach ($sample as $p) {
    $id = $p->get_image_id();
    echo $p->get_name() . ' | ' . wp_get_attachment_image_url($id, 'woocommerce_thumbnail') . PHP_EOL;
}
