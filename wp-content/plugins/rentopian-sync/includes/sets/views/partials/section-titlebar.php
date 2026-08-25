<?php
/**
 * Sets Module — Shared Section Title-bar Partial
 *
 * One row: [title (truncating)] [(i) info — JS-revealed on overflow]
 * [required|optional badge]. Used by every section type so the badge is
 * anchored at the same horizontal position on every card (UX #3). A long
 * title is clipped with an ellipsis instead of wrapping; the full title
 * stays reachable via the native `title=""` tooltip and the (i) icon
 * (shown only when the JS detects the text actually overflows).
 *
 * Indicator (right slot) — same position on every card:
 *   - 'included' mode (fixed items): required → "Included" badge.
 *   - 'marker'  mode (choice sections): required → red `*` marker.
 *   - optional (either mode) → subtle "Optional" badge.
 *
 * Expects in scope:
 *   @var array  $section              The section array (uses title + required).
 *   @var bool   $rntp_tb_required     Optional override; defaults to
 *                                     ! empty( $section['required'] ).
 *   @var string $rntp_tb_badge_mode   'included' | 'marker'. Default 'marker'.
 *
 * @package RentopianSync\Sets
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$rntp_tb_title      = isset( $section['title'] ) ? (string) $section['title'] : '';
$rntp_tb_required   = isset( $rntp_tb_required )
    ? (bool) $rntp_tb_required
    : ! empty( $section['required'] );
$rntp_tb_badge_mode = isset( $rntp_tb_badge_mode ) ? (string) $rntp_tb_badge_mode : 'marker';
?>
<div class="rntp-section-titlebar">
    <h4 class="rntp-section-title" title="<?php echo esc_attr( $rntp_tb_title ); ?>"><?php echo esc_html( $rntp_tb_title ); ?></h4>
    <span class="rntp-title-info" role="img" aria-label="<?php echo esc_attr( $rntp_tb_title ); ?>" title="<?php echo esc_attr( $rntp_tb_title ); ?>">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
            <circle cx="12" cy="12" r="10" />
            <line x1="12" y1="16" x2="12" y2="12" />
            <line x1="12" y1="8" x2="12.01" y2="8" />
        </svg>
    </span>
    <?php if ( $rntp_tb_required ) : ?>
        <?php if ( 'included' === $rntp_tb_badge_mode ) : ?>
            <span class="rntp-required-badge" aria-label="<?php esc_attr_e( 'Included', 'rentopian-sync' ); ?>">
                <?php esc_html_e( 'Included', 'rentopian-sync' ); ?>
            </span>
        <?php else : ?>
            <span class="rntp-required-marker" aria-label="<?php esc_attr_e( 'Required', 'rentopian-sync' ); ?>">*</span>
        <?php endif; ?>
    <?php else : ?>
        <span class="rntp-optional-badge" aria-label="<?php esc_attr_e( 'Optional', 'rentopian-sync' ); ?>">
            <?php esc_html_e( 'Optional', 'rentopian-sync' ); ?>
        </span>
    <?php endif; ?>
</div>
<?php
unset( $rntp_tb_required, $rntp_tb_title, $rntp_tb_badge_mode );
