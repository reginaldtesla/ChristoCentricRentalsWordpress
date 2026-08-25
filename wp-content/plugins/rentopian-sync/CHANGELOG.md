#### Version 2.15.1 – Released on August 20, 2026
* Direct payment from google/apple payments on quotes fix
* Optional set item add to cart gate fix
* Product hidden price setting extended
* Product options bug fixes/improvements
* Dates validation settings and calendar UI bug fix


#### Version 2.15.0 – Released on August 7, 2026
* Background data synchronization bugs fixed.
* Added product page cache mechanism.
* Added repeated variant products names in attributes removal mechanism on modern layout of sets module.
* Formatted text save fix on TinyMCE.
* Product auto add on date selection settings/condition added.
* Added attribute value groups

#### Version 2.14.12 – Released on July 31, 2026
* Added background data synchronization.
* Added a Damage Waiver feature to the cart page, along with a configuration setting.
* Updated delivery settings to support adding conditions on direct payments.
* Fixed multiple bugs in the Sets module.
* Improved the Sets module.

#### Version 2.14.11 – Released on July 15, 2026
* Sets grouped items entity released

#### Version 2.14.10 – Released on June 25, 2026
* Stale start/end dates bug fix

#### Version 2.14.9 – Released on June 22, 2026
* Inventory blocks refactored/fixed
* Security deposit bug fix in online payments
* Zip code bug fix on dates auto apply
* Modern checkout validation fixed
* Modern checkout lables fixed
* Modern checkout docs updated
* File-Based Logging of Orders/Quotes process improved
* File-Based Logging for Cart Removal Actions and Incidents


#### Version 2.14.8 – Released on May 22, 2026
* File based logging of Orders/Quotes process

#### Version 2.14.7 – Released on May 21, 2026
* Delivery default auto switching fixed
* Pickup address validation fixed
* File delete sync/file sync modules fixed
* Image webhook edge case fix

#### Version 2.14.6 – Released on April 20, 2026
* Event date with offsets integrated (also available in modern checkout builder)
* Rental day tiers bug fixed
* Webhook images autoheal added

#### Version 2.14.5 – Released on April 8, 2026
* Added backward compatibility to translation module

#### Version 2.14.4 – Released on April 7, 2026
* Fixed “headers already sent” issue caused by UTF-8 BOM
* Improved UI/UX for product and variant add-ons
* Enhanced horizontal dates form UX
* Fixed the issue where only one product option exists that option gets selected as default
* Optimized translation module with cached database translations (for secondary langugages) on initial load
* Refactored file deletion sync into a separate module and improved its performance


#### Version 2.14.3 – Released on April 3, 2026
* Fixed issues with hidden products appearing in search results
* Resolved bugs in Local Pickup / Delivery settings
* Improved and optimized the Checkout Visual Builder, along with multiple bug fixes
* Added documentation for the background file sync feature
* Refactored the background file sync feature into a standalone module
* Introduced multi-division support for the file sync feature
* Enhanced Addons UI/UX and addressed related bugs
* Integrated Polylang with support for free translation APIs
* Optimized and improved horizontal date form functionality

#### Version 2.14.0 – Released on February 15, 2026
* Fixed modern checkout validation issues causing false required errors.
* Implemented photo upload feature on order submit.
* Implemented three-tier required-field logic (system, layout JSON, registry).
* Improved photo upload handling and review-order stability.
* Implemented the ability to hide/show different parts of the checkout page.

#### Version 2.13.0 – Released on February 9, 2026
* Fixed Product/Set options bugs.
* Product/Set options are now available theme independently.
* Event time component bug fix to sanitize input and extract only valid time format.
* Set item with variable items bug fix.

#### Version 2.12.4 – Released on January 7, 2026

* Fixed variant name display issue in Set items.
* Fixed variant image display issue for Set items.
* Resolved minor bug affecting the “Add to Cart” functionality.
* Fixed Calentim calendar sync issue (input and calendar dates now match).
* Implemented automatic recovery mechanism for failed orders.

#### Version 2.12.3 – Released on December 16, 2025

* Settings page minor bugs fixed and UI/UX improvements added
* Calendar disabled week days issue fix

#### Version 2.12.2 – Released on December 08, 2025

* Web hooks bug fix
* Delivery options(allow both) bug fix

#### Version 2.12.1 – Released on November 29, 2025

* Resume capability added to File Background Sync for large inventories
* File Background Sync meta data creation improved
* Bug fixes and improvements done to File Background Sync for large inventories
* Added File Background Delete mechanism
* Allow both shipping method improved (adds a placeholder when there is no customer address and etc.)
* Stemmed search improved (hides addons and hidden products)
* Hide set items in cart/checkout pages (from plugin)


#### Version 2.12.0 – Released on Octobor 21, 2025

* Added File Background Sync for large inventories
* Improved cart/checkout to hide set items (from plugin)
* Fixed unique handling for parent/sub categories
* Fixed pickup address selection condition
* Minor bug fix for product options
* Minor bug fix for webhooks

#### Version 2.11.14 – Released on Octobor 5, 2025

* Fixed bug with optional items in set items
* Fixed bug with hidden items in product addons

#### Version 2.11.13.1 – Released on September 28, 2025

* Auto tax validation fixed

#### Version 2.11.13 – Released on September 28, 2025

* Delivery to different address option setting feature added in Rentopian system
* All the shipping/delivery settings are moved to Rentopian system
* Add to cart functionallity optimized/improved

#### Version 2.11.12.3 – Released on September 21, 2025

* Set items maximum quantity integration
* Minor bug fixes and improvements

#### Version 2.11.12.2 – Released on September 9, 2025

* Coupon calculation bug fix

#### Version 2.11.12.1 – Released on September 7, 2025

* Minor bug fixes
* Disable product reviews on plugin activation and synchronization
* Invalidate product listing cache on synchronization

#### Version 2.11.12 – Released on August 26, 2025

* Tax calculations integrated/updated/fixed
* Coupon calculations bug fixes
* Auto applied fees fixed
* Event Types integrated
* Stemmed search feature added 

#### Version 2.11.11 – Released on July 15, 2025

* Plugin updater fix
* Minor bug fixes

#### Version 2.11.10 – Released on June 30, 2025

* Added process to remove orphaned/unattached files (e.g. from failed synchronization or sync type changes)
* Fixed bug in coupon usage logic


#### Version 2.11.9 – Released on May 29, 2025

* Custom related products and upsell products functionality for Rentpro theme single product pages  
* Custom shortcode for latest, random, specific category and etc. products carousel
* Custom search popup component to use with Renpro theme upon search icon click
* Ability to add a textual label for the rental start/end dates form  
* Ability to add an alternative textual label for the street address field on the checkout page  
* Fixed Font Awesome icon display issue related to the Eventorian theme  
* Fixed a bug affecting delivery price calculation  

#### Version 2.11.8 – Released on May 27, 2025

* Time range selection is now supported on the checkout page. If available, customers can select preferred delivery and pickup windows by specifying start and end times.

#### Version 2.11.7 – Released on May 12, 2025

* Added support for user-defined pickup addresses (different that shipping/billing address) on the checkout page. The system also checks for matching venues for the pickup address and prioritizes venue-based delivery rates; otherwise, it uses advanced delivery cost calculations.

#### Version 2.11.6 – Released on May 10, 2025

* The plugin's delivery system now supports advanced calculation types: by distance, by order total, and by both distance and order total. (These settings are managed from the Rentopian core system.)
* The order total-based delivery calculation method now includes three options: product subtotal, product raw subtotal for one day, and product subtotal for one day. (Configurable via the Rentopian core system.)
* Additional delivery charges based on order size (amount) are now calculated and added to the delivery fees.
* All existing plugin-side delivery settings are now managed through the Rentopian core system's delivery settings section.
* Introduced two types of file synchronization processes to make file sync/re-sync more flexible and faster.
* Fixed issues related to syncing product dimensions and SKUs via synchronization/webhooks.
* Added the ability to edit the textual label of the "Billing Details" section on the Checkout page.
* Introduced a rich text editor for entering special terms.
* Special terms can now be displayed at the top or bottom of the Checkout page.
* Fixed the order offset option in the date/time picker calendar.
* Fixed a caching issue with the minimum order amount setting notice.
* Fixed caching issues related to plugin CSS files.


#### Version 2.9.0 Released on Dec 17, 2024

*   Expanded the supported functionality for sets.

#### Version 2.8.0 Released on Nov 4, 2024

*   Mile-based shipping fixes and custom label option.

#### Version 2.7.9 Released on Oct 1, 2024

*   Fixed a performance related bug related to variants with addons.
*   Fixed optional sets items not displaying bug (for non-addon products).

#### Version 2.7.8 Released on Aug 20, 2024

*   Fixed Google Matrix distance calculations for kilometrage based companies.

#### Version 2.7.7 Released on Jul 15, 2024

*   Fixed a bug with coupons that have special symbols not working correctly.
*   Added support for category-level banner images.

#### Version 2.7.6 Released on Jun 20, 2024

*   Fixed a bug with admin-side product filtering.
*   Got rid of sessions completely. The plugin now passes the Site Health Check with flying colors.

#### Version 2.7.5 Released on May 30, 2024

*   Added a setting to exclude delivery cost from taxable items.
*   Added synchronization for coupon description field.

#### Version 2.7.4 Released on May 02, 2024

*   Added support for up-sell and cross-sell products.
*   Added option for async loading of Google Maps for better performance.

#### Version 2.7.3 Released on Apr 15, 2024

*   Fixed a bug with indexing duplicate products when a company has multiple locations.
*   Added a template for Cancelled / Failed payment notifications.

#### Version 2.7.2 Released on Mar 20, 2024

*   Added support for WC Single Variants plugin.
*   Fixed issue with caching plugins.

#### Version 2.7.1 Released on Mar 05, 2024

*   Fixed an issue with set images not synchronizing if set has multiple images assigned.
*   Fixed an issue with add to cart functionality not working correctly in some edge cases.
*   Fixed a bug with blocked inventory items rendering.

#### Version 2.7.0 Released on Feb 11, 2024

*   Fixed issue with same name tags applied to both sets and products.
*   Fixed issue with stock quantity counting incorrectly when location-merged stock calculation is used.

#### Version 2.6.9 Released on Jan 23, 2024

*   Added option to show referral sources select box on the checkout page.
*   Added \[rs\_products\] shortcode that mirrors the native \[products /\] shortcode but adds additional layer of filtering.
*   Fixed issues with auto tax.

#### Version 2.6.8 Released on Nov 15, 2023

*   Added option to show the calendar on checkout page only. This applies only if overbooks are set to allowed.
*   Added taxable option for rush fees.
*   Bug fixes concerning Google API keys.

#### Version 2.6.7 Released on Sep 30, 2023

*   Added support for custom categories sorting synchronization.

#### Version 2.6.6 Released on Aug 31, 2023

*   Created a feature for same product to show only once, in case of multi-location companies.

#### Version 2.6.5 Released on July 31, 2023

*   Added option to hide duplicate products when synchronizing with multiple locations.
*   Fixed real-time update issue with mini cart.
*   Fixed Husky and Products filter integration.
*   Added support for auto tax rate for damage waiver.

#### Version 2.6.4 Released on Jun 22, 2023

*   Added option to show the date picker and enforce rental dates selection on checkout page only.

#### Version 2.6.3 Released on Jun 14, 2023

*   Added default damage waiver tax option.
*   Shipping label text not updating bug fix.
*   Auto tax rate not working correctly bug fix.

#### Version 2.6.2 Released on May 20, 2023

*   Shipping zone synchronization bug fixes and improvements

#### Version 2.6.1 Released on Apr 28, 2023

*   Fixed the bug with start / end time still showing after setting it as hidden.
*   Fixed default product variation not being selected by default on the product single page.
*   Address autocomplete option improved.

#### Version 2.6.0 Released on Mar 11, 2023

*   Huge improvements in availability functionality. Now the unavailable products can be filtered from the main listing page based on the selected dates.
*   Added auto tax functionality support.
*   Added automated rental dates selection and filtering option.
*   Added product tags support for sets.
*   Bug fixes and design improvements.

#### Version 2.5.3 Released on Feb 1, 2023

*   Improved cookie handling.
*   Improved fly-in cart trigger functionality.

#### Version 2.5.2 Released on Jan 17, 2023

*   Fixed a bug with ZIP code not applying on checkout.

#### Version 2.5.1 Released on Jan 15, 2023

*   Added product filtering functionality based on availability. Now all the unavailable items will be hidden on the website right away.

#### Version 2.5.0 Released on Nov 1, 2022

*   Added wishlist functionality.
*   Added address autofill functionality (via Google Maps).

#### Version 2.4.0 Released on Sep 30, 2022

*   Added product options support.

#### Version 2.3.1 Released on Sep 1, 2022

*   Fixed mileage based shipping calculation / rendering issue.
*   Added support for blocked inventory items synchronization.

#### Version 2.3.0 Released on Aug 1, 2022

*   Now the plugin supports hourly rented products.

#### Version 2.1.0 Released on May 3,

#### Version 2.2.0 Released on May 11, 2022

*   Support for security deposits added.

#### Version 2.1.0 Released on May 3, 2022

*   Major Update - added compatibility with WP Rocket plugin.
*   Added native synchronization for coupons.

#### Version 2.0.9 Released on Mar 15, 2022

*   Removed state requirement for shipping options.
*   Fixed date range selection dropdown bug.

#### Version 2.0.8 Released on Feb 8, 2022

*   Minor bug fixes and improvements.

#### Version 2.0.7 Released on Jan 6, 2022

*   Added option to specify minimal order amount for delivery.

#### Version 2.0.6 Released on Dec 25, 2021

*   Added ability to calculate taxes based on the delivery address.

#### Version 2.0.5 Released on Dec 15, 2021

*   Fixed a bug with auto fee calculation.

#### Version 2.0.4 Released on Dec 6, 2021

*   Added support for shipping options synchronization in WordPress Multisite environment.

#### Version 2.0.3 Released on Dec 3, 2021

*   Small bug fixes.

#### Version 2.0.2 Released on Dec 1, 2021

*   Updated the shipping methods mechanism to use the Woocommerce native shipping logic.

#### Version 2.0.1 Released on Nov 14, 2021

*   Fixed bugs connected with setting the event time and the shipping method.

#### Version 2.0.0 Released on Nov 04, 2021

*   Updated the date picker, now its mobile friendliness is improved drastically.

#### Version 1.9.2 Released on Oct 04, 2021

*   Fixed bug in mobile phone where ZIP code was not being populated in the billing address.
*   Added option to set different billing and shipping zip codes in the checkout page.

#### Version 1.9.1 Released on Oct 01, 2021

*   Fixed Zip code not being recognized issue.
*   Added ability to specify fixed intervals for rentals.
*   Added ability to specify maximum number of days for rentals.

#### Version 1.9.0 Released on Sep 27, 2021

*   Changed the plugin logic to not use .htaccess any more.
*   Fixed zip code field not showing on checkout issue.

#### Version 1.8.10 Released on Sep 9, 2021

*   Added support for hour-based rush fees.

#### Version 1.8.9 Released on Aug 26, 2021

*   Fixed several stilistic issues on slide-in cart section, including buggy scrolling when > 5 products are on cart.

#### Version 1.8.8 Released on Aug 13, 2021

*   Added option to block placing orders on specific dates of week.
*   Added option to disable pick up option in checkout.
*   Added option to set an offset in days for placing orders.

#### Version 1.8.7 Released on Aug 5, 2021

*   Added sability to remove applied coupons on checkout page.

#### Version 1.8.6 Released on Jul 30, 2021

*   Added support for different delivery and pickup addresses.
*   Combined the date / time selection block of horizontal calendar and added confirmation button.
*   Added option to hide the damage waiver selection on checkout page.

#### Version 1.8.5 Released on Jul 26, 2021

*   Added support for deposit only or deposit - full interval payments.

#### Version 1.8.4 Released on Jul 14, 2021

*   Added Rentopian sync as a shortcode. Now you can insert the form anywhere on your pages by using the \[rentopian\_sync\_date\_form\] shortcode.

#### Version 1.8.3 Released on Jul 08, 2021

*   Added delivery tax syncing.
*   Added option to combine delivery and standard taxes.

#### Version 1.8.2 Released on Jul 01, 2021

*   Fixed new product attribute thumbnails not syncing issue.

#### Version 1.8.1 Released on Jun 25, 2021

*   Added option for end user to select a location if the company has more than one locations.

#### Version 1.8.0 Released on Jun 11, 2021

*   Changed the order notes added on checkout section to go into system as external note instead of internal.
*   Added support for delivery distance calculation.
*   Added option to detach a custom attribute from the product.

#### Version 1.7.8 Released on Jun 03, 2021

*   Fixed rush fee calculation bug.

#### Version 1.7.7 Released on Jun 01, 2021

*   Added support for Woocommerce Products Filter plugin.
*   Added support for Woocommerce Vaiation Swatches plugin.
*   Added support for Woocommerce Brands plugin.
*   Added discount before / after tax syncing.
*   Added product attributes syncing.

#### Version 1.6.0 Released on Jan 01, 2021

*   Added double shipping fees option.
*   Added option to hide damage waiver.
*   Bug fixes and layout / design improvements.

#### Version 1.5.5 Released on May 02, 2020

*   Added support for category description.
*   Optimized image synchronization.
*   Fixed a bug with deleted variants not syncing with the inventory.

#### Version 1.5.4 Released on April 22, 2020

*   Fixed a bug with tax and addons prices calculation.

#### Version 1.5.3 Released on April 15, 2020

*   Removed product url ID prefixes to improve SEO by not changing product url when resynced.

#### Version 1.5.2 Released on April 07, 2020

*   Fixed a bug with product prices calculation.

#### Version 1.5.1 Released on April 04, 2020

*   Fixed a bug with product variants synchronization.

#### Version 1.5.0 Released on March 27, 2020

*   Improved plugin's logic to fully support multi-location companies. Please refer to the documentation for more detials.
*   **\*Breaking changes Introduced\***  
    Please refer to upgrade guide.

#### Version 1.4.3 Released on March 02, 2020

*   Changed plugin updater to overwrite the existing version without adding generated postfix random string to the plugins name.

#### Version 1.4.2 Released on Feb 20, 2020

*   Added synchronization for delivery taxes and product dimensions.

#### Version 1.4.1 Released on Feb 4, 2020

*   Fixed a bug with main system synchronization.

#### Version 1.4 Released on Dec 9, 2019

*   Fixed the issue with plugin not being recognized correctly if folder name was anything other than "rentopian-sync".
*   Added option to disable using Rentopian shipping calculation in favor of third party plugins such as UPS Shipping.

#### Version 1.3 Released on Nov 7, 2019

*   Added tiers for rental duration calculations.

#### Version 1.2 Released on Oct 10, 2019

*   Added support for sets. In-set product add-ons are not supported yet.

#### Version 1.1 Released on June 10, 2019

*   Now the plugin supports add-on products logic. If a product has any attached add-ons, these will be added into cart with the product automatically.
*   Added auto-update functionality.
*   Improved styling
*   Fixed product variants import image attachments bug

#### Version 1.0

*   Initial Release