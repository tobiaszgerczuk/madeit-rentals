<?php

namespace Madeit\Rentals;

defined('ABSPATH') || exit;

/**
 * Reads options and product configuration. Add-ons extend them through filters.
 */
final class Settings
{
    public const DEFAULT_ACCENT = '#294a74';

    /**
     * @return array<string, string>
     */
    public static function contentDefaults(): array
    {
        return [
            'widget_title' => __('Choose your rental dates', 'madeit-rentals'),
            'hint_start' => __('Choose the pick-up date.', 'madeit-rentals'),
            'hint_end' => __('Now choose the return date.', 'madeit-rentals'),
            'quantity_label' => __('Quantity', 'madeit-rentals'),
            'price_suffix' => __('/ night', 'madeit-rentals'),
            'nights_label' => __('Nights', 'madeit-rentals'),
            'rental_label' => __('Rental', 'madeit-rentals'),
            'discount_label' => __('Long stay discount', 'madeit-rentals'),
            'prepay_label' => __('Prepayment due now', 'madeit-rentals'),
            'later_label' => __('Due at pick-up', 'madeit-rentals'),
            'deposit_label' => __('Refundable deposit', 'madeit-rentals'),
            'online_label' => __('Due now', 'madeit-rentals'),
            'button' => __('Book these dates', 'madeit-rentals'),
            'loop_button' => __('Choose dates', 'madeit-rentals'),
            'unavailable' => __('These dates are not available. Choose other dates.', 'madeit-rentals'),
        ];
    }

    /**
     * Customer-facing texts. The add-on can replace them through the `madeit_rentals_content` filter.
     *
     * @return array<string, string>
     */
    public static function content(): array
    {
        $defaults = self::contentDefaults();
        $content = apply_filters('madeit_rentals_content', $defaults);

        return array_map('strval', array_merge($defaults, array_intersect_key(is_array($content) ? $content : [], $defaults)));
    }

    /**
     * @return array{lead_days: string, horizon_days: string, show_summary: string, accent: string}
     */
    public static function settings(): array
    {
        $saved = get_option('madeit_rentals_settings', []);
        $saved = is_array($saved) ? $saved : [];
        $settings = [
            'lead_days' => '1',
            'horizon_days' => '365',
            'show_summary' => ($saved['show_summary'] ?? 'yes') === 'no' ? 'no' : 'yes',
            'accent' => sanitize_hex_color((string) ($saved['accent'] ?? '')) ?: self::DEFAULT_ACCENT,
        ];
        /**
         * Lets an add-on change the booking window: minimum lead time and booking horizon, both in days.
         *
         * @param  array<string, string>  $settings
         */
        $filtered = apply_filters('madeit_rentals_settings', $settings);
        if (is_array($filtered)) {
            $settings['lead_days'] = (string) max(0, min(365, (int) ($filtered['lead_days'] ?? $settings['lead_days'])));
            $settings['horizon_days'] = (string) max(0, min(1095, (int) ($filtered['horizon_days'] ?? $settings['horizon_days'])));
        }

        return $settings;
    }

    public static function isRental(?\WC_Product $product): bool
    {
        return $product !== null && $product->is_type('simple') && $product->get_meta('_madeit_rental') === 'yes';
    }

    /**
     * @return array{units: int, min_nights: int, max_nights: int, buffer: int, deposit_cents: int, prepay: int}
     */
    public static function config(\WC_Product $product): array
    {
        $config = [
            'units' => max(1, (int) $product->get_meta('_madeit_rental_units')),
            'min_nights' => max(1, (int) $product->get_meta('_madeit_rental_min_nights')),
            'max_nights' => max(0, (int) $product->get_meta('_madeit_rental_max_nights')),
            'buffer' => 0,
            'deposit_cents' => 0,
            'prepay' => 100,
        ];
        /**
         * Lets an add-on supply the buffer, deposit and prepayment settings of a product.
         *
         * @param  array<string, int>  $config
         */
        $filtered = apply_filters('madeit_rentals_product_config', $config, $product);

        return is_array($filtered) ? array_merge($config, array_intersect_key($filtered, $config)) : $config;
    }

    public static function today(): string
    {
        return wp_date(Calculator::DATE_FORMAT, null, wp_timezone());
    }

    public static function nightsText(int $nights): string
    {
        /* translators: %d: number of nights */
        return sprintf(_n('%d night', '%d nights', $nights, 'madeit-rentals'), $nights);
    }

    public static function formatDate(string $date): string
    {
        return Calculator::isDate($date) ? wp_date(get_option('date_format'), strtotime($date.' 12:00:00 UTC'), new \DateTimeZone('UTC')) : $date;
    }
}
