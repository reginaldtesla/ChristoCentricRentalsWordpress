<?php
/**
 * Import newsletter subscribers from the Laravel app database.
 *
 * Usage: php scripts/import-newsletter-subscribers.php
 */
require dirname(__DIR__) . '/wp-load.php';

if (! class_exists('CCR_Newsletter')) {
    fwrite(STDERR, "CCR_Newsletter not loaded.\n");
    exit(1);
}

$laravelRoot = dirname(__DIR__, 2) . '/ChristocentricRentals';
$autoload = $laravelRoot . '/vendor/autoload.php';

if (! is_file($autoload)) {
    fwrite(STDERR, "Laravel app not found at {$laravelRoot}\n");
    exit(1);
}

require $autoload;

$app = require $laravelRoot . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$rows = App\Models\NewsletterSubscriber::query()->orderBy('id')->get();

if ($rows->isEmpty()) {
    echo "No Laravel subscribers to import.\n";
    exit(0);
}

global $wpdb;

$table = CCR_Newsletter::table_name();
$imported = 0;
$skipped = 0;

foreach ($rows as $row) {
    $email = strtolower(trim((string) $row->email));

    if ($email === '' || ! is_email($email)) {
        $skipped++;
        continue;
    }

    $existing = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$table} WHERE email = %s", $email)); // phpcs:ignore

    if ($existing) {
        $wpdb->update(
            $table,
            [
                'unsubscribe_token' => (string) $row->unsubscribe_token,
                'subscribed_at' => $row->subscribed_at?->format('Y-m-d H:i:s') ?? current_time('mysql'),
                'unsubscribed_at' => $row->unsubscribed_at?->format('Y-m-d H:i:s'),
            ],
            ['id' => (int) $existing->id],
            ['%s', '%s', '%s'],
            ['%d']
        );
        $skipped++;
        continue;
    }

    $wpdb->insert(
        $table,
        [
            'email' => $email,
            'unsubscribe_token' => (string) $row->unsubscribe_token,
            'subscribed_at' => $row->subscribed_at?->format('Y-m-d H:i:s') ?? current_time('mysql'),
            'unsubscribed_at' => $row->unsubscribed_at?->format('Y-m-d H:i:s'),
        ],
        ['%s', '%s', '%s', '%s']
    );

    $imported++;
}

echo "Imported: {$imported}\n";
echo "Updated/skipped existing: {$skipped}\n";
echo "Active subscribers: " . CCR_Newsletter::active_count() . "\n";
