<?php

defined('ABSPATH') || exit;

/**
 * Branded HTML emails with company logo.
 */
final class CCR_Email
{
    public static function init(): void
    {
        add_action('init', [self::class, 'ensure_woocommerce_logo'], 20);
        add_filter('woocommerce_email_header_image', [self::class, 'filter_woocommerce_logo']);
        add_filter('woocommerce_email_styles', [self::class, 'email_styles'], 20);
    }

    public static function logo_url(): string
    {
        if (function_exists('ccr_theme_asset')) {
            return ccr_theme_asset('images/brand/logo.png');
        }

        $theme = get_template_directory_uri() . '/assets/images/brand/logo.png';
        if (is_file(get_template_directory() . '/assets/images/brand/logo.png')) {
            return $theme;
        }

        return content_url('uploads/christocentric/brand/logo.png');
    }

    public static function ensure_woocommerce_logo(): void
    {
        if (! class_exists('WooCommerce')) {
            return;
        }

        $logo = self::logo_url();
        $current = (string) get_option('woocommerce_email_header_image', '');

        if ($current === '' || ! str_contains($current, 'logo')) {
            update_option('woocommerce_email_header_image', $logo);
        }

        if ((string) get_option('woocommerce_email_header_image_width', '') === '') {
            update_option('woocommerce_email_header_image_width', '180');
        }
    }

    public static function filter_woocommerce_logo($image): string
    {
        $image = is_string($image) ? trim($image) : '';

        return $image !== '' ? $image : self::logo_url();
    }

    public static function email_styles(string $css): string
    {
        $css .= '
#template_header_image img {
    max-width: 180px !important;
    height: auto !important;
}
';

        return $css;
    }

    /**
     * Build a simple branded HTML email body.
     */
    public static function wrap_html(string $title, string $innerHtml): string
    {
        $logo = esc_url(self::logo_url());
        $site = esc_html(get_bloginfo('name'));
        $home = esc_url(home_url('/'));
        $year = esc_html(gmdate('Y'));

        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>' . esc_html($title) . '</title></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f3f4f6;padding:24px 12px;">
    <tr><td align="center">
      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:#ffffff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">
        <tr>
          <td style="padding:20px 24px;border-bottom:1px solid #e5e7eb;text-align:center;background:#ffffff;">
            <a href="' . $home . '" style="text-decoration:none;">
              <img src="' . $logo . '" alt="' . $site . '" width="180" style="display:inline-block;max-width:180px;height:auto;border:0;">
            </a>
          </td>
        </tr>
        <tr>
          <td style="padding:24px;font-size:15px;line-height:1.55;color:#374151;">
            ' . $innerHtml . '
          </td>
        </tr>
        <tr>
          <td style="padding:16px 24px;background:#f9fafb;border-top:1px solid #e5e7eb;font-size:12px;color:#6b7280;text-align:center;">
            &copy; ' . $year . ' ' . $site . '
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body></html>';
    }

    /**
     * @param list<string> $extraHeaders
     * @return list<string>
     */
    public static function html_headers(array $extraHeaders = []): array
    {
        $fromName = (string) get_option('ccr_smtp_from_name', '');
        if ($fromName === '') {
            $fromName = (string) get_option('ccr_newsletter_from_name', get_bloginfo('name'));
        }
        $fromEmail = (string) get_option('ccr_smtp_from_email', '');
        if ($fromEmail === '' || ! is_email($fromEmail)) {
            $fromEmail = (string) get_option('ccr_newsletter_from_email', '');
        }
        if ($fromEmail === '' || ! is_email($fromEmail)) {
            $fromEmail = class_exists('CCR_Settings') ? CCR_Settings::default_support_email() : (string) get_option('admin_email');
        }

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $fromName . ' <' . $fromEmail . '>',
        ];

        return array_merge($headers, $extraHeaders);
    }

    /**
     * Escape plain text and convert newlines to paragraphs for HTML emails.
     */
    public static function text_to_html(string $text): string
    {
        $parts = preg_split("/\n{2,}/", trim($text)) ?: [];
        $html = '';
        foreach ($parts as $part) {
            $html .= '<p style="margin:0 0 14px;">' . nl2br(esc_html(trim($part))) . '</p>';
        }

        return $html;
    }
}
