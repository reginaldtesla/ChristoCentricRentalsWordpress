<?php
/**
 * Audit product featured images: size + approximate white background.
 * Run: php scripts/audit-product-images.php
 *      php scripts/audit-product-images.php --remove
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('CLI only.');
}

$wpRoot = dirname(__DIR__);
require_once $wpRoot . '/wp-load.php';

$remove = in_array('--remove', $argv, true);

$minSide = 400;      // too small if shortest side below this
$maxSide = 3500;     // too large if longest side above this
$maxBytes = 2_500_000; // ~2.5MB
$whiteTol = 28;      // how far from 255 to still count as white-ish

echo "=== Product image audit ===\n";
echo $remove ? "Mode: REMOVE bad images from products\n" : "Mode: report only (add --remove to clear)\n";

$bad = [];
$ok = 0;
$noThumb = 0;

foreach (wc_get_products(['limit' => -1, 'status' => 'publish']) as $product) {
    $productId = $product->get_id();
    $thumbId = (int) $product->get_image_id();
    if ($thumbId <= 0) {
        $noThumb++;
        continue;
    }

    $file = get_attached_file($thumbId);
    if (! $file || ! is_file($file)) {
        $bad[] = [
            'product' => $product->get_name(),
            'id' => $productId,
            'attachment' => $thumbId,
            'file' => $file ?: '(missing)',
            'reasons' => ['file missing on disk'],
        ];
        continue;
    }

    $reasons = [];
    $bytes = filesize($file) ?: 0;
    if ($bytes > $maxBytes) {
        $reasons[] = 'file too large (' . round($bytes / 1048576, 2) . ' MB)';
    }

    $info = @getimagesize($file);
    if (! $info) {
        $reasons[] = 'unreadable image';
    } else {
        [$w, $h] = $info;
        $min = min($w, $h);
        $max = max($w, $h);
        if ($min < $minSide) {
            $reasons[] = "too small ({$w}x{$h})";
        }
        if ($max > $maxSide) {
            $reasons[] = "too large dimensions ({$w}x{$h})";
        }

        $bg = ccr_background_score($file, $info[2], $whiteTol);
        if ($bg !== null) {
            if ($bg['white_ratio'] < 0.55) {
                $reasons[] = sprintf(
                    'non-white / busy background (white corners ~%d%%, avg RGB %d,%d,%d)',
                    (int) round($bg['white_ratio'] * 100),
                    $bg['avg'][0],
                    $bg['avg'][1],
                    $bg['avg'][2]
                );
            }
        }
    }

    if ($reasons === []) {
        $ok++;
        continue;
    }

    $bad[] = [
        'product' => $product->get_name(),
        'id' => $productId,
        'attachment' => $thumbId,
        'file' => $file,
        'reasons' => $reasons,
    ];
}

echo "\nOK: {$ok}\n";
echo "No thumbnail: {$noThumb}\n";
echo "Bad: " . count($bad) . "\n\n";

$report = $wpRoot . '/products/image-audit-report.csv';
$fh = fopen($report, 'w');
fputcsv($fh, ['product_id', 'product', 'attachment_id', 'file', 'reasons'], ',', '"', '\\');

foreach ($bad as $row) {
    $reasonText = implode('; ', $row['reasons']);
    echo "- {$row['product']}\n  {$reasonText}\n  {$row['file']}\n";
    fputcsv($fh, [$row['id'], $row['product'], $row['attachment'], $row['file'], $reasonText], ',', '"', '\\');

    if ($remove) {
        delete_post_thumbnail($row['id']);
        // Detach featured only — keep media file in library in case needed later.
        echo "  → removed featured image from product\n";
    }
}
fclose($fh);

echo "\nReport: {$report}\n";
if (! $remove && $bad !== []) {
    echo "Re-run with --remove to clear these featured images from products.\n";
}

/**
 * Sample corners + edge midpoints; return how "white" the backdrop looks.
 *
 * @return array{white_ratio: float, avg: array{0:int,1:int,2:int}}|null
 */
function ccr_background_score(string $file, int $type, int $tol): ?array
{
    $img = match ($type) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($file),
        IMAGETYPE_PNG => @imagecreatefrompng($file),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file) : false,
        IMAGETYPE_GIF => @imagecreatefromgif($file),
        default => false,
    };

    if (! $img) {
        // AVIF / other — skip bg check
        return null;
    }

    $w = imagesx($img);
    $h = imagesy($img);
    $points = [
        [2, 2],
        [$w - 3, 2],
        [2, $h - 3],
        [$w - 3, $h - 3],
        [(int) ($w / 2), 2],
        [(int) ($w / 2), $h - 3],
        [2, (int) ($h / 2)],
        [$w - 3, (int) ($h / 2)],
        // slightly inset corners (catch colored studio backdrops)
        [12, 12],
        [$w - 13, 12],
        [12, $h - 13],
        [$w - 13, $h - 13],
    ];

    $white = 0;
    $sum = [0, 0, 0];
    $n = 0;

    foreach ($points as [$x, $y]) {
        $x = max(0, min($w - 1, $x));
        $y = max(0, min($h - 1, $y));
        $rgb = imagecolorat($img, $x, $y);
        // PNG with alpha
        $a = ($rgb >> 24) & 0x7F;
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;

        // Fully/nearly transparent counts as fine (cutout)
        if ($a >= 100) {
            $white++;
            $n++;
            continue;
        }

        $sum[0] += $r;
        $sum[1] += $g;
        $sum[2] += $b;
        $n++;

        if ($r >= 255 - $tol && $g >= 255 - $tol && $b >= 255 - $tol) {
            $white++;
        }
    }

    imagedestroy($img);

    if ($n === 0) {
        return null;
    }

    return [
        'white_ratio' => $white / $n,
        'avg' => [
            (int) round($sum[0] / max(1, $n)),
            (int) round($sum[1] / max(1, $n)),
            (int) round($sum[2] / max(1, $n)),
        ],
    ];
}
