<?php
/** Auto-synced from Laravel pages/partials/help-body.blade.php */
?>
<div class="doc-intro">
    <p class="doc-lead">
        At Christocentric Rentals, we strive to offer an exceptional experience for all our customers. Whether you need assistance with renting equipment, have questions about our services, or need troubleshooting advice, our support team is here to help.
    </p>
</div>

<section id="rent" class="doc-section">
    <h2>1. How to Rent Equipment</h2>
    <p>Renting equipment from Christocentric Rentals is straightforward:</p>
    <ol>
        <li><strong>Browse our catalog</strong> — filter by category on the <a href="<?php echo esc_url(ccr_shop_url()); ?>">shop page</a>.</li>
        <li><strong>Select your items</strong> — choose pickup and return dates, then add to cart.</li>
        <li><strong>Reserve and pay</strong> — sign in and complete checkout.</li>
        <li><strong>Confirmation</strong> — you will receive email confirmation with pickup details.</li>
        <li><strong>Pick up your equipment</strong> — collect at our Bomso location near Abesse Gaming Center in Kumasi.</li>
    </ol>
</section>

<section id="terms" class="doc-section">
    <h2>2. Rental Terms &amp; Conditions</h2>
    <p>Please review these points before booking:</p>
    <ul>
        <li><strong>Rental duration</strong> — flexible daily and weekly rates depending on the item.</li>
        <li><strong>Deposit</strong> — a refundable security deposit may be required for some equipment.</li>
        <li><strong>Late returns</strong> — additional fees apply if equipment is returned after the due date.</li>
        <li><strong>Damage or loss</strong> — you are responsible during the rental period; repair or replacement costs may apply.</li>
    </ul>
    <p>See our full <a href="<?php echo esc_url(home_url('/terms/')); ?>">Terms &amp; Conditions</a> for complete details.</p>
</section>

<section id="pickup" class="doc-section">
    <h2>3. Pickup and Return of Equipment</h2>
    <p><strong>Pickup location:</strong> <?php echo esc_html(ccr_site_config('contact.address')); ?>, <?php echo esc_html(ccr_site_config('contact.city')); ?>.</p>
    <p><strong>Returns:</strong> bring equipment back to the same location on the due date, during business hours.</p>
</section>

<section id="payment" class="doc-section">
    <h2>4. Payment Methods</h2>
    <ul>
        <li><strong>Credit/debit cards</strong> — Visa, Mastercard, and other major cards via online checkout.</li>
        <li><strong>Mobile money</strong> — available for customers who prefer mobile payment.</li>
        <li><strong>Bank transfers</strong> — for larger bookings; <a href="<?php echo esc_url(home_url('/contact/')); ?>">contact us</a> for details.</li>
    </ul>
</section>

<section id="changes" class="doc-section">
    <h2>5. Cancellation and Modifications</h2>
    <ul>
        <li><strong>Cancellations</strong> — cancel up to 48 hours before pickup for a full refund (minus processing fees).</li>
        <li><strong>Modifications</strong> — contact us as soon as possible to change dates or swap equipment.</li>
    </ul>
</section>

<section id="technical" class="doc-section">
    <h2>6. Technical Support</h2>
    <p>If you have issues during your rental:</p>
    <ul>
        <li><strong>Troubleshooting</strong> — help with common camera, lens, and lighting problems.</li>
        <li><strong>Replacement</strong> — contact us immediately if equipment is faulty.</li>
    </ul>
</section>

<section id="service" class="doc-section">
    <h2>7. Customer Service</h2>
    <p>Reach our team for rentals, returns, or general questions:</p>
    <div class="doc-callout">
        <p class="doc-callout-title"><?php echo esc_html(get_bloginfo('name')); ?></p>
        <ul>
            <li>Email: <a href="mailto:<?php echo esc_html(ccr_site_config('contact.support_email')); ?>"><?php echo esc_html(ccr_site_config('contact.support_email')); ?></a></li>
            <li>Phone: <a href="tel:<?php echo esc_attr(ccr_site_config('contact.phone')); ?>"><?php echo esc_html(ccr_site_config('contact.phone_display')); ?></a></li>
            <li>Web: <a href="<?php echo esc_url(home_url('/contact/')); ?>">Contact us</a></li>
        </ul>
    </div>
    <p>We aim to respond within 24 hours on business days.</p>
</section>

<section id="faq" class="doc-section">
    <h2>8. FAQ</h2>
    <p>For quick answers, see our <a href="<?php echo esc_url(home_url('/faq/')); ?>">FAQ page</a>.</p>
</section>

<section id="insurance" class="doc-section">
    <h2>9. Insurance Options</h2>
    <p>Insurance may be available at booking to help cover accidental damage or loss. Without insurance, you are responsible for the full cost of repairs or replacement.</p>
</section>

<section id="maintenance" class="doc-section">
    <h2>10. Equipment Maintenance and Quality Assurance</h2>
    <p>All gear is cleaned, inspected, and tested before each rental. If you notice any issue, let us know right away.</p>
</section>

<section id="feedback" class="doc-section">
    <h2>11. Feedback and Suggestions</h2>
    <p>Email <a href="mailto:<?php echo esc_html(ccr_site_config('contact.feedback_email')); ?>"><?php echo esc_html(ccr_site_config('contact.feedback_email')); ?></a> or use the <a href="<?php echo esc_url(home_url('/contact/')); ?>">contact form</a>.</p>
</section>

<section id="location" class="doc-section">
    <h2>12. Location Assistance</h2>
    <p>Need directions to our Bomso office? Call <a href="tel:<?php echo esc_attr(ccr_site_config('contact.phone')); ?>"><?php echo esc_html(ccr_site_config('contact.phone_display')); ?></a> or <a href="<?php echo esc_url(home_url('/contact/')); ?>">message us</a>.</p>
</section>
