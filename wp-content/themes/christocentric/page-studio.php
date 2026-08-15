<?php
defined('ABSPATH') || exit;

$contact = ccr_site_config('contact', []);
$lights_url = ccr_shop_url(['product_cat' => 'continuous-light']);
$cameras_url = ccr_shop_url(['product_cat' => 'cameras']);
$audio_url = ccr_shop_url(['product_cat' => 'audio-gears']);
$contact_url = home_url('/contact/');

$gear_images = [];
if (function_exists('wc_get_products')) {
    $products = wc_get_products([
        'status' => 'publish',
        'limit' => 6,
        'category' => ['continuous-light', 'cameras'],
        'orderby' => 'date',
        'order' => 'DESC',
    ]);
    foreach ($products as $product) {
        $src = wp_get_attachment_image_url($product->get_image_id(), 'medium');
        if ($src) {
            $gear_images[] = [
                'src' => $src,
                'alt' => $product->get_name(),
                'url' => $product->get_permalink(),
            ];
        }
    }
}

$uses = [
    ['label' => 'Interviews', 'text' => 'Clean key light and a quiet backdrop for talking-head and documentary work.'],
    ['label' => 'Portraits', 'text' => 'Controlled light for headshots, beauty, and creator stills.'],
    ['label' => 'Product & brand', 'text' => 'Tabletop and lifestyle setups for packs, props, and social content.'],
    ['label' => 'Church & team media', 'text' => 'Reliable space for announcements, worship clips, and training videos.'],
];

$steps = [
    ['n' => '01', 'title' => 'Tell us the shoot', 'text' => 'Share your date, session length, and whether you need cameras, lights, or audio.'],
    ['n' => '02', 'title' => 'Confirm the slot', 'text' => 'We check studio availability, quote the session, and reserve any gear with it.'],
    ['n' => '03', 'title' => 'Show up ready', 'text' => 'Arrive on time. First-time clients should bring a valid Ghana Card.'],
];

get_header();
?>

<section class="ccr-studio-hero">
    <div class="container-site ccr-studio-hero-inner">
        <div class="ccr-studio-hero-copy">
            <p class="ccr-studio-eyebrow"><?php esc_html_e('Kumasi · Bomso', 'christocentric'); ?></p>
            <h1 class="ccr-studio-title"><?php esc_html_e('Studio', 'christocentric'); ?></h1>
            <p class="ccr-studio-lead">
                <?php esc_html_e('Book a controlled space for interviews, portraits, and content shoots — and pair it with rental cameras, lights, and audio from our inventory.', 'christocentric'); ?>
            </p>
            <div class="ccr-studio-hero-actions">
                <a href="<?php echo esc_url($contact_url); ?>" class="btn-solid"><?php esc_html_e('Enquire to book', 'christocentric'); ?></a>
                <a href="<?php echo esc_url($lights_url); ?>" class="ccr-studio-link"><?php esc_html_e('Browse lighting', 'christocentric'); ?></a>
            </div>
        </div>
        <?php if ($gear_images !== []) : ?>
            <div class="ccr-studio-hero-stage" aria-hidden="true">
                <?php foreach (array_slice($gear_images, 0, 4) as $i => $img) : ?>
                    <div class="ccr-studio-hero-tile ccr-studio-hero-tile--<?php echo (int) ($i + 1); ?>">
                        <img src="<?php echo esc_url($img['src']); ?>" alt="" loading="eager">
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<section class="ccr-studio-section">
    <div class="container-site">
        <div class="ccr-studio-section-head">
            <h2><?php esc_html_e('Built for real shoots', 'christocentric'); ?></h2>
            <p><?php esc_html_e('A practical studio environment for creators, churches, and production teams in Kumasi.', 'christocentric'); ?></p>
        </div>
        <ul class="ccr-studio-uses">
            <?php foreach ($uses as $use) : ?>
                <li>
                    <span class="ccr-studio-use-label"><?php echo esc_html($use['label']); ?></span>
                    <span class="ccr-studio-use-text"><?php echo esc_html($use['text']); ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</section>

<?php if ($gear_images !== []) : ?>
<section class="ccr-studio-section ccr-studio-section--alt">
    <div class="container-site">
        <div class="ccr-studio-section-head ccr-studio-section-head--row">
            <div>
                <h2><?php esc_html_e('Gear ready with your session', 'christocentric'); ?></h2>
                <p><?php esc_html_e('Reserve lights, cameras, and audio alongside studio time so you walk into a complete setup.', 'christocentric'); ?></p>
            </div>
            <a href="<?php echo esc_url(ccr_shop_url()); ?>" class="ccr-studio-link"><?php esc_html_e('All products', 'christocentric'); ?></a>
        </div>
        <div class="ccr-studio-gear-rail">
            <?php foreach ($gear_images as $img) : ?>
                <a href="<?php echo esc_url($img['url']); ?>" class="ccr-studio-gear-item">
                    <img src="<?php echo esc_url($img['src']); ?>" alt="<?php echo esc_attr($img['alt']); ?>" loading="lazy">
                    <span><?php echo esc_html($img['alt']); ?></span>
                </a>
            <?php endforeach; ?>
        </div>
        <div class="ccr-studio-gear-links">
            <a href="<?php echo esc_url($cameras_url); ?>"><?php esc_html_e('Cameras', 'christocentric'); ?></a>
            <a href="<?php echo esc_url($lights_url); ?>"><?php esc_html_e('Lights', 'christocentric'); ?></a>
            <a href="<?php echo esc_url($audio_url); ?>"><?php esc_html_e('Audio', 'christocentric'); ?></a>
        </div>
    </div>
</section>
<?php endif; ?>

<section class="ccr-studio-section">
    <div class="container-site">
        <div class="ccr-studio-section-head">
            <h2><?php esc_html_e('How booking works', 'christocentric'); ?></h2>
            <p><?php esc_html_e('Simple enquiry — we confirm space, kit, and timing before you pay.', 'christocentric'); ?></p>
        </div>
        <ol class="ccr-studio-steps">
            <?php foreach ($steps as $step) : ?>
                <li>
                    <span class="ccr-studio-step-n"><?php echo esc_html($step['n']); ?></span>
                    <h3><?php echo esc_html($step['title']); ?></h3>
                    <p><?php echo esc_html($step['text']); ?></p>
                </li>
            <?php endforeach; ?>
        </ol>
    </div>
</section>

<section class="ccr-studio-book">
    <div class="container-site ccr-studio-book-inner">
        <div>
            <h2><?php esc_html_e('Ready to book the studio?', 'christocentric'); ?></h2>
            <p>
                <?php echo esc_html($contact['address'] ?? 'Bomso, near Abesse Gaming Center'); ?>
                · <?php echo esc_html($contact['city'] ?? 'Kumasi, Ghana'); ?>
            </p>
            <p class="ccr-studio-book-meta">
                <a href="tel:<?php echo esc_attr($contact['phone'] ?? ''); ?>"><?php echo esc_html($contact['phone_display'] ?? ''); ?></a>
                <span aria-hidden="true">·</span>
                <a href="mailto:<?php echo esc_attr($contact['email'] ?? ''); ?>"><?php echo esc_html($contact['email'] ?? ''); ?></a>
            </p>
        </div>
        <a href="<?php echo esc_url($contact_url); ?>" class="btn-solid"><?php esc_html_e('Enquire to book', 'christocentric'); ?></a>
    </div>
</section>

<?php get_footer(); ?>
