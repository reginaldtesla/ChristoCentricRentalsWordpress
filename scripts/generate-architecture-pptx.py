"""Generate Christocentric Rentals architecture presentation."""
from pptx import Presentation
from pptx.util import Inches, Pt
from pptx.dml.color import RGBColor
from pptx.enum.text import PP_ALIGN
from pptx.enum.shapes import MSO_SHAPE

# Brand palette (matches storefront primary ~#1e73be)
NAVY = RGBColor(0x0F, 0x1C, 0x2E)
BLUE = RGBColor(0x1E, 0x73, 0xBE)
LIGHT_BLUE = RGBColor(0xE8, 0xF1, 0xF8)
WHITE = RGBColor(0xFF, 0xFF, 0xFF)
SLATE = RGBColor(0x37, 0x41, 0x51)
MUTED = RGBColor(0x6B, 0x72, 0x80)
GREEN = RGBColor(0x05, 0x96, 0x69)
AMBER = RGBColor(0xB4, 0x53, 0x09)

prs = Presentation()
prs.slide_width = Inches(13.333)
prs.slide_height = Inches(7.5)


def set_run(run, size=18, bold=False, color=SLATE, font="Calibri"):
    run.font.size = Pt(size)
    run.font.bold = bold
    run.font.color.rgb = color
    run.font.name = font


def add_bg(slide, color):
    shape = slide.shapes.add_shape(
        MSO_SHAPE.RECTANGLE, Inches(0), Inches(0), prs.slide_width, prs.slide_height
    )
    shape.fill.solid()
    shape.fill.fore_color.rgb = color
    shape.line.fill.background()
    # send to back
    spTree = slide.shapes._spTree
    sp = shape._element
    spTree.remove(sp)
    spTree.insert(2, sp)
    return shape


def add_accent_bar(slide):
    bar = slide.shapes.add_shape(
        MSO_SHAPE.RECTANGLE, Inches(0), Inches(0), Inches(0.12), prs.slide_height
    )
    bar.fill.solid()
    bar.fill.fore_color.rgb = BLUE
    bar.line.fill.background()


def add_footer(slide, page, total=12):
    box = slide.shapes.add_textbox(Inches(0.5), Inches(7.05), Inches(10), Inches(0.3))
    tf = box.text_frame
    p = tf.paragraphs[0]
    run = p.add_run()
    run.text = f"Christocentric Rentals  ·  Architecture & MVP  ·  {page}/{total}"
    set_run(run, 11, False, MUTED)
    num = slide.shapes.add_textbox(Inches(11.5), Inches(7.05), Inches(1.5), Inches(0.3))
    tf2 = num.text_frame
    p2 = tf2.paragraphs[0]
    p2.alignment = PP_ALIGN.RIGHT
    run2 = p2.add_run()
    run2.text = "christocentricrentals.com"
    set_run(run2, 11, False, MUTED)


def title_slide():
    slide = prs.slides.add_slide(prs.slide_layouts[6])
    add_bg(slide, NAVY)
    # accent stripe
    stripe = slide.shapes.add_shape(
        MSO_SHAPE.RECTANGLE, Inches(0), Inches(5.8), prs.slide_width, Inches(1.7)
    )
    stripe.fill.solid()
    stripe.fill.fore_color.rgb = BLUE
    stripe.line.fill.background()

    t = slide.shapes.add_textbox(Inches(0.8), Inches(2.0), Inches(11.5), Inches(1.2))
    p = t.text_frame.paragraphs[0]
    run = p.add_run()
    run.text = "Christocentric Rentals"
    set_run(run, 44, True, WHITE, "Calibri")

    s = slide.shapes.add_textbox(Inches(0.8), Inches(3.2), Inches(11.5), Inches(0.8))
    p = s.text_frame.paragraphs[0]
    run = p.add_run()
    run.text = "Architecture Decisions · API Schema · MVP"
    set_run(run, 26, False, LIGHT_BLUE, "Calibri")

    m = slide.shapes.add_textbox(Inches(0.8), Inches(6.15), Inches(11.5), Inches(0.9))
    tf = m.text_frame
    p = tf.paragraphs[0]
    run = p.add_run()
    run.text = "WordPress + WooCommerce rebuild  ·  Ghana · GHS  ·  Hostinger"
    set_run(run, 16, False, WHITE)
    p2 = tf.add_paragraph()
    run2 = p2.add_run()
    run2.text = "Production: christocentricrentals.com  ·  July 2026"
    set_run(run2, 14, False, LIGHT_BLUE)


def section_slide(title, subtitle=""):
    slide = prs.slides.add_slide(prs.slide_layouts[6])
    add_bg(slide, NAVY)
    add_accent_bar(slide)
    t = slide.shapes.add_textbox(Inches(0.8), Inches(2.8), Inches(11.5), Inches(1))
    p = t.text_frame.paragraphs[0]
    run = p.add_run()
    run.text = title
    set_run(run, 36, True, WHITE)
    if subtitle:
        s = slide.shapes.add_textbox(Inches(0.8), Inches(3.9), Inches(11.5), Inches(0.8))
        p = s.text_frame.paragraphs[0]
        run = p.add_run()
        run.text = subtitle
        set_run(run, 18, False, LIGHT_BLUE)
    return slide


def content_slide(title):
    slide = prs.slides.add_slide(prs.slide_layouts[6])
    add_bg(slide, WHITE)
    add_accent_bar(slide)
    # top rule
    rule = slide.shapes.add_shape(
        MSO_SHAPE.RECTANGLE, Inches(0.5), Inches(1.05), Inches(12.3), Inches(0.04)
    )
    rule.fill.solid()
    rule.fill.fore_color.rgb = LIGHT_BLUE
    rule.line.fill.background()

    t = slide.shapes.add_textbox(Inches(0.5), Inches(0.35), Inches(12), Inches(0.6))
    p = t.text_frame.paragraphs[0]
    run = p.add_run()
    run.text = title
    set_run(run, 28, True, NAVY)
    return slide


def add_bullets(slide, items, left=0.5, top=1.3, width=12, size=18):
    box = slide.shapes.add_textbox(Inches(left), Inches(top), Inches(width), Inches(5.4))
    tf = box.text_frame
    tf.word_wrap = True
    for i, item in enumerate(items):
        p = tf.paragraphs[0] if i == 0 else tf.add_paragraph()
        p.level = 0
        p.space_after = Pt(10)
        run = p.add_run()
        run.text = "•  " + item
        set_run(run, size, False, SLATE)


def add_card(slide, left, top, width, height, title, body_lines, accent=BLUE):
    card = slide.shapes.add_shape(
        MSO_SHAPE.ROUNDED_RECTANGLE, Inches(left), Inches(top), Inches(width), Inches(height)
    )
    card.fill.solid()
    card.fill.fore_color.rgb = LIGHT_BLUE
    card.line.fill.background()
    # left accent
    a = slide.shapes.add_shape(
        MSO_SHAPE.RECTANGLE, Inches(left), Inches(top), Inches(0.08), Inches(height)
    )
    a.fill.solid()
    a.fill.fore_color.rgb = accent
    a.line.fill.background()

    t = slide.shapes.add_textbox(
        Inches(left + 0.25), Inches(top + 0.15), Inches(width - 0.4), Inches(0.4)
    )
    p = t.text_frame.paragraphs[0]
    run = p.add_run()
    run.text = title
    set_run(run, 16, True, NAVY)

    b = slide.shapes.add_textbox(
        Inches(left + 0.25), Inches(top + 0.55), Inches(width - 0.4), Inches(height - 0.7)
    )
    tf = b.text_frame
    tf.word_wrap = True
    for i, line in enumerate(body_lines):
        p = tf.paragraphs[0] if i == 0 else tf.add_paragraph()
        p.space_after = Pt(4)
        run = p.add_run()
        run.text = line
        set_run(run, 13, False, SLATE)


# ---- Build slides ----
title_slide()

# Agenda
s = content_slide("Agenda")
add_bullets(
    s,
    [
        "Problem & goals for the rebuild",
        "System architecture at a glance",
        "Key architecture decisions (ADRs)",
        "Data model & order lifecycle",
        "API / integration schema (MVP)",
        "Payments, tax & email",
        "MVP scope vs deferred",
        "Go-live checklist & next steps",
    ],
    size=20,
)
add_footer(s, 2)

# Problem
s = content_slide("Problem & goals")
add_card(
    s,
    0.5,
    1.3,
    6.0,
    5.2,
    "What we needed",
    [
        "Daily camera / gear rentals in GHS",
        "Online pay (card / MoMo) + cash pickup",
        "Staff-friendly booking ops",
        "Stock holds without double-booking",
        "Ghana tax (VAT + NHIL + GETFund)",
        "Reliable order emails on Hostinger",
        "Familiar admin for the business",
    ],
)
add_card(
    s,
    6.8,
    1.3,
    6.0,
    5.2,
    "What we shipped",
    [
        "WordPress + WooCommerce storefront",
        "Custom christocentric theme",
        "christocentric-rentals domain plugin",
        "Paystack + pay-on-pickup gateway",
        "ACF homepage CMS + Rank Math SEO",
        "Laravel kept as export/reference only",
        "Live: christocentricrentals.com",
    ],
    accent=GREEN,
)
add_footer(s, 3)

# Architecture overview
s = content_slide("System architecture")
add_card(
    s,
    0.5,
    1.25,
    4.0,
    2.4,
    "Presentation",
    ["Theme: christocentric", "Shop, pages, product cards", "Homepage CMS (ACF)"],
)
add_card(
    s,
    4.7,
    1.25,
    4.0,
    2.4,
    "Domain",
    ["Plugin: christocentric-rentals", "Dates, holds, pickup, SMTP", "Newsletter, late returns"],
    accent=BLUE,
)
add_card(
    s,
    8.9,
    1.25,
    4.0,
    2.4,
    "Commerce",
    ["WooCommerce core", "Catalog, cart, checkout", "Orders, stock, Ghana tax"],
    accent=NAVY,
)
add_card(
    s,
    0.5,
    3.9,
    4.0,
    2.4,
    "Payments",
    ["woo-paystack", "Card / Mobile Money", "Webhook confirmation"],
    accent=GREEN,
)
add_card(
    s,
    4.7,
    3.9,
    4.0,
    2.4,
    "Hosting",
    ["Hostinger (PHP 8.1+)", "MySQL + SSL", "Mailbox SMTP"],
)
add_card(
    s,
    8.9,
    3.9,
    4.0,
    2.4,
    "Optional",
    ["Rentopian sync", "Mailchimp / webhooks", "Not required for MVP"],
    accent=AMBER,
)
add_footer(s, 4)

# ADR section
section_slide("Architecture decisions", "Accepted ADRs that shaped the MVP")

s = content_slide("Key decisions (1/2)")
add_bullets(
    s,
    [
        "ADR-001 — Ship WordPress/WooCommerce in production; Laravel = export only",
        "ADR-002 — Orders are bookings (no separate booking database)",
        "ADR-003 — Dual payments: Paystack online + pay on pickup (cash)",
        "ADR-004 — Tax via WooCommerce rates; shop base address (pickup-friendly)",
        "ADR-005 — Rentopian optional — empty API key, store still runs",
    ],
    size=18,
)
add_footer(s, 6)

s = content_slide("Key decisions (2/2)")
add_bullets(
    s,
    [
        "ADR-006 — No custom public REST API for MVP (AJAX / admin-post instead)",
        "ADR-007 — Lean plugins only (no Jetpack / MailPoet / ads bloat)",
        "ADR-008 — Guest checkout off — account required",
        "ADR-009 — SMTP required on Hostinger for order & reset emails",
        "Market fixed: Ghana · GHS · Bomso pickup narrative",
    ],
    size=18,
)
add_footer(s, 7)

# Data + lifecycle
s = content_slide("Data model (MVP)")
add_card(
    s,
    0.5,
    1.25,
    4.0,
    5.3,
    "Products",
    [
        "_ccr_price_per_day",
        "_ccr_sale_price_per_day",
        "_ccr_is_featured / _ccr_is_kit",
        "WC stock = inventory",
        "Optional Rentopian ID",
    ],
)
add_card(
    s,
    4.7,
    1.25,
    4.0,
    5.3,
    "Order lines",
    [
        "Start / end rental dates",
        "Pickup & return times",
        "Days + rate snapshot",
        "Returned_at + late penalty",
    ],
    accent=BLUE,
)
add_card(
    s,
    8.9,
    1.25,
    4.0,
    5.3,
    "Tax & holds",
    [
        "VAT 15% + NHIL 2.5%",
        "+ GETFund 2.5% = 20%",
        "Pickup hold: 72 hours",
        "Online hold: 2 hours",
        "Hourly expiry cron",
    ],
    accent=GREEN,
)
add_footer(s, 8)

s = content_slide("Order lifecycle")
add_bullets(
    s,
    [
        "Paystack → charge / webhook → payment_complete → processing",
        "Pay on pickup → on-hold (stock held) → staff “Mark paid” → processing",
        "Unpaid holds past window → cancelled by cron",
        "Staff “Mark returned” → returned_at + optional late penalty",
        "Customer emails: on-hold / processing / confirmation (needs SMTP)",
        "Admin: New order email for every booking path",
    ],
    size=18,
)
add_footer(s, 9)

# API
s = content_slide("API & integrations (MVP)")
add_bullets(
    s,
    [
        "Storefront: admin-ajax / admin-post (quote, compare, contact, newsletter)",
        "Paystack webhook: /?wc-api=Tbz_WC_Paystack_Webhook  (required live)",
        "Admin: Ghana tax setup, SMTP test, mark returned, mark paid",
        "Outbound optional: Rentopian POST /orders, Mailchimp, newsletter webhook",
        "No custom public REST/GraphQL for MVP — future ADR if apps need it",
    ],
    size=18,
)
add_footer(s, 10)

# MVP
s = content_slide("MVP scope")
add_card(
    s,
    0.5,
    1.25,
    6.0,
    5.3,
    "In MVP — shipped",
    [
        "Catalog, daily pricing, kits, search",
        "Dates + availability + holds",
        "Paystack + pay on pickup",
        "Ghana tax itemized at checkout",
        "Theme, pages, compare, Studio",
        "Homepage CMS, SEO, redirects",
        "Order ops + late returns",
        "SMTP settings + branded emails",
    ],
    accent=GREEN,
)
add_card(
    s,
    6.8,
    1.25,
    6.0,
    5.3,
    "Deferred / optional",
    [
        "Rentopian live API key",
        "Mailchimp / webhook mirroring",
        "Public versioned booking REST API",
        "Guest checkout",
        "Multi-currency / multi-country",
        "Full calendar SaaS inventory",
        "Marketing plugin bloat",
    ],
    accent=AMBER,
)
add_footer(s, 11)

# Go-live
s = content_slide("Go-live checklist")
add_bullets(
    s,
    [
        "SMTP credentials on Hostinger — prove order + password-reset mail",
        "Paystack live keys + webhook URL configured",
        "End-to-end test: Paystack path + pay-on-pickup path",
        "Purge LiteSpeed / CDN after deploy; spot-check images & redirects",
        "Keep cart / checkout / account excluded from full-page cache",
        "Reference doc: ARCHITECTURE.md in the project root",
    ],
    size=18,
)
add_footer(s, 12)

# Closing
slide = prs.slides.add_slide(prs.slide_layouts[6])
add_bg(slide, NAVY)
stripe = slide.shapes.add_shape(
    MSO_SHAPE.RECTANGLE, Inches(0), Inches(0), Inches(0.12), prs.slide_height
)
stripe.fill.solid()
stripe.fill.fore_color.rgb = BLUE
stripe.line.fill.background()

t = slide.shapes.add_textbox(Inches(0.8), Inches(2.4), Inches(11.5), Inches(1))
p = t.text_frame.paragraphs[0]
run = p.add_run()
run.text = "Questions?"
set_run(run, 44, True, WHITE)

s = slide.shapes.add_textbox(Inches(0.8), Inches(3.6), Inches(11.5), Inches(1.5))
tf = s.text_frame
p = tf.paragraphs[0]
run = p.add_run()
run.text = "Full write-up: ARCHITECTURE.md"
set_run(run, 20, False, LIGHT_BLUE)
p2 = tf.add_paragraph()
run2 = p2.add_run()
run2.text = "Live store: https://christocentricrentals.com"
set_run(run2, 18, False, WHITE)
p3 = tf.add_paragraph()
run3 = p3.add_run()
run3.text = "Christocentric Rentals  ·  Bomso, Kumasi  ·  GHS"
set_run(run3, 16, False, MUTED)

out = r"C:\laragon\www\ChristoCentricRentalsWordpress\Christocentric-Rentals-Architecture-MVP.pptx"
prs.save(out)
print(out)
