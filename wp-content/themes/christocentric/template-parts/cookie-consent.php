<?php
defined('ABSPATH') || exit;

$privacy = home_url('/privacy/');
?>
<div class="ccr-cookie-consent" data-ccr-cookie-consent hidden>
    <div class="ccr-cookie-consent-inner" role="dialog" aria-labelledby="ccr-cookie-consent-title" aria-describedby="ccr-cookie-consent-desc">
        <div class="ccr-cookie-consent-copy">
            <p id="ccr-cookie-consent-title" class="ccr-cookie-consent-title"><?php esc_html_e('We use cookies', 'christocentric'); ?></p>
            <p id="ccr-cookie-consent-desc" class="ccr-cookie-consent-desc">
                <?php
                echo wp_kses(
                    sprintf(
                        /* translators: %s: privacy policy URL */
                        __('We use essential cookies to run the shop (cart, login, compare). With your permission we also use a personalization cookie to suggest gear based on what you view. Read our <a href="%s">Privacy Policy</a>.', 'christocentric'),
                        esc_url($privacy)
                    ),
                    [
                        'a' => [
                            'href' => true,
                        ],
                    ]
                );
                ?>
            </p>
        </div>
        <div class="ccr-cookie-consent-actions">
            <button type="button" class="ccr-cookie-consent-btn ccr-cookie-consent-btn--ghost" data-ccr-cookie-essential>
                <?php esc_html_e('Essential only', 'christocentric'); ?>
            </button>
            <button type="button" class="ccr-cookie-consent-btn ccr-cookie-consent-btn--solid" data-ccr-cookie-accept>
                <?php esc_html_e('Accept all', 'christocentric'); ?>
            </button>
        </div>
    </div>
</div>
