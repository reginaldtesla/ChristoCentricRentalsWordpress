<?php
defined('ABSPATH') || exit;

$page = ccr_page_json($args['slug'] ?? 'help');
$slug = $args['slug'] ?? 'help';
$toc = is_array($page['toc'] ?? null) ? $page['toc'] : [];
$body = ccr_page_body_html($slug);
?>
<div class="container-site doc-page">
    <div class="<?php echo $toc === [] ? 'doc-layout doc-layout--full' : 'doc-layout'; ?>">
        <?php if ($toc !== []) : ?>
            <nav class="doc-toc" aria-label="Page sections">
                <p class="doc-toc-title">On this page</p>
                <ul class="doc-toc-list">
                    <?php foreach ($toc as $id => $label) : ?>
                        <li><a href="#<?php echo esc_attr((string) $id); ?>"><?php echo esc_html($label); ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </nav>
        <?php endif; ?>
        <div class="doc-body ccr-doc-body">
            <?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </div>
    </div>
</div>
