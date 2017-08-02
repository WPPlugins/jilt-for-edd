=== Jilt for Easy Digital Downloads ===
Contributors: jilt, skyverge, easydigitaldownloads
Tags: easy digital downloads, edd, abandoned carts, cart abandonment, lost revenue, save abandoned carts
Requires at least: 4.1
Tested up to: 4.7.5
Tested Easy Digital Downloads up to: 2.7.6
Stable Tag: 1.1.1
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Recover abandoned carts and lost revenue in your Easy Digital Downloads store by connecting it to Jilt, the abandoned cart recovery app.

== Description ==

The [Jilt abandoned cart recovery app](http://jilt.com/) helps your eCommerce store **recover lost revenue** due to cart abandonment. This plugin connects Jilt to [Easy Digital Downloads](https://easydigitaldownloads.com/), letting you track when carts are abandoned, then send recovery emails to encourage the customers who abandoned these carts to complete the purchase.

Jilt has already helped merchants recover **over $14,000,000** in lost revenue! You can set up as many campaigns and recovery emails as you'd like, and customize the text and design of every email sent.

= Track Abandoned Carts =

Jilt will track all abandoned carts in your EDD store, capturing email addresses for customers where possible. This lets you see how many customers enter your purchasing flow, but then leave without completing the order.

You can then send recovery emails to these customers to encourage them to complete their purchases, recovering revenue that would otherwise be lost to your store.

= Recover Lost Revenue =

Once Jilt tracks an abandoned cart, you can use your campaigns and recovery emails to save this lost revenue. A **campaign** is a collection of recovery emails that can be sent after the cart is abandoned. You can set up as many emails within a campaign as you'd like (e.g., send 3 recovery emails per abandoned cart).

You can also set up as many campaigns as you want &ndash; create a dedicated series of recovery emails for holidays, sales, or other company events.

= Built for Site Performance =

This plugin sends details on all abandoned carts to the Jilt app rather than storing them in your site's database. This ensures that you can track data over time and get valuable insights into cart abandonment and recovery, while your site **stays speedy** and doesn't get bogged down with tons of abandoned cart data.

Jilt for EDD is built and maintained jointly by [SkyVerge](http://skyverge.com/) and the [Easy Digital Downloads team](https://easydigitaldownloads.com/the-crew/). Jilt for EDD is great for merchants small and large alike, and is built to scale as large as your store can.

= More Details =

 - Visit [Jilt.com](http://jilt.com/) for more details on Jilt, the abandoned cart recovery app
 - See the [full knowledge base and documentation](http://help.jilt.com/collection/428-jilt-for-easy-digital-downloads) for questions and set up help.

== Installation ==

1. Install the plugin - you can do one of the following:

    - (Recommended) Search for "Jilt for EDD" under Plugins &gt; Add New
    - Upload the entire `jilt-for-edd` folder to the `/wp-content/plugins/` directory.
    - Upload the .zip file with the plugin under **Plugins &gt; Add New &gt; Upload**

3. Activate the plugin through the 'Plugins' menu in WordPress

4. Click the "Configure" plugin link or go to **Downloads &gt; Settings &gt; Extensions &gt; Jilt** to add your Jilt API keys, connecting your account to EDD.

5. Save your settings!

== Frequently Asked Questions ==

= Do I need anything else to use this plugin? =

Yes, a Jilt account (paid) is required to recover abandoned carts for your Easy Digital Downloads store. You can [learn more about Jilt here](http://jilt.com/). You can try Jilt for 14 days for free! Your trial will start as soon as you recover your first abandoned cart.

= When is a cart "abandoned"? =

A cart is considered abandoned if a customer has added items to the cart, but has not checked out or shown any cart activity for at least 15 minutes (i.e. adding more items). At this point, Jilt starts the timers for your recovery emails.

= Which customers will be emailed? =

Any logged in customer who abandons a cart will receive recovery emails from Jilt. Any guest customer who has entered a full, valid email address in the checkout process will also be sent recovery emails.

= Can I include unique discount codes in my recovery emails? =

Yes! Starting with version 1.1.0 Jilt can automatically create unique discount codes and include them in your recovery emails. This gives you a proven and powerful means of increasing conversion rates and recovering more orders!

== Screenshots ==

1. Get your Jilt Secret Token (click your email &gt; "Edit Account" in the Jilt app)
2. Enter the secret token in the plugin's settings
3. Set up your campaigns in the Jilt app to start recovering lost sales!

== Other Notes ==

**Translators:** the plugin text domain is: `jilt-for-edd`

== Changelog ==

= 2017-05-19 - version 1.1.1 =
 * Tweak - Preferred HTTP Request method transport can be specified in the edd_jilt_http_request_args filter
 * Fix - Bug with recreating sessions for certain logged in customers

= 2017-04-25 - version 1.1.0 =
 * Feature - Support for dynamic recovery discounts
 * Tweak - Remove Jilt Staging setting
 * Tweak - Additional logging level for easier troubleshooting
 * Tweak - x-jilt-shop-domain header is now included in all Jilt API requests
 * Tweak - Better handling of staging/dev migrations
 * Tweak - Removing the configured secret key or deactivating the plugin now signals the Jilt app to pause any active recovery campaigns

= 2016-12-01 - version 1.0.2 =
 * Fix - Fix an issue where the total discount amount sent to Jilt could be incorrect
 * Fix - Fix incorrect currency when an payment is placed via off-site payment gateways
 * Fix - Avoid errors when following a recovery link for a a previously deleted order
 * Tweak - Improve how API requests to Jilt are sent for improved stability and compatibility with different server environments

= 2016-11-25 - version 1.0.1 =
 * Fix - Fix issues with some payments placed via off-site payment gateways (like PayPal) incorrectly being marked as recoverable in Jilt
 * Tweak - Recovered payments placed via an offsite payment gateway (like PayPal) are more clearly linked to the original abandoned payment
 * Misc - Updated public JS API to support setting customer data prior to a visitor starting the checkout process

= 2016-11-09 - version 1.0.0 =
 * Initial release!
