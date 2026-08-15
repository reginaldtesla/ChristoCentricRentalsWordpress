<?php
/**
 * Ensure every rentable product manages stock (default qty 1 from CSV when present).
 * Run: php scripts/ensure-product-stock.php
 *      php scripts/ensure-product-stock.php --default=1
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('CLI only.');
}

require dirname(__DIR__) . '/wp-load.php';

$defaultQty = 1;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--default=')) {
        $defaultQty = max(0, (int) substr($arg, 10));
    }
}

$csvStock = [];
$csv = dirname(__DIR__) . '/migration/woocommerce-products.csv';
if (is_file($csv)) {
    $fh = fopen($csv, 'r');
    $header = fgetcsv($fh, 0, ',', '"', '\\');
    $col = array_flip($header ?: []);
    while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
        $sku = $row[$col['SKU'] ?? -1] ?? '';
        if ($sku === '') {
            continue;
        }
        $stock = $row[$col['Stock'] ?? -1] ?? '';
        if ($stock !== '' && is_numeric($stock)) {
            $csvStock[$sku] = max(0, (int) $stock);
        }
    }
    fclose($fh);
}

echo "=== Ensure product stock ===\n";
$updated = 0;
$skippedKit = 0;

foreach (wc_get_products(['limit' => -1, 'status' => 'publish']) as $product) {
    $id = $product->get_id();

    // Kits pull component stock at add-to-cart time.
    if (get_post_meta($id, '_ccr_is_kit', true) === 'yes') {
        $product->set_manage_stock(false);
        $product->set_stock_status('instock');
        $product->save();
        $skippedKit++;
        echo "Kit (components track stock): {$product->get_name()}\n";
        continue;
    }

    $sku = (string) $product->get_sku();
    $qty = $csvStock[$sku] ?? null;
    if ($qty === null) {
        $current = $product->get_manage_stock() ? (int) $product->get_stock_quantity() : 0;
        $qty = $current > 0 ? $current : $defaultQty;
    }

    $product->set_manage_stock(true);
    $product->set_stock_quantity($qty);
    $product->set_stock_status($qty > 0 ? 'instock' : 'outofstock');
    $product->set_backorders('no');
    $product->save();
    $updated++;
    echo "OK: {$product->get_name()} → qty {$qty}\n";
}

echo "\nUpdated: {$updated}\nKits left unmanaged: {$skippedKit}\nDone.\n";
