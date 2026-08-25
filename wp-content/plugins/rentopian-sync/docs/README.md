# Rentopian Sync — Documentation

This folder contains feature-level documentation for the Rentopian Sync plugin.

## Contents

| Document | Description |
|----------|-------------|
| [checkout/README.md](checkout/README.md) | **Modern Checkout Builder** — Architecture, configuration, visual builder, validation, and how to extend. |
| [checkout/WORKFLOWS.md](checkout/WORKFLOWS.md) | Checkout Builder — Step-by-step workflows (admin save, frontend render, validation, thank-you). |
| [options/README.md](options/README.md) | **Rental Options System** — Product and set options architecture, database schema, and API. |
| [options/WORKFLOWS.md](options/WORKFLOWS.md) | Options System — Data flows for sync, selection, cart calculation, and order creation. |
| [file-sync/README.md](file-sync/README.md) | **Background File Sync** — Architecture, class reference, database schema, REST/AJAX endpoints, error handling, and testing checklist. |
| [file-sync/WORKFLOWS.md](file-sync/WORKFLOWS.md) | File Sync — Step-by-step workflows for every sync operation (start, chunk processing, cancel, resume, retry). |
| [data-sync/README.md](data-sync/README.md) | **Background Data Sync** — Chunked catalog sync (products, variants, sets, taxonomies): architecture, phases, class reference, endpoints, admin UI, sweep safety, and email reporting. |
| [data-sync/WORKFLOWS.md](data-sync/WORKFLOWS.md) | Data Sync — Step-by-step workflows for every phase (start, chunk processing, staging, sweep, chaining the file sync, cancel, reporting). |
| [page-cache/README.md](page-cache/README.md) | **Page Cache Guard** — Keeps the date / add-to-cart gate correct when a product page is reused from a browser, proxy or plugin cache. |
| [attribute-groups/README.md](attribute-groups/README.md) | **Attribute Value Groups** — Group storage, the membership webhook and full pull, and the grouped filter facet. |
| [translation/README.md](translation/README.md) | **Auto-Translation (Polylang)** — Architecture, class reference, database schema, cache, usage tracking, image propagation, logging, and testing checklist. |
| [translation/WORKFLOWS.md](translation/WORKFLOWS.md) | Translation — Step-by-step workflows for bulk sync, webhooks, queue processing, cache lookups, image propagation. |

## Conventions

- Documentation is written so that **any developer** can understand the feature without prior knowledge of the codebase.
- **Examples** are included where they clarify usage (e.g. JSON snippets, hook usage).
- When **new features** are added, the relevant doc (e.g. `checkout/README.md`) should be updated; optional subsections: *Changelog*, *New in 2.x*.
- File and option names are **literal** (e.g. `rental_checkout_layout_config`) so they can be searched in the codebase.

## Suggesting a location for new docs

- **New feature (e.g. “Wishlist Builder”)** → add `docs/wishlist/README.md` and link it from this README.
- **Checkout sub-feature (e.g. “Thank-you page”)** → add a section or new file under `docs/checkout/` and link from `checkout/README.md`.
