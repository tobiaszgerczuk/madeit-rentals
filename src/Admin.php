<?php

namespace Madeit\Rentals;

defined('ABSPATH') || exit;

final class Admin
{
    public const PAGE = 'madeit-rentals';

    public const SETTINGS_PAGE = 'madeit-rentals-settings';

    public const UPGRADE_URL = 'https://wco-labs.pl/en/plugins/madeit-rentals-pro';

    public function __construct(private Repository $repository) {}

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('admin_post_madeit_rentals_appearance', [$this, 'saveAppearance']);
        add_action('admin_post_madeit_rentals_booking', [$this, 'bookingAction']);
        add_filter('plugin_action_links_'.plugin_basename(MADEIT_RENTALS_FILE), [$this, 'actionLinks']);
    }

    public function menu(): void
    {
        add_menu_page(__('Madeit Rentals', 'madeit-rentals'), __('Madeit Rentals', 'madeit-rentals'), 'manage_woocommerce', self::PAGE, [$this, 'renderBookings'], 'dashicons-calendar-alt', 58);
        add_submenu_page(self::PAGE, __('Bookings', 'madeit-rentals'), __('Bookings', 'madeit-rentals'), 'manage_woocommerce', self::PAGE, [$this, 'renderBookings']);
        add_submenu_page(self::PAGE, __('Settings', 'madeit-rentals'), __('Settings', 'madeit-rentals'), 'manage_woocommerce', self::SETTINGS_PAGE, [$this, 'renderSettings']);
    }

    /**
     * @param  array<int, string>  $links
     * @return array<int, string>
     */
    public function actionLinks(array $links): array
    {
        array_unshift($links, '<a href="'.esc_url(admin_url('admin.php?page='.self::SETTINGS_PAGE)).'">'.esc_html__('Settings', 'madeit-rentals').'</a>');

        return $links;
    }

    public function assets(): void
    {
        // Only used to decide whether to load the stylesheet on this plugin's own screens.
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (strpos($page, 'madeit-rentals') !== 0) {
            return;
        }
        wp_enqueue_style('madeit-rentals-admin', plugins_url('assets/admin.css', MADEIT_RENTALS_FILE), [], MADEIT_RENTALS_VERSION);
        wp_enqueue_style('madeit-rentals-preview', plugins_url('assets/frontend.css', MADEIT_RENTALS_FILE), [], MADEIT_RENTALS_VERSION);
    }

    /**
     * @return array<string, string>
     */
    private function tabs(): array
    {
        $tabs = apply_filters('madeit_rentals_settings_tabs', [
            'products' => __('Products', 'madeit-rentals'),
            'appearance' => __('Appearance', 'madeit-rentals'),
        ]);

        return is_array($tabs) ? $tabs : [];
    }

    public function renderBookings(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to do this.', 'madeit-rentals'));
        }

        // Search and paging parameters only filter a read-only list.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';
        $page = max(1, absint($_GET['paged'] ?? 1));
        $bookingId = absint($_GET['booking'] ?? 0);
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        $perPage = 20;
        $result = $this->repository->search($search, $status, $page, $perPage);
        $pages = max(1, (int) ceil($result['total'] / $perPage));
        $summary = $this->repository->summary(Settings::today());
        ?>
        <div class="wrap madeit-admin">
            <div class="madeit-hero">
                <div>
                    <span class="madeit-eyebrow"><?php esc_html_e('MADEIT RENTALS / MANAGEMENT', 'madeit-rentals'); ?></span>
                    <h1><?php esc_html_e('Bookings', 'madeit-rentals'); ?></h1>
                    <p><?php esc_html_e('Pick-ups, returns and deposits in one place. A booking is created with the order and blocks units in the calendar.', 'madeit-rentals'); ?></p>
                </div>
                <a class="madeit-button madeit-button-light" href="<?php echo esc_url(admin_url('admin.php?page='.self::SETTINGS_PAGE)); ?>"><?php esc_html_e('Settings', 'madeit-rentals'); ?> <span aria-hidden="true">↗</span></a>
            </div>
            <?php $this->notice(); ?>
            <div class="madeit-stats">
                <div class="madeit-stat"><span><?php esc_html_e('Upcoming bookings', 'madeit-rentals'); ?></span><strong><?php echo esc_html((string) $summary['upcoming']); ?></strong></div>
                <div class="madeit-stat"><span><?php esc_html_e('Picked up (with customers)', 'madeit-rentals'); ?></span><strong><?php echo esc_html((string) $summary['out']); ?></strong></div>
                <div class="madeit-stat madeit-stat-note"><span><?php esc_html_e('Next pick-up', 'madeit-rentals'); ?></span><strong><?php echo esc_html($summary['next_start'] !== '' ? Settings::formatDate($summary['next_start']) : __('none', 'madeit-rentals')); ?></strong></div>
            </div>
            <?php
            if ($bookingId > 0) {
                $this->detail($bookingId);
            }
            ?>
            <section class="madeit-card">
                <div class="madeit-section-heading"><div>
                    <h2><?php esc_html_e('Booking list', 'madeit-rentals'); ?></h2>
                    <p><?php echo esc_html(sprintf(/* translators: %s: number of results */ __('Results: %s', 'madeit-rentals'), (string) $result['total'])); ?></p>
                </div></div>
                <form class="madeit-filters" method="get">
                    <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE); ?>">
                    <label><span><?php esc_html_e('Search by booking, order or product ID', 'madeit-rentals'); ?></span><input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('For example 54', 'madeit-rentals'); ?>"></label>
                    <label><span><?php esc_html_e('Status', 'madeit-rentals'); ?></span><select name="status">
                        <option value=""><?php esc_html_e('All', 'madeit-rentals'); ?></option>
                        <?php foreach (Repository::statuses() as $value => $label) { ?>
                            <option value="<?php echo esc_attr($value); ?>"<?php selected($status, $value); ?>><?php echo esc_html($label); ?></option>
                        <?php } ?>
                    </select></label>
                    <button class="madeit-button madeit-button-primary" type="submit"><?php esc_html_e('Show results', 'madeit-rentals'); ?></button>
                    <?php if ($search !== '' || $status !== '') { ?>
                        <a class="madeit-reset" href="<?php echo esc_url(admin_url('admin.php?page='.self::PAGE)); ?>"><?php esc_html_e('Clear', 'madeit-rentals'); ?></a>
                    <?php } ?>
                </form>
                <?php if (! $result['rows']) { ?>
                    <div class="madeit-empty"><span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span><h3><?php esc_html_e('No bookings', 'madeit-rentals'); ?></h3><p><?php esc_html_e('Change the filters or wait for the first order with a rental.', 'madeit-rentals'); ?></p></div>
                <?php } else { ?>
                    <div class="madeit-list">
                        <?php
                        foreach ($result['rows'] as $row) {
                            $this->row($row);
                        }
                        ?>
                    </div>
                <?php } ?>
                <div class="madeit-pagination">
                    <span><?php echo esc_html(sprintf(/* translators: 1: current page, 2: total pages */ __('Page %1$s of %2$s', 'madeit-rentals'), (string) min($page, $pages), (string) $pages)); ?></span>
                    <div>
                        <?php if ($page > 1) { ?>
                            <a class="madeit-button madeit-button-outline" href="<?php echo esc_url($this->listUrl($search, $status, $page - 1)); ?>">← <?php esc_html_e('Previous', 'madeit-rentals'); ?></a>
                        <?php } ?>
                        <?php if ($page < $pages) { ?>
                            <a class="madeit-button madeit-button-outline" href="<?php echo esc_url($this->listUrl($search, $status, $page + 1)); ?>"><?php esc_html_e('Next', 'madeit-rentals'); ?> →</a>
                        <?php } ?>
                    </div>
                </div>
            </section>
        </div>
        <?php
    }

    public function renderSettings(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to do this.', 'madeit-rentals'));
        }

        $tabs = $this->tabs();
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- the tab key only selects which screen to show.
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'products';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        $tab = isset($tabs[$tab]) ? $tab : 'products';
        $settings = Settings::settings();
        $products = $this->rentalProducts();
        $summary = $this->repository->summary(Settings::today());
        ?>
        <div class="wrap madeit-admin">
            <div class="madeit-hero">
                <div>
                    <span class="madeit-eyebrow"><?php esc_html_e('MADEIT RENTALS / CONFIGURATION', 'madeit-rentals'); ?></span>
                    <h1><?php esc_html_e('Rentals', 'madeit-rentals'); ?></h1>
                    <p><?php esc_html_e('Set up the rental products and the look of the calendar on the product page.', 'madeit-rentals'); ?></p>
                </div>
                <?php do_action('madeit_rentals_settings_hero'); ?>
            </div>
            <?php $this->notice(); ?>
            <div class="madeit-stats">
                <div class="madeit-stat"><span><?php esc_html_e('Rental products', 'madeit-rentals'); ?></span><strong><?php echo esc_html((string) count($products)); ?></strong></div>
                <div class="madeit-stat"><span><?php esc_html_e('Upcoming bookings', 'madeit-rentals'); ?></span><strong><?php echo esc_html((string) $summary['upcoming']); ?></strong></div>
                <div class="madeit-stat madeit-stat-note"><span><?php esc_html_e('Lead time / horizon', 'madeit-rentals'); ?></span><strong>
                    <?php
                    echo esc_html(sprintf(
                        /* translators: 1: lead time in days, 2: booking horizon */
                        __('%1$s days / %2$s', 'madeit-rentals'),
                        $settings['lead_days'],
                        $settings['horizon_days'] === '0' ? __('no limit', 'madeit-rentals') : sprintf(/* translators: %s: number of days */ __('%s days', 'madeit-rentals'), $settings['horizon_days'])
                    ));
                    ?>
                </strong></div>
            </div>
            <nav class="madeit-tabs" aria-label="<?php esc_attr_e('Rental settings', 'madeit-rentals'); ?>">
                <?php foreach ($tabs as $key => $label) { ?>
                    <a class="<?php echo esc_attr($tab === $key ? 'is-active' : ''); ?>" href="<?php echo esc_url(add_query_arg(['page' => self::SETTINGS_PAGE, 'tab' => $key], admin_url('admin.php'))); ?>"<?php echo $tab === $key ? ' aria-current="page"' : ''; ?>><?php echo esc_html($label); ?></a>
                <?php } ?>
            </nav>
            <?php
            if ($tab === 'products') {
                $this->productsTab($products);
            } elseif ($tab === 'appearance') {
                $this->appearanceTab($settings);
            } else {
                /**
                 * Renders the body of a settings tab added through `madeit_rentals_settings_tabs`.
                 *
                 * @param  string  $tab  Tab key.
                 */
                do_action('madeit_rentals_render_settings_tab', $tab);
            }
            ?>
        </div>
        <?php
    }

    /**
     * @return array<int, \WC_Product>
     */
    private function rentalProducts(): array
    {
        $products = wc_get_products(['limit' => -1, 'status' => ['publish', 'draft', 'private'], 'meta_key' => '_madeit_rental', 'meta_value' => 'yes']); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value

        return array_values(array_filter($products, static fn ($product): bool => $product instanceof \WC_Product));
    }

    /**
     * @param  array<int, \WC_Product>  $products
     */
    private function productsTab(array $products): void
    {
        ?>
        <section class="madeit-card madeit-settings-card">
            <div class="madeit-section-heading">
                <div>
                    <h2><?php esc_html_e('Rental products', 'madeit-rentals'); ?></h2>
                    <p><?php esc_html_e('Tick “Rental” in the product data, in the General section. The regular price is the price for one night. Units and night limits are set in the product.', 'madeit-rentals'); ?></p>
                </div>
                <a class="madeit-button madeit-button-outline" href="<?php echo esc_url(admin_url('edit.php?post_type=product')); ?>"><?php esc_html_e('Products', 'madeit-rentals'); ?> →</a>
            </div>
            <?php if (! $products) { ?>
                <div class="madeit-empty"><span class="dashicons dashicons-products" aria-hidden="true"></span><h3><?php esc_html_e('No rental products', 'madeit-rentals'); ?></h3><p><?php esc_html_e('Edit a simple product and tick “Rental”.', 'madeit-rentals'); ?></p></div>
            <?php } else { ?>
                <div class="madeit-list">
                    <?php foreach ($products as $product) { ?>
                        <?php $config = Settings::config($product); ?>
                        <article class="madeit-row">
                            <div class="madeit-row-identity"><strong><?php echo esc_html($product->get_name()); ?></strong><small><?php echo esc_html(sprintf(/* translators: 1: number of units, 2: minimum nights, for example "2 nights" */ __('%1$d units, from %2$s', 'madeit-rentals'), $config['units'], Settings::nightsText($config['min_nights']))); ?></small></div>
                            <div class="madeit-row-balance"><span class="madeit-cell-label"><?php esc_html_e('Price', 'madeit-rentals'); ?></span><strong>
                                <?php
                                if ($product->get_price() !== '') {
                                    echo wp_kses_post(sprintf(/* translators: %s: price */ __('%s / night', 'madeit-rentals'), wc_price((float) $product->get_price())));
                                } else {
                                    esc_html_e('no price', 'madeit-rentals');
                                }
                                ?>
                            </strong></div>
                            <div class="madeit-row-status"><span class="madeit-cell-label"><?php esc_html_e('Status', 'madeit-rentals'); ?></span><span class="madeit-badge madeit-badge-<?php echo esc_attr($product->get_status() === 'publish' ? 'success' : 'muted'); ?>"><?php echo esc_html($product->get_status() === 'publish' ? __('Published', 'madeit-rentals') : __('Draft', 'madeit-rentals')); ?></span></div>
                            <div class="madeit-row-date"><span class="madeit-cell-label"><?php esc_html_e('Maximum nights', 'madeit-rentals'); ?></span><strong><?php echo esc_html($config['max_nights'] > 0 ? (string) $config['max_nights'] : '—'); ?></strong></div>
                            <a class="madeit-button madeit-button-outline" href="<?php echo esc_url((string) get_edit_post_link($product->get_id(), 'raw')); ?>"><?php esc_html_e('Edit', 'madeit-rentals'); ?> →</a>
                        </article>
                    <?php } ?>
                </div>
            <?php } ?>
            <?php if (! defined('MADEIT_RENTALS_PRO_VERSION')) { ?>
                <p class="madeit-pro-note">
                    <?php
                    printf(
                        /* translators: %s: link to the Pro add-on page */
                        esc_html__('Need seasonal prices, deposits, buffers between bookings or booking windows? %s adds them.', 'madeit-rentals'),
                        '<a href="'.esc_url(self::UPGRADE_URL).'" target="_blank" rel="noopener">'.esc_html__('Madeit Rentals Pro', 'madeit-rentals').'</a>'
                    );
                    ?>
                </p>
            <?php } ?>
        </section>
        <?php
    }

    /**
     * @param  array<string, string>  $settings
     */
    private function appearanceTab(array $settings): void
    {
        ?>
        <section class="madeit-card madeit-settings-card">
            <div class="madeit-section-heading"><div><h2><?php esc_html_e('Calendar appearance', 'madeit-rentals'); ?></h2><p><?php esc_html_e('The accent colour of the calendar and the price summary on the product page.', 'madeit-rentals'); ?></p></div></div>
            <div class="madeit-settings-layout">
                <form class="madeit-settings-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="madeit_rentals_appearance">
                    <?php wp_nonce_field('madeit_rentals_appearance'); ?>
                    <label><span><?php esc_html_e('Accent colour', 'madeit-rentals'); ?></span><input type="color" name="accent" value="<?php echo esc_attr($settings['accent']); ?>"></label>
                    <label class="madeit-check"><input type="checkbox" name="show_summary" value="1"<?php checked($settings['show_summary'], 'yes'); ?>><span><?php esc_html_e('Show the price summary below the calendar', 'madeit-rentals'); ?></span></label>
                    <button class="madeit-button madeit-button-primary" type="submit"><?php esc_html_e('Save appearance', 'madeit-rentals'); ?></button>
                </form>
                <div class="madeit-settings-preview"><?php $this->preview($settings, Settings::content()); ?></div>
            </div>
        </section>
        <?php
    }

    /**
     * Static preview of the widget (colour and texts), without any script. Public so the add-on can reuse it.
     *
     * @param  array<string, string>  $settings
     * @param  array<string, string>  $content
     */
    public function preview(array $settings, array $content): void
    {
        $firstOfMonth = strtotime('first day of november 2026 12:00:00 UTC');
        $offset = (int) wp_date('N', $firstOfMonth, new \DateTimeZone('UTC')) - 1;
        ?>
        <span><?php esc_html_e('Product page preview', 'madeit-rentals'); ?></span>
        <div class="madeit-rn" style="--madeit-rn-accent:<?php echo esc_attr($settings['accent']); ?>">
            <h3 class="madeit-rn__title"><?php echo esc_html($content['widget_title']); ?></h3>
            <p class="madeit-rn__hint"><?php echo esc_html($content['hint_end']); ?></p>
            <div class="madeit-rn__calendar"><div class="madeit-rn__cal"><div class="madeit-rn__head"><strong><?php echo esc_html(wp_date('F Y', $firstOfMonth, new \DateTimeZone('UTC'))); ?></strong></div><div class="madeit-rn__grid">
                <?php
                for ($weekday = 0; $weekday < 7; $weekday++) {
                    echo '<span class="madeit-rn__dow">'.esc_html(wp_date('D', strtotime('2024-01-01 12:00:00 UTC') + $weekday * DAY_IN_SECONDS, new \DateTimeZone('UTC'))).'</span>';
                }
                for ($blank = 0; $blank < $offset; $blank++) {
                    echo '<span></span>';
                }
                for ($day = 1; $day <= 14; $day++) {
                    $class = 'madeit-rn__day';
                    if ($day === 5) {
                        $class .= ' is-start';
                    } elseif ($day === 8) {
                        $class .= ' is-end';
                    } elseif ($day > 5 && $day < 8) {
                        $class .= ' is-between';
                    } elseif ($day >= 10 && $day <= 11) {
                        $class .= ' is-disabled';
                    }
                    echo '<span class="'.esc_attr($class).'">'.esc_html((string) $day).'</span>';
                }
                ?>
            </div></div></div>
            <div class="madeit-rn__summary"><dl class="madeit-rn__rows">
                <div><dt><?php echo esc_html($content['nights_label']); ?></dt><dd>3</dd></div>
                <div><dt><?php echo esc_html($content['rental_label']); ?></dt><dd><?php echo wp_kses_post(wc_price(450)); ?></dd></div>
                <div class="is-total"><dt><?php echo esc_html($content['online_label']); ?></dt><dd><?php echo wp_kses_post(wc_price(450)); ?></dd></div>
            </dl></div>
            <button type="button" class="madeit-rn__preview-button" disabled><?php echo esc_html($content['button']); ?></button>
        </div>
        <?php
    }

    public function saveAppearance(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to do this.', 'madeit-rentals'));
        }
        check_admin_referer('madeit_rentals_appearance');
        $accent = isset($_POST['accent']) ? sanitize_hex_color(wp_unslash($_POST['accent'])) : null;
        $saved = get_option('madeit_rentals_settings', []);
        $saved = is_array($saved) ? $saved : [];
        $saved['accent'] = $accent ?: Settings::DEFAULT_ACCENT;
        $saved['show_summary'] = ! empty($_POST['show_summary']) ? 'yes' : 'no';
        update_option('madeit_rentals_settings', $saved, false);
        wp_safe_redirect(admin_url('admin.php?page='.self::SETTINGS_PAGE.'&tab=appearance&madeit_notice=settings'));
        exit;
    }

    public function bookingAction(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to do this.', 'madeit-rentals'));
        }
        check_admin_referer('madeit_rentals_booking');
        $id = isset($_POST['booking_id']) ? absint(wp_unslash($_POST['booking_id'])) : 0;
        $operation = isset($_POST['operation']) ? sanitize_key(wp_unslash($_POST['operation'])) : '';
        if ($this->repository->find($id)) {
            match ($operation) {
                'confirm' => $this->repository->setStatus($id, 'confirmed'),
                'pickup' => $this->repository->setStatus($id, 'picked_up'),
                'return' => $this->repository->setStatus($id, 'returned'),
                'cancel' => $this->repository->setStatus($id, 'cancelled'),
                /**
                 * Lets an add-on handle its own booking operations, for example returning a deposit.
                 *
                 * @param  string  $operation  Operation key posted by the button.
                 * @param  int  $id  Booking ID.
                 */
                default => do_action('madeit_rentals_booking_operation', $operation, $id),
            };
        }
        wp_safe_redirect(admin_url('admin.php?page='.self::PAGE.'&booking='.$id.'&madeit_notice=booking'));
        exit;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function row(array $row): void
    {
        [$label, $tone] = $this->status((string) $row['status']);
        $product = wc_get_product((int) $row['product_id']);
        $detailUrl = add_query_arg(['page' => self::PAGE, 'booking' => (int) $row['id']], admin_url('admin.php'));
        $nights = Calculator::nights((string) $row['start_date'], (string) $row['end_date']);
        ?>
        <article class="madeit-row">
            <div class="madeit-row-identity">
                <span class="madeit-row-number">#<?php echo esc_html((string) $row['id']); ?></span>
                <strong><?php echo esc_html($product ? $product->get_name() : sprintf(/* translators: %s: product ID */ __('Product #%s', 'madeit-rentals'), $row['product_id'])); ?></strong>
                <small><?php echo esc_html(sprintf(/* translators: 1: order number, 2: number of units */ __('Order #%1$s · %2$s units', 'madeit-rentals'), (string) $row['order_id'], (string) $row['quantity'])); ?></small>
            </div>
            <div class="madeit-row-balance"><span class="madeit-cell-label"><?php esc_html_e('Dates', 'madeit-rentals'); ?></span><strong><?php echo esc_html(Settings::formatDate((string) $row['start_date']).' → '.Settings::formatDate((string) $row['end_date'])); ?></strong><small><?php echo esc_html(Settings::nightsText($nights)); ?></small></div>
            <div class="madeit-row-status"><span class="madeit-cell-label"><?php esc_html_e('Status', 'madeit-rentals'); ?></span><span class="madeit-badge madeit-badge-<?php echo esc_attr($tone); ?>"><?php echo esc_html($label); ?></span></div>
            <div class="madeit-row-date"><span class="madeit-cell-label"><?php esc_html_e('Created', 'madeit-rentals'); ?></span><strong><?php echo esc_html(wp_date(get_option('date_format'), strtotime($row['created_at'].' UTC'), wp_timezone())); ?></strong></div>
            <a class="madeit-button madeit-button-outline" href="<?php echo esc_url($detailUrl); ?>"><?php esc_html_e('Details', 'madeit-rentals'); ?> →</a>
        </article>
        <?php
    }

    private function detail(int $bookingId): void
    {
        $booking = $this->repository->find($bookingId);
        if (! $booking) {
            ?>
            <div class="madeit-notice madeit-notice-error"><?php echo esc_html(sprintf(/* translators: %s: booking ID */ __('Booking #%s not found.', 'madeit-rentals'), (string) $bookingId)); ?></div>
            <?php
            return;
        }
        [$label, $tone] = $this->status((string) $booking['status']);
        $product = wc_get_product((int) $booking['product_id']);
        $order = wc_get_order((int) $booking['order_id']);
        $nights = Calculator::nights((string) $booking['start_date'], (string) $booking['end_date']);
        $current = (string) $booking['status'];

        $operations = [];
        if ($current === 'pending') {
            $operations['confirm'] = [__('Confirm manually', 'madeit-rentals'), 'primary'];
        }
        if (in_array($current, ['pending', 'confirmed'], true)) {
            $operations['pickup'] = [__('Mark as picked up', 'madeit-rentals'), 'primary'];
        }
        if ($current === 'picked_up') {
            $operations['return'] = [__('Mark as returned', 'madeit-rentals'), 'primary'];
        }
        if (in_array($current, ['pending', 'confirmed'], true)) {
            $operations['cancel'] = [__('Cancel booking', 'madeit-rentals'), 'outline'];
        }
        ?>
        <section class="madeit-card madeit-detail">
            <div class="madeit-section-heading">
                <div><span class="madeit-eyebrow"><?php esc_html_e('BOOKING DETAILS', 'madeit-rentals'); ?></span><h2><?php echo esc_html(sprintf(/* translators: %s: booking ID */ __('Booking #%s', 'madeit-rentals'), (string) $bookingId)); ?></h2></div>
                <a class="madeit-close" href="<?php echo esc_url(admin_url('admin.php?page='.self::PAGE)); ?>"><?php esc_html_e('Close', 'madeit-rentals'); ?> ×</a>
            </div>
            <div class="madeit-detail-grid">
                <div><span><?php esc_html_e('Product', 'madeit-rentals'); ?></span><strong><?php echo esc_html($product ? $product->get_name() : sprintf(/* translators: %s: product ID */ __('Product #%s', 'madeit-rentals'), $booking['product_id'])); ?></strong></div>
                <div><span><?php esc_html_e('Status', 'madeit-rentals'); ?></span><strong class="madeit-badge madeit-badge-<?php echo esc_attr($tone); ?>"><?php echo esc_html($label); ?></strong></div>
                <div><span><?php esc_html_e('Units', 'madeit-rentals'); ?></span><strong><?php echo esc_html((string) $booking['quantity']); ?></strong></div>
                <div><span><?php esc_html_e('Pick-up', 'madeit-rentals'); ?></span><strong><?php echo esc_html(Settings::formatDate((string) $booking['start_date'])); ?></strong></div>
                <div><span><?php esc_html_e('Return', 'madeit-rentals'); ?></span><strong><?php echo esc_html(Settings::formatDate((string) $booking['end_date']).' ('.Settings::nightsText($nights).')'); ?></strong></div>
                <div><span><?php esc_html_e('Order', 'madeit-rentals'); ?></span><strong>
                    <?php if ($order) { ?>
                        <a href="<?php echo esc_url($order->get_edit_order_url()); ?>">#<?php echo esc_html((string) $booking['order_id']); ?> ↗</a>
                    <?php } else { ?>
                        #<?php echo esc_html((string) $booking['order_id']); ?>
                    <?php } ?>
                </strong></div>
                <?php
                /**
                 * Lets an add-on print extra cells in the booking detail grid, for example the deposit.
                 *
                 * @param  array<string, mixed>  $booking  Booking row.
                 */
                do_action('madeit_rentals_booking_detail_fields', $booking);
                ?>
            </div>
            <?php
            /**
             * Filters the operation buttons of the booking detail. Each value is [label, 'primary'|'outline'].
             *
             * @param  array<string, array{0: string, 1: string}>  $operations
             * @param  array<string, mixed>  $booking
             */
            $operations = apply_filters('madeit_rentals_booking_operations', $operations, $booking);
            ?>
            <?php if ($operations) { ?>
                <div class="madeit-inline-form">
                    <?php foreach ($operations as $operation => [$text, $style]) { ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="madeit_rentals_booking">
                            <input type="hidden" name="booking_id" value="<?php echo esc_attr((string) $bookingId); ?>">
                            <input type="hidden" name="operation" value="<?php echo esc_attr($operation); ?>">
                            <?php wp_nonce_field('madeit_rentals_booking'); ?>
                            <button class="madeit-button madeit-button-<?php echo esc_attr($style); ?>" type="submit"><?php echo esc_html($text); ?></button>
                        </form>
                    <?php } ?>
                </div>
            <?php } ?>
        </section>
        <?php
    }

    private function listUrl(string $search, string $status, int $page): string
    {
        return add_query_arg(array_filter(['page' => self::PAGE, 's' => $search, 'status' => $status, 'paged' => $page], static fn ($value): bool => $value !== ''), admin_url('admin.php'));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function status(string $status): array
    {
        $tone = ['pending' => 'warning', 'confirmed' => 'success', 'picked_up' => 'success', 'returned' => 'muted', 'cancelled' => 'muted'][$status] ?? 'muted';

        return [Repository::statuses()[$status] ?? $status, $tone];
    }

    private function notice(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- the key only selects a fixed message.
        $notice = isset($_GET['madeit_notice']) ? sanitize_key(wp_unslash($_GET['madeit_notice'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        $messages = apply_filters('madeit_rentals_notice_messages', ['settings' => __('Settings saved.', 'madeit-rentals'), 'booking' => __('Booking updated.', 'madeit-rentals')]);
        if (is_array($messages) && isset($messages[$notice])) {
            ?>
            <div class="madeit-notice" role="status"><?php echo esc_html($messages[$notice]); ?></div>
            <?php
        }
    }
}
