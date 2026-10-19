<?php
/**
 * Plugin Name: MadeIT Rentals
 * Plugin URI: https://labs.made-it.pl/en/plugins/madeit-rentals-pro
 * Description: Night-based rentals and bookings for WooCommerce, with an availability calendar on the product page.
 * Version: 1.0.1
 * Requires at least: 6.6
 * Tested up to: 7.1
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * Author: MadeIT Labs
 * Author URI: https://labs.made-it.pl/en
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: madeit-rentals
 * Domain Path: /languages
 * WC requires at least: 9.0
 * WC tested up to: 10.0
 */

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Madeit\Rentals\Plugin;
use Madeit\Rentals\Repository;

defined('ABSPATH') || exit;

define('MADEIT_RENTALS_FILE', __FILE__);
define('MADEIT_RENTALS_VERSION', '1.0.1');

require_once __DIR__.'/src/Calculator.php';
require_once __DIR__.'/src/Settings.php';
require_once __DIR__.'/src/Repository.php';
require_once __DIR__.'/src/Admin.php';
require_once __DIR__.'/src/Plugin.php';

register_activation_hook(__FILE__, ['Madeit\\Rentals\\Repository', 'install']);

add_action('before_woocommerce_init', static function (): void {
    if (class_exists(FeaturesUtil::class)) {
        FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

add_action('plugins_loaded', static function (): void {
    if (! class_exists('WooCommerce')) {
        return;
    }

    (new Plugin(new Repository))->register();
    do_action('madeit_rentals_loaded');
});
