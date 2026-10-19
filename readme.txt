=== MadeIT Rentals ===
Contributors: wcolabs
Tags: woocommerce, rental, booking, reservation, calendar
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.0
Requires Plugins: woocommerce
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Night-based rentals and bookings for WooCommerce, with an availability calendar on the product page.

== Description ==

MadeIT Rentals turns a simple WooCommerce product into something customers can rent by the night: equipment, vehicles, holiday cottages, costumes. Customers pick the pick-up and return dates in a calendar that already hides the dates that are taken, see the price straight away and check out like any other order.

**Features**

* Any simple product can be a rental: tick "Rental" in the product data. The regular price is the price for one night.
* Calendar on the product page with unavailable dates greyed out, live availability and a price summary.
* Several units per product: the same product can be booked by more than one customer at once, up to the number of units you own.
* Minimum and maximum number of nights per product.
* A booking is created with the order, waits for payment, is confirmed when the order is paid and releases the units when the order is cancelled, fails or is refunded.
* Availability is checked when adding to the cart, in the cart and at payment, so two customers cannot take the same dates.
* Bookings screen with search, status filters and actions: confirm, mark as picked up, mark as returned, cancel.
* Works with the classic and block cart and checkout, and with High-Performance Order Storage.
* Accent colour setting for the calendar.
* Fully translatable.

**MadeIT Rentals Pro**

An optional add-on from MadeIT Labs (sold separately at [labs.made-it.pl/en](https://labs.made-it.pl/en/plugins/madeit-rentals-pro)) adds seasonal prices and long-stay discounts, refundable deposits and partial prepayment, buffer days between bookings, a minimum lead time and booking horizon, and editable customer-facing texts. The free plugin is complete on its own and does not contact any external service.

== Installation ==

1. Make sure WooCommerce is installed and active.
2. Upload the plugin to `/wp-content/plugins/` or install it from the Plugins screen, then activate it.
3. Edit a simple product, open the General tab of Product data and tick "Rental". Set the regular price to the price for one night and the number of units.
4. Open MadeIT Rentals → Settings to choose the calendar colour.

== Frequently Asked Questions ==

= How are nights counted? =

A booking occupies the nights from the pick-up day up to the day before the return. The return day stays free for the next customer.

= Can I rent several units of the same product? =

Yes. Set "Units available to rent" in the product. Customers can book as many units as are free for the chosen dates.

= Does it work with the WooCommerce Cart and Checkout blocks? =

Yes, availability is checked in the block cart and checkout as well.

= Is customer data sent to external services? =

No. The plugin does not make any external requests.

= What happens to the data if I delete the plugin? =

The bookings table is kept so you do not lose your booking history.

== Screenshots ==

1. Availability calendar and price summary on the product page.
2. Bookings list with statuses.
3. Booking details with actions.
4. Settings with live preview.

== Changelog ==

= 1.0.0 =
* First public release.

== Upgrade Notice ==

= 1.0.0 =
First public release.
