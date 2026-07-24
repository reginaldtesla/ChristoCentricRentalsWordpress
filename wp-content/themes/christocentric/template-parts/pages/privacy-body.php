<?php
/** Auto-synced from Laravel pages/partials/privacy-body.blade.php */
?>
<p class="doc-date">Effective date: February 6, 2025</p>

<div class="doc-intro">
    <p class="doc-lead">
        At Christocentric Rentals, we are committed to protecting your privacy and ensuring the security of your personal information. This Privacy Policy outlines how we collect, use, disclose, and safeguard your data when you visit our website or use our services.
    </p>
    <p>
        By accessing or using Christocentric Rentals’s website and services, you agree to the practices described in this policy. If you do not agree with this policy, please do not use our website or services.
    </p>
</div>

<section id="collect" class="doc-section">
    <h2>1. Information We Collect</h2>
    <p>We collect both personally identifiable information (PII) and non-personally identifiable information (non-PII) when you use our website or services.</p>
    <h3>Personal information</h3>
    <p>When you create an account, make a rental reservation, or contact us directly, we may collect:</p>
    <ul>
        <li>Name</li>
        <li>Email address</li>
        <li>Phone number</li>
        <li>Billing and shipping address</li>
        <li>Payment information (credit/debit card details, mobile money, PayPal, etc.)</li>
        <li>Date of birth (if applicable for age verification)</li>
        <li>User preferences (such as rental period and equipment choices)</li>
    </ul>
    <h3>Non-personal information</h3>
    <p>We may also collect non-personally identifiable information automatically when you visit our website, including:</p>
    <ul>
        <li>IP address</li>
        <li>Browser type</li>
        <li>Device information (e.g., mobile, desktop)</li>
        <li>Location data (if you enable location services)</li>
        <li>Pages viewed</li>
        <li>Referring URLs</li>
        <li>Cookies and tracking technologies</li>
    </ul>
</section>

<section id="use" class="doc-section">
    <h2>2. How We Use Your Information</h2>
    <ul>
        <li><strong>Rental processing</strong> — to process and manage your equipment rental, including booking, payments, and fulfillment.</li>
        <li><strong>Customer support</strong> — to respond to inquiries and provide assistance.</li>
        <li><strong>Communication</strong> — to send confirmations, updates, promotions, and important notices.</li>
        <li><strong>Website improvement</strong> — to analyze how you interact with our website and improve our services.</li>
        <li><strong>Marketing</strong> — to send promotional offers where you have consented to receive them.</li>
    </ul>
</section>

<section id="protect" class="doc-section">
    <h2>3. How We Protect Your Information</h2>
    <ul>
        <li><strong>Encryption</strong> — sensitive data, such as payment information, is encrypted using SSL technology.</li>
        <li><strong>Access control</strong> — only authorized personnel have access to your personal data.</li>
        <li><strong>Secure storage</strong> — your information is stored in secure databases and servers.</li>
    </ul>
    <p>Despite our efforts, no method of transmission over the internet is 100% secure. We cannot guarantee absolute security.</p>
</section>

<section id="sharing" class="doc-section">
    <h2>4. Sharing and Disclosure of Your Information</h2>
    <p>We do not sell, trade, or rent your personal information to third parties. We may share your information when:</p>
    <ul>
        <li><strong>Service providers</strong> — payment processing, hosting, marketing, and other business functions.</li>
        <li><strong>Legal compliance</strong> — when required by law or legal requests.</li>
        <li><strong>Business transfers</strong> — in a merger, acquisition, or sale of assets, with notice to you.</li>
    </ul>
</section>

<section id="cookies" class="doc-section">
    <h2>5. Cookies and Tracking Technologies</h2>
    <p>Our website uses cookies to enhance your experience. We use cookies to:</p>
    <ul>
        <li>Remember preferences and settings.</li>
        <li>Gather anonymous analytics data.</li>
        <li>Improve website functionality and navigation.</li>
    </ul>
    <p>You can control cookies through your browser settings. Disabling cookies may affect some site features.</p>
</section>

<section id="third-party" class="doc-section">
    <h2>6. Third-Party Links</h2>
    <p>Our website may contain links to third-party websites. These sites have their own privacy policies. We encourage you to review them before providing personal information.</p>
</section>

<section id="rights" class="doc-section">
    <h2>7. Your Rights and Choices</h2>
    <ul>
        <li><strong>Access</strong> — request access to the personal data we hold about you.</li>
        <li><strong>Correction</strong> — update or correct inaccurate information.</li>
        <li><strong>Deletion</strong> — request deletion, subject to legal or contractual obligations.</li>
        <li><strong>Opt-out</strong> — choose not to receive marketing communications.</li>
        <li><strong>Data portability</strong> — receive your data in a structured, commonly used format.</li>
    </ul>
    <p>To exercise these rights, contact us using the information below.</p>
</section>

<section id="children" class="doc-section">
    <h2>8. Children’s Privacy</h2>
    <p>Our services are not intended for children under 13. We do not knowingly collect personal information from minors. Contact us if you believe we have collected such data.</p>
</section>

<section id="changes" class="doc-section">
    <h2>9. Changes to This Privacy Policy</h2>
    <p>We may update this policy from time to time. Changes will be posted on this page with an updated effective date. Please review this policy periodically.</p>
</section>

<section id="contact" class="doc-section">
    <h2>10. Contact Us</h2>
    <p>Questions about this Privacy Policy or our data practices?</p>
    <div class="doc-callout">
        <p class="doc-callout-title"><?php echo esc_html(get_bloginfo('name')); ?></p>
        <ul>
            <li>Email: <a href="mailto:<?php echo esc_html(ccr_site_config('contact.support_email')); ?>"><?php echo esc_html(ccr_site_config('contact.support_email')); ?></a></li>
            <li>Phone: <a href="tel:<?php echo esc_attr(ccr_site_config('contact.phone')); ?>"><?php echo esc_html(ccr_site_config('contact.phone_display')); ?></a></li>
            <li>Website: <a href="{{ config('app.url') }}">{{ parse_url(config('app.url'), PHP_URL_HOST) ?: 'christocentricrentals.com' }}</a></li>
            <li>Address: <?php echo esc_html(ccr_site_config('contact.address')); ?>, <?php echo esc_html(ccr_site_config('contact.city')); ?></li>
        </ul>
    </div>
</section>
