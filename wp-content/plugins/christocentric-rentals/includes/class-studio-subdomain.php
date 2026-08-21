<?php

defined('ABSPATH') || exit;

/**
 * Serve the studio booking page at studio.{main-domain} (same WordPress install).
 */
final class CCR_Studio_Subdomain
{
    public static function init(): void
    {
        add_filter('request', [self::class, 'map_request']);
        add_filter('template_include', [self::class, 'force_template'], 99);
        add_filter('redirect_canonical', [self::class, 'skip_canonical']);
        add_filter('body_class', [self::class, 'body_class']);
        add_filter('allowed_redirect_hosts', [self::class, 'allow_host']);
    }

    public static function current_host(): string
    {
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));

        return preg_replace('/:\d+$/', '', $host) ?: $host;
    }

    public static function main_host(): string
    {
        $host = (string) wp_parse_url((string) get_option('home'), PHP_URL_HOST);
        $host = preg_replace('/^www\./i', '', $host) ?: $host;

        return strtolower($host);
    }

    public static function studio_host(): string
    {
        if (defined('CCR_STUDIO_HOST') && is_string(CCR_STUDIO_HOST) && CCR_STUDIO_HOST !== '') {
            return strtolower(preg_replace('/:\d+$/', '', CCR_STUDIO_HOST) ?: CCR_STUDIO_HOST);
        }

        return 'studio.' . self::main_host();
    }

    public static function is_subdomain_request(): bool
    {
        $current = self::current_host();

        return $current !== '' && $current === self::studio_host();
    }

    /**
     * Use studio.{domain} links only when already on that host, or when opted in.
     * Add define('CCR_STUDIO_SUBDOMAIN', true); to wp-config.php after the subdomain loads the booking page.
     */
    public static function use_subdomain_urls(): bool
    {
        if (defined('CCR_STUDIO_SUBDOMAIN') && CCR_STUDIO_SUBDOMAIN === false) {
            return false;
        }
        if (self::is_subdomain_request()) {
            return true;
        }

        return defined('CCR_STUDIO_SUBDOMAIN') && CCR_STUDIO_SUBDOMAIN === true;
    }

    public static function origin(): string
    {
        $scheme = is_ssl() ? 'https' : 'http';

        return $scheme . '://' . self::studio_host();
    }

    public static function url(string $path = '/'): string
    {
        $path = '/' . ltrim($path, '/');
        if ($path === '/') {
            $path = '/';
        }
        if (self::use_subdomain_urls()) {
            return untrailingslashit(self::origin()) . ($path === '/' ? '/' : $path);
        }

        $base = home_url('/studio/');
        if ($path === '/' || $path === '/studio/' || $path === '/studio') {
            return $base;
        }

        return $base . ltrim($path, '/');
    }

    public static function ajax_url(): string
    {
        if (self::is_subdomain_request()) {
            return self::origin() . '/wp-admin/admin-ajax.php';
        }

        return admin_url('admin-ajax.php');
    }

    /** @param array<string,mixed> $vars */
    public static function map_request(array $vars): array
    {
        if (! self::is_subdomain_request()) {
            return $vars;
        }
        $vars['pagename'] = 'studio';
        unset($vars['error']);

        return $vars;
    }

    public static function force_template(string $template): string
    {
        if (! self::is_subdomain_request()) {
            return $template;
        }
        $file = get_template_directory() . '/page-studio.php';

        return is_readable($file) ? $file : $template;
    }

    public static function skip_canonical($redirect)
    {
        if (self::is_subdomain_request()) {
            return false;
        }

        return $redirect;
    }

    /** @param list<string> $classes */
    public static function body_class(array $classes): array
    {
        if (self::is_subdomain_request()) {
            $classes[] = 'ccr-studio-booking';
        }

        return $classes;
    }

    public static function redirect_main_studio_path(): void
    {
        // Optional 301; off by default so /studio/ keeps working if the subdomain is not ready.
        if (! defined('CCR_STUDIO_REDIRECT') || CCR_STUDIO_REDIRECT !== true) {
            return;
        }
        if (! self::use_subdomain_urls() || self::is_subdomain_request()) {
            return;
        }
        if (! is_page('studio')) {
            return;
        }
        $qs = isset($_SERVER['QUERY_STRING']) ? (string) $_SERVER['QUERY_STRING'] : '';
        $target = self::url('/');
        if ($qs !== '') {
            $target .= (str_contains($target, '?') ? '&' : '?') . $qs;
        }
        if (! headers_sent()) {
            wp_safe_redirect($target, 301);
            exit;
        }
    }

    /** @param list<string> $hosts */
    public static function allow_host(array $hosts): array
    {
        $hosts[] = self::studio_host();

        return array_values(array_unique($hosts));
    }
}

if (! function_exists('ccr_studio_url')) {
    function ccr_studio_url(string $path = '/'): string
    {
        return CCR_Studio_Subdomain::url($path);
    }
}

if (! function_exists('ccr_is_studio_subdomain')) {
    function ccr_is_studio_subdomain(): bool
    {
        return CCR_Studio_Subdomain::is_subdomain_request();
    }
}
