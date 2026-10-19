<?php

namespace Madeit\Rentals;

defined('ABSPATH') || exit;

final class Plugin
{
    public function __construct(private Repository $repository) {}

    public function register(): void
    {
        add_action('admin_init', [Repository::class, 'upgradeIfNeeded']);
        add_action('rest_api_init', [$this, 'registerRoutes']);

        add_action('woocommerce_product_options_pricing', [$this, 'productFields']);
        add_action('woocommerce_process_product_meta', [$this, 'saveProductFields']);

        add_action('woocommerce_before_add_to_cart_button', [$this, 'renderWidget']);
        add_filter('woocommerce_get_price_html', [$this, 'priceHtml'], 10, 2);
        add_filter('woocommerce_product_single_add_to_cart_text', [$this, 'buttonText'], 10, 2);
        add_filter('woocommerce_product_add_to_cart_text', [$this, 'loopButtonText'], 10, 2);
        add_filter('woocommerce_product_add_to_cart_url', [$this, 'loopButtonUrl'], 10, 2);
        add_filter('woocommerce_product_supports', [$this, 'disableAjaxAdd'], 10, 3);
        add_filter('woocommerce_quantity_input_args', [$this, 'quantityArgs'], 10, 2);
        add_filter('woocommerce_is_sold_individually', [$this, 'neverSoldIndividually'], 10, 2);

        add_filter('woocommerce_add_to_cart_validation', [$this, 'validateAddToCart'], 10, 3);
        add_filter('woocommerce_add_cart_item_data', [$this, 'addCartItemData'], 10, 2);
        add_filter('woocommerce_get_cart_item_from_session', [$this, 'restoreCartItem'], 10, 2);
        add_action('woocommerce_before_calculate_totals', [$this, 'applyStayPrice'], 20);
        add_action('woocommerce_cart_calculate_fees', [$this, 'addFees']);
        add_filter('woocommerce_get_item_data', [$this, 'cartItemData'], 10, 2);
        add_action('woocommerce_check_cart_items', [$this, 'checkCart']);
        add_action('woocommerce_after_checkout_validation', [$this, 'checkoutValidation'], 10, 2);
        add_action('woocommerce_store_api_cart_errors', [$this, 'storeApiErrors'], 10, 2);

        add_action('woocommerce_checkout_create_order_line_item', [$this, 'saveOrderItem'], 10, 3);
        add_action('woocommerce_checkout_order_processed', [$this, 'createBookings']);
        add_action('woocommerce_store_api_checkout_order_processed', [$this, 'createBookingsFromOrder']);
        add_action('woocommerce_order_status_changed', [$this, 'syncStatus'], 10, 3);

        add_action('wp_enqueue_scripts', [$this, 'frontendAssets']);

        (new Admin($this->repository))->register();
    }

    /* ---------- Product fields ---------- */

    public function productFields(): void
    {
        $productId = get_the_ID();
        echo '<div class="options_group show_if_simple madeit-rentals-fields">';
        woocommerce_wp_checkbox(['id' => '_madeit_rental', 'label' => __('Rental', 'madeit-rentals'), 'description' => __('The product is rented by the night. The regular price is the price for one night for one unit.', 'madeit-rentals')]);
        woocommerce_wp_text_input(['id' => '_madeit_rental_units', 'label' => __('Units available to rent', 'madeit-rentals'), 'type' => 'number', 'custom_attributes' => ['min' => '1', 'step' => '1'], 'value' => (string) max(1, (int) get_post_meta($productId, '_madeit_rental_units', true)), 'desc_tip' => true, 'description' => __('How many units can be rented at the same time.', 'madeit-rentals')]);
        woocommerce_wp_text_input(['id' => '_madeit_rental_min_nights', 'label' => __('Minimum nights', 'madeit-rentals'), 'type' => 'number', 'custom_attributes' => ['min' => '1', 'step' => '1'], 'value' => (string) max(1, (int) get_post_meta($productId, '_madeit_rental_min_nights', true))]);
        woocommerce_wp_text_input(['id' => '_madeit_rental_max_nights', 'label' => __('Maximum nights', 'madeit-rentals'), 'type' => 'number', 'custom_attributes' => ['min' => '0', 'step' => '1'], 'desc_tip' => true, 'description' => __('0 means no limit.', 'madeit-rentals')]);
        /**
         * Lets an add-on print extra rental fields in the product data panel.
         *
         * @param  int  $productId  Product being edited.
         */
        do_action('madeit_rentals_product_fields', $productId);
        echo '</div>';
    }

    public function saveProductFields(int $productId): void
    {
        $product = wc_get_product($productId);
        if (! $product) {
            return;
        }
        // WooCommerce verifies the product edit nonce before this hook runs.
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $product->update_meta_data('_madeit_rental', ! empty($_POST['_madeit_rental']) ? 'yes' : 'no');
        $product->update_meta_data('_madeit_rental_units', (string) max(1, isset($_POST['_madeit_rental_units']) ? absint(wp_unslash($_POST['_madeit_rental_units'])) : 1));
        $product->update_meta_data('_madeit_rental_min_nights', (string) max(1, isset($_POST['_madeit_rental_min_nights']) ? absint(wp_unslash($_POST['_madeit_rental_min_nights'])) : 1));
        $product->update_meta_data('_madeit_rental_max_nights', (string) (isset($_POST['_madeit_rental_max_nights']) ? absint(wp_unslash($_POST['_madeit_rental_max_nights'])) : 0));
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        /**
         * Lets an add-on save its own rental fields. The product is saved afterwards.
         *
         * @param  \WC_Product  $product
         */
        do_action('madeit_rentals_save_product_fields', $product);
        $product->save_meta_data();
    }

    /* ---------- Pricing and availability ---------- */

    /**
     * Price of a stay without checking availability.
     *
     * @return array{nights: int, subtotal: int, discount_percent: int, discount: int, total: int, average: int, now: int, later: int, deposit: int, online: int}
     */
    public function stay(\WC_Product $product, string $start, string $end): array
    {
        $nightly = (int) round(max(0.0, (float) $product->get_price()) * 100);
        $stay = Calculator::stay($nightly, $start, $end);
        /**
         * Lets an add-on price the stay: seasons, long-stay discounts, prepayment and deposit.
         *
         * @param  array<string, int>  $stay  Flat price with all amounts in cents, for one unit.
         * @param  \WC_Product  $product
         * @param  string  $start  Pick-up date, Y-m-d.
         * @param  string  $end  Return date, Y-m-d.
         * @param  array<string, int>  $nightlyAndConfig  Nightly price in cents under `nightly` plus the product config.
         */
        $filtered = apply_filters('madeit_rentals_stay', $stay, $product, $start, $end, ['nightly' => $nightly] + Settings::config($product));

        return is_array($filtered) ? array_merge($stay, array_intersect_key($filtered, $stay)) : $stay;
    }

    /**
     * Full quote: date and availability errors plus the price.
     *
     * @return array{errors: array<int, string>, stay: array<string, int>, config: array<string, int>}
     */
    public function quote(\WC_Product $product, string $start, string $end, int $quantity, int $ignoreOrderId = 0): array
    {
        $config = Settings::config($product);
        $settings = Settings::settings();
        $errors = [];
        $codes = Calculator::dateErrors($start, $end, [
            'min_nights' => $config['min_nights'],
            'max_nights' => $config['max_nights'],
            'lead_days' => (int) $settings['lead_days'],
            'horizon_days' => (int) $settings['horizon_days'],
        ], Settings::today());
        foreach ($codes as $code) {
            $errors[] = $this->errorMessage($code, $config, $settings);
        }
        if ($quantity < 1 || $quantity > $config['units']) {
            /* translators: %d: number of units */
            $errors[] = sprintf(_n('At most %d unit of this product is available.', 'At most %d units of this product are available.', $config['units'], 'madeit-rentals'), $config['units']);
        }
        if (! $errors) {
            $bookings = $this->repository->activeFor($product->get_id(), $start, $end, $config['buffer'], $ignoreOrderId);
            if (! Calculator::isAvailable($config['units'], $bookings, $config['buffer'], $start, $end, $quantity)) {
                $errors[] = Settings::content()['unavailable'];
            }
        }

        return ['errors' => $errors, 'stay' => $this->stay($product, $start, $end), 'config' => $config];
    }

    /**
     * @param  array<string, int>  $config
     * @param  array<string, string>  $settings
     */
    private function errorMessage(string $code, array $config, array $settings): string
    {
        return match ($code) {
            'order' => __('The return date must be later than the pick-up date.', 'madeit-rentals'),
            /* translators: %s: number of nights, for example "2 nights" */
            'min' => sprintf(__('The minimum rental period is %s.', 'madeit-rentals'), Settings::nightsText($config['min_nights'])),
            /* translators: %s: number of nights, for example "7 nights" */
            'max' => sprintf(__('The maximum rental period is %s.', 'madeit-rentals'), Settings::nightsText($config['max_nights'])),
            /* translators: %s: date */
            'lead' => sprintf(__('The earliest possible pick-up is %s.', 'madeit-rentals'), Settings::formatDate(Calculator::addDays(Settings::today(), (int) $settings['lead_days']))),
            /* translators: %s: number of nights, for example "365 nights" */
            'horizon' => sprintf(__('Bookings are accepted at most %s ahead.', 'madeit-rentals'), Settings::nightsText((int) $settings['horizon_days'])),
            default => __('Choose valid pick-up and return dates.', 'madeit-rentals'),
        };
    }

    /* ---------- REST ---------- */

    public function registerRoutes(): void
    {
        register_rest_route('madeit-rentals/v1', '/availability', [
            'methods' => 'GET',
            'callback' => [$this, 'restAvailability'],
            'permission_callback' => '__return_true',
            'args' => ['product' => ['type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint']],
        ]);
        register_rest_route('madeit-rentals/v1', '/quote', [
            'methods' => 'GET',
            'callback' => [$this, 'restQuote'],
            'permission_callback' => '__return_true',
            'args' => [
                'product' => ['type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint'],
                'start' => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'end' => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'qty' => ['type' => 'integer', 'default' => 1, 'sanitize_callback' => 'absint'],
            ],
        ]);
    }

    public function restAvailability(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $product = wc_get_product((int) $request['product']);
        if (! Settings::isRental($product) || $product->get_status() !== 'publish') {
            return new \WP_Error('madeit_rentals_product', __('This is not a rental product.', 'madeit-rentals'), ['status' => 404]);
        }
        $config = Settings::config($product);
        $settings = Settings::settings();
        $today = Settings::today();
        $horizon = (int) $settings['horizon_days'] ?: 365;
        $last = Calculator::addDays($today, $horizon + 1);
        $bookings = $this->repository->activeFor($product->get_id(), $today, $last, $config['buffer']);

        $response = new \WP_REST_Response([
            'units' => $config['units'],
            'today' => $today,
            'first' => Calculator::addDays($today, (int) $settings['lead_days']),
            'last' => Calculator::addDays($today, $horizon),
            'remaining' => (object) Calculator::remainingByDate($config['units'], $bookings, $config['buffer'], $today, $last),
        ]);
        $response->header('Cache-Control', 'no-store');

        return $response;
    }

    public function restQuote(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $product = wc_get_product((int) $request['product']);
        if (! Settings::isRental($product) || $product->get_status() !== 'publish') {
            return new \WP_Error('madeit_rentals_product', __('This is not a rental product.', 'madeit-rentals'), ['status' => 404]);
        }
        $quantity = max(1, (int) $request['qty']);
        $quote = $this->quote($product, (string) $request['start'], (string) $request['end'], $quantity);
        $response = new \WP_REST_Response([
            'valid' => ! $quote['errors'],
            'errors' => $quote['errors'],
            'summary' => $quote['errors'] ? '' : $this->summaryHtml($product, $quote['stay'], $quantity),
        ]);
        $response->header('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * @param  array<string, int>  $stay
     */
    private function summaryHtml(\WC_Product $product, array $stay, int $quantity): string
    {
        $content = Settings::content();
        $money = static fn (int $cents): string => wc_price(wc_get_price_to_display($product, ['price' => $cents / 100, 'qty' => 1]));
        $rows = [
            [$content['nights_label'], esc_html((string) $stay['nights'])],
            [$content['rental_label'], $money($stay['subtotal'] * $quantity)],
            [$content['online_label'], $money($stay['total'] * $quantity)],
        ];
        /**
         * Lets an add-on change the rows of the price summary. The last row is shown as the total.
         *
         * @param  array<int, array{0: string, 1: string}>  $rows  Label and HTML value.
         * @param  array<string, int>  $stay  Price of the stay for one unit, in cents.
         * @param  int  $quantity
         * @param  \WC_Product  $product
         */
        $rows = apply_filters('madeit_rentals_summary_rows', $rows, $stay, $quantity, $product);
        $html = '<dl class="madeit-rn__rows">';
        foreach (array_values($rows) as $index => [$label, $value]) {
            $html .= '<div'.($index === count($rows) - 1 ? ' class="is-total"' : '').'><dt>'.esc_html($label).'</dt><dd>'.wp_kses_post($value).'</dd></div>';
        }

        return $html.'</dl>';
    }

    /* ---------- Product page ---------- */

    public function renderWidget(): void
    {
        global $product;
        if (! $product instanceof \WC_Product || ! Settings::isRental($product)) {
            return;
        }
        $config = Settings::config($product);
        $settings = Settings::settings();
        $content = Settings::content();
        $data = [
            'product' => $product->get_id(),
            'units' => $config['units'],
            'min' => $config['min_nights'],
            'max' => $config['max_nights'],
            'availability' => rest_url('madeit-rentals/v1/availability'),
            'quote' => rest_url('madeit-rentals/v1/quote'),
            'hintStart' => $content['hint_start'],
            'hintEnd' => $content['hint_end'],
            'unavailable' => $content['unavailable'],
            'i18n' => [
                'prevMonth' => __('Previous month', 'madeit-rentals'),
                'nextMonth' => __('Next month', 'madeit-rentals'),
                'quoteFailed' => __('Could not check the dates. Please try again.', 'madeit-rentals'),
                'pickDates' => __('Choose the pick-up and return dates.', 'madeit-rentals'),
                'loadFailed' => __('Could not load the calendar. Refresh the page.', 'madeit-rentals'),
            ],
        ];
        ?>
        <div class="madeit-rn" data-madeit-rn="<?php echo esc_attr((string) wp_json_encode($data)); ?>" style="--madeit-rn-accent:<?php echo esc_attr($settings['accent']); ?>">
            <h3 class="madeit-rn__title"><?php echo esc_html($content['widget_title']); ?></h3>
            <p class="madeit-rn__hint" data-rn-hint role="status" aria-live="polite"><?php echo esc_html($content['hint_start']); ?></p>
            <div class="madeit-rn__calendar" data-rn-calendar></div>
            <?php if ($config['units'] > 1) { ?>
                <p class="madeit-rn__units"><?php echo esc_html($content['quantity_label']); ?>: <span><?php echo esc_html(sprintf(/* translators: %d: maximum number of units */ __('max. %d', 'madeit-rentals'), $config['units'])); ?></span></p>
            <?php } ?>
            <div class="madeit-rn__summary" data-rn-summary aria-live="polite"<?php echo $settings['show_summary'] === 'yes' ? '' : ' hidden'; ?>></div>
            <p class="madeit-rn__error" data-rn-error role="alert" hidden></p>
            <input type="hidden" name="madeit_rental_start" data-rn-start><input type="hidden" name="madeit_rental_end" data-rn-end>
        </div>
        <?php
    }

    public function priceHtml(string $html, \WC_Product $product): string
    {
        if (! Settings::isRental($product) || $html === '') {
            return $html;
        }

        return $html.' <span class="madeit-rn__suffix">'.esc_html(Settings::content()['price_suffix']).'</span>';
    }

    public function buttonText(string $text, \WC_Product $product): string
    {
        return Settings::isRental($product) ? Settings::content()['button'] : $text;
    }

    public function loopButtonText(string $text, \WC_Product $product): string
    {
        return Settings::isRental($product) ? Settings::content()['loop_button'] : $text;
    }

    public function loopButtonUrl(string $url, \WC_Product $product): string
    {
        return Settings::isRental($product) ? $product->get_permalink() : $url;
    }

    public function disableAjaxAdd(bool $supports, string $feature, \WC_Product $product): bool
    {
        return $feature === 'ajax_add_to_cart' && Settings::isRental($product) ? false : $supports;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function quantityArgs(array $args, \WC_Product $product): array
    {
        if (Settings::isRental($product)) {
            $args['max_value'] = Settings::config($product)['units'];
            $args['min_value'] = 1;
        }

        return $args;
    }

    public function neverSoldIndividually(bool $individually, \WC_Product $product): bool
    {
        return Settings::isRental($product) ? false : $individually;
    }

    /* ---------- Cart ---------- */

    /**
     * Dates posted with the add-to-cart form. WooCommerce handles that request itself and offers no nonce for it;
     * the values are only sanitized here and validated before anything is stored.
     *
     * @return array{start: string, end: string}
     */
    private function requestedDates(): array
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
        return [
            'start' => isset($_REQUEST['madeit_rental_start']) ? sanitize_text_field(wp_unslash($_REQUEST['madeit_rental_start'])) : '',
            'end' => isset($_REQUEST['madeit_rental_end']) ? sanitize_text_field(wp_unslash($_REQUEST['madeit_rental_end'])) : '',
        ];
        // phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
    }

    public function validateAddToCart(bool $passed, int $productId, $quantity): bool
    {
        $product = wc_get_product($productId);
        if (! Settings::isRental($product)) {
            return $passed;
        }
        ['start' => $start, 'end' => $end] = $this->requestedDates();
        if ($start === '' || $end === '') {
            wc_add_notice(__('Choose the pick-up and return dates.', 'madeit-rentals'), 'error');

            return false;
        }
        $quote = $this->quote($product, $start, $end, max(1, (int) $quantity));
        foreach ($quote['errors'] as $error) {
            wc_add_notice($error, 'error');
        }

        return $passed && ! $quote['errors'];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function addCartItemData(array $data, int $productId): array
    {
        if (Settings::isRental(wc_get_product($productId))) {
            ['start' => $start, 'end' => $end] = $this->requestedDates();
            if (Calculator::isDate($start) && Calculator::isDate($end)) {
                $data['madeit_rental'] = ['start' => $start, 'end' => $end];
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function restoreCartItem(array $item, array $values): array
    {
        if (isset($values['madeit_rental']) && is_array($values['madeit_rental'])) {
            $item['madeit_rental'] = $values['madeit_rental'];
        }

        return $item;
    }

    public function applyStayPrice(\WC_Cart $cart): void
    {
        if (is_admin() && ! defined('DOING_AJAX')) {
            return;
        }
        foreach ($cart->get_cart() as $item) {
            if (empty($item['madeit_rental'])) {
                continue;
            }
            // A fresh product copy, so repeated recalculations do not stack prices on the stay price.
            $fresh = wc_get_product($item['product_id']);
            if (! Settings::isRental($fresh)) {
                continue;
            }
            $stay = $this->stay($fresh, $item['madeit_rental']['start'], $item['madeit_rental']['end']);
            $item['data']->set_price((string) ($stay['total'] / 100));
        }
    }

    public function addFees(\WC_Cart $cart): void
    {
        if (is_admin() && ! defined('DOING_AJAX')) {
            return;
        }
        foreach ($cart->get_cart() as $item) {
            if (empty($item['madeit_rental'])) {
                continue;
            }
            $product = wc_get_product($item['product_id']);
            if (! Settings::isRental($product)) {
                continue;
            }
            $stay = $this->stay($product, $item['madeit_rental']['start'], $item['madeit_rental']['end']);
            $name = $product->get_name().', '.Settings::formatDate($item['madeit_rental']['start']).' – '.Settings::formatDate($item['madeit_rental']['end']);
            /**
             * Lets an add-on add cart fees for a rental line, for example the deposit or the amount due at pick-up.
             *
             * @param  \WC_Cart  $cart
             * @param  \WC_Product  $product
             * @param  array<string, int>  $stay  Price of the stay for one unit, in cents.
             * @param  int  $quantity
             * @param  string  $name  Product name with the dates.
             */
            do_action('madeit_rentals_cart_fees', $cart, $product, $stay, (int) $item['quantity'], $name);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $itemData
     * @param  array<string, mixed>  $cartItem
     * @return array<int, array<string, mixed>>
     */
    public function cartItemData(array $itemData, array $cartItem): array
    {
        if (empty($cartItem['madeit_rental'])) {
            return $itemData;
        }
        $rental = $cartItem['madeit_rental'];
        $itemData[] = ['key' => __('Pick-up', 'madeit-rentals'), 'value' => Settings::formatDate($rental['start'])];
        $itemData[] = ['key' => __('Return', 'madeit-rentals'), 'value' => Settings::formatDate($rental['end'])];
        $itemData[] = ['key' => Settings::content()['nights_label'], 'value' => (string) Calculator::nights($rental['start'], $rental['end'])];

        return $itemData;
    }

    /**
     * @return array<int, string>
     */
    private function cartErrors(\WC_Cart $cart): array
    {
        $errors = [];
        foreach ($cart->get_cart() as $item) {
            $product = wc_get_product($item['product_id']);
            if (! Settings::isRental($product)) {
                continue;
            }
            if (empty($item['madeit_rental'])) {
                /* translators: %s: product name */
                $errors[] = sprintf(__('%s: choose the pick-up and return dates on the product page.', 'madeit-rentals'), $product->get_name());

                continue;
            }
            foreach ($this->quote($product, $item['madeit_rental']['start'], $item['madeit_rental']['end'], (int) $item['quantity'])['errors'] as $error) {
                $errors[] = $product->get_name().': '.$error;
            }
        }

        return array_values(array_unique($errors));
    }

    public function checkCart(): void
    {
        if (! WC()->cart) {
            return;
        }
        foreach ($this->cartErrors(WC()->cart) as $error) {
            wc_add_notice($error, 'error');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function checkoutValidation(array $data, \WP_Error $errors): void
    {
        if (! WC()->cart) {
            return;
        }
        foreach ($this->cartErrors(WC()->cart) as $error) {
            $errors->add('madeit_rentals_unavailable', $error);
        }
    }

    public function storeApiErrors(\WP_Error $errors, \WC_Cart $cart): void
    {
        foreach ($this->cartErrors($cart) as $error) {
            $errors->add('madeit_rentals_unavailable', $error);
        }
    }

    /* ---------- Orders and bookings ---------- */

    /**
     * @param  array<string, mixed>  $values
     */
    public function saveOrderItem(\WC_Order_Item_Product $item, string $cartItemKey, array $values): void
    {
        if (empty($values['madeit_rental'])) {
            return;
        }
        $product = wc_get_product($values['product_id']);
        if (! Settings::isRental($product)) {
            return;
        }
        $rental = $values['madeit_rental'];
        $stay = $this->stay($product, $rental['start'], $rental['end']);
        $quantity = (int) $values['quantity'];
        $item->add_meta_data('_madeit_rental_start', $rental['start'], true);
        $item->add_meta_data('_madeit_rental_end', $rental['end'], true);
        $item->add_meta_data('_madeit_rental_deposit_cents', $stay['deposit'] * $quantity, true);
        $item->add_meta_data('_madeit_rental_later_cents', $stay['later'] * $quantity, true);
        $item->add_meta_data(__('Pick-up', 'madeit-rentals'), Settings::formatDate($rental['start']), true);
        $item->add_meta_data(__('Return', 'madeit-rentals'), Settings::formatDate($rental['end']), true);
        $item->add_meta_data(Settings::content()['nights_label'], (string) $stay['nights'], true);
    }

    public function createBookings(int $orderId): void
    {
        $order = wc_get_order($orderId);
        if ($order) {
            $this->createBookingsFromOrder($order);
        }
    }

    public function createBookingsFromOrder(\WC_Order $order): void
    {
        $existing = array_map(static fn (array $row): int => (int) $row['order_item_id'], $this->repository->forOrder($order->get_id()));
        $paid = $order->has_status(['processing', 'completed']);
        foreach ($order->get_items() as $item) {
            $start = (string) $item->get_meta('_madeit_rental_start');
            $end = (string) $item->get_meta('_madeit_rental_end');
            if (! Calculator::isDate($start) || ! Calculator::isDate($end) || in_array($item->get_id(), $existing, true)) {
                continue;
            }
            $this->repository->create([
                'order_id' => $order->get_id(),
                'order_item_id' => $item->get_id(),
                'product_id' => $item->get_product_id(),
                'quantity' => (int) $item->get_quantity(),
                'start' => $start,
                'end' => $end,
                'deposit_cents' => (int) $item->get_meta('_madeit_rental_deposit_cents'),
                'later_cents' => (int) $item->get_meta('_madeit_rental_later_cents'),
                'status' => $paid ? 'confirmed' : 'pending',
            ]);
        }
    }

    public function syncStatus(int $orderId, string $from, string $to): void
    {
        if (in_array($to, ['processing', 'completed'], true)) {
            $order = wc_get_order($orderId);
            if ($order) {
                $this->createBookingsFromOrder($order);
            }
            $this->repository->setStatusForOrder($orderId, 'confirmed', ['pending']);
        } elseif (in_array($to, ['cancelled', 'failed', 'refunded'], true)) {
            $this->repository->setStatusForOrder($orderId, 'cancelled', ['pending', 'confirmed']);
        }
    }

    public function frontendAssets(): void
    {
        wp_enqueue_style('madeit-rentals-frontend', plugins_url('assets/frontend.css', MADEIT_RENTALS_FILE), [], MADEIT_RENTALS_VERSION);
        if (function_exists('is_product') && is_product()) {
            wp_enqueue_script('madeit-rentals-frontend', plugins_url('assets/frontend.js', MADEIT_RENTALS_FILE), [], MADEIT_RENTALS_VERSION, true);
        }
    }
}
