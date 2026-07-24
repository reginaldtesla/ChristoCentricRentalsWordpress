<?php
/** Auto-synced from Laravel pages/partials/terms-body.blade.php */
?>
<p class="doc-date">Effective date: February 6, 2025</p>

<section class="doc-section">
    <h2>Introduction</h2>
    <p class="doc-lead">
        Welcome to Christocentric Rentals. By accessing and using our website (the “Site”) and renting equipment through our services, you agree to comply with and be bound by the following Terms and Conditions (“Terms”). Please read these Terms carefully before using our services.
    </p>
</section>

<section id="definitions" class="doc-section">
    <h2>1. Definitions</h2>
    <ul>
        <li><strong>“Site”</strong> — christocentricrentals.com and all associated services.</li>
        <li><strong>“User”, “You”, “Your”</strong> — the individual or entity using the Site to rent equipment.</li>
        <li><strong>“We”, “Us”, “Our”</strong> — Christocentric Rentals, the provider of rental services.</li>
        <li><strong>“Equipment”</strong> — photographic or video equipment available for rent through our website.</li>
        <li><strong>“Rental Agreement”</strong> — the agreement you enter into when renting equipment through our Site.</li>
    </ul>
</section>

<section id="account" class="doc-section">
    <h2>2. Account Registration</h2>
    <p>To rent equipment, you must create an account with accurate and complete information. You agree to keep your account information up to date.</p>
    <p><strong>Eligibility:</strong> You must be at least 18 years old to create an account and rent equipment.</p>
</section>

<section id="rental" class="doc-section">
    <h2>3. Rental Process</h2>
    <ul>
        <li><strong>Equipment availability</strong> — subject to change; we make reasonable efforts to honor your requested dates.</li>
        <li><strong>Rental period</strong> — begins at pickup or shipment and ends on the date in your Rental Agreement.</li>
        <li><strong>Reservation</strong> — select items, dates, and payment on the Site; confirmation is sent by email.</li>
    </ul>
</section>

<section id="fees" class="doc-section">
    <h2>4. Rental Fees and Payment</h2>
    <ul>
        <li><strong>Rental fee</strong> — based on equipment and duration; final price shown before booking.</li>
        <li><strong>Payment</strong> — due in full at booking via methods listed on the Site.</li>
        <li><strong>Security deposit</strong> — may be required for certain equipment; amount communicated before checkout.</li>
        <li><strong>Late fees</strong> — charged if equipment is not returned by the due date.</li>
        <li><strong>Damaged or lost equipment</strong> — you agree to reimburse repair or replacement costs.</li>
    </ul>
</section>

<section id="care" class="doc-section">
    <h2>5. Equipment Use and Care</h2>
    <ul>
        <li><strong>Usage</strong> — lawful purposes only, per manufacturer guidelines.</li>
        <li><strong>Care</strong> — reasonable care during the rental period; you are responsible for damage.</li>
        <li><strong>Return condition</strong> — return in the same condition as rented, subject to normal wear.</li>
    </ul>
</section>

<section id="shipping" class="doc-section">
    <h2>6. Shipping and Delivery</h2>
    <ul>
        <li><strong>Shipping</strong> — where applicable, to the address provided at booking.</li>
        <li><strong>Delivery/collection</strong> — pickup at our designated location or delivery where offered.</li>
        <li><strong>Return shipping</strong> — your responsibility unless otherwise agreed.</li>
    </ul>
</section>

<section id="cancellation" class="doc-section">
    <h2>7. Cancellation and Refund Policy</h2>
    <ul>
        <li><strong>Cancellation</strong> — at least 48 hours before rental start for a full refund (minus processing fees); later cancellations may incur a fee.</li>
        <li><strong>Refunds</strong> — processed to the original payment method; no refund for unused days on early return.</li>
    </ul>
</section>

<section id="liability" class="doc-section">
    <h2>8. Limitation of Liability</h2>
    <ul>
        <li><strong>Limitation</strong> — we are not responsible for injury, damage, loss, or expense from use of rented equipment.</li>
        <li><strong>Indemnification</strong> — you agree to hold us harmless from claims arising from your use of rented equipment.</li>
    </ul>
</section>

<section id="insurance" class="doc-section">
    <h2>9. Insurance</h2>
    <p>Optional insurance may be available at booking. Without it, you remain fully responsible for damage or loss.</p>
</section>

<section id="privacy" class="doc-section">
    <h2>10. Privacy and Data Protection</h2>
    <ul>
        <li><strong>Personal data</strong> — collected per our <a href="<?php echo esc_url(home_url('/privacy/')); ?>">Privacy Policy</a>.</li>
        <li><strong>Third-party services</strong> — payment and delivery partners may process data under their own policies.</li>
    </ul>
</section>

<section id="prohibited" class="doc-section">
    <h2>11. Prohibited Activities</h2>
    <p>You agree not to:</p>
    <ul>
        <li>Use equipment for illegal purposes.</li>
        <li>Sublet or transfer equipment without our consent.</li>
        <li>Tamper with or alter rented equipment.</li>
    </ul>
</section>

<section id="termination" class="doc-section">
    <h2>12. Termination of Service</h2>
    <p>We may suspend or terminate your access if you violate these Terms or engage in fraudulent or unlawful activity.</p>
</section>

<section id="force-majeure" class="doc-section">
    <h2>13. Force Majeure</h2>
    <p>We are not liable for failure to perform due to events beyond our control, including natural disasters or government actions.</p>
</section>

<section id="law" class="doc-section">
    <h2>14. Governing Law and Dispute Resolution</h2>
    <ul>
        <li><strong>Governing law</strong> — laws of the jurisdiction in which Christocentric Rentals operates.</li>
        <li><strong>Dispute resolution</strong> — informal negotiations first, then binding arbitration if needed.</li>
    </ul>
</section>

<section id="modifications" class="doc-section">
    <h2>15. Modifications to Terms and Conditions</h2>
    <p>We may update these Terms at any time. Continued use of the Site after changes constitutes acceptance.</p>
</section>

<section id="contact" class="doc-section">
    <h2>16. Contact Information</h2>
    <div class="doc-callout">
        <p class="doc-callout-title"><?php echo esc_html(get_bloginfo('name')); ?></p>
        <ul>
            <li>Email: <a href="mailto:<?php echo esc_html(ccr_site_config('contact.support_email')); ?>"><?php echo esc_html(ccr_site_config('contact.support_email')); ?></a></li>
            <li>Phone: <a href="tel:<?php echo esc_attr(ccr_site_config('contact.phone')); ?>"><?php echo esc_html(ccr_site_config('contact.phone_display')); ?></a></li>
            <li>Website: <a href="{{ config('app.url') }}">christocentricrentals.com</a></li>
            <li>Address: <?php echo esc_html(ccr_site_config('contact.address')); ?>, <?php echo esc_html(ccr_site_config('contact.city')); ?></li>
        </ul>
    </div>
</section>
