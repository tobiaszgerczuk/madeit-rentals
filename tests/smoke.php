<?php

/**
 * Full flow without the Pro add-on: cart, price, booking, availability, cancellation.
 * Uruchom: wp eval-file wp-content/plugins/madeit-rentals/tests/smoke.php
 * Tworzy produkt i zamówienie, więc używaj wyłącznie w izolowanym sklepie testowym.
 */

use Madeit\Rentals\Calculator;
use Madeit\Rentals\Plugin;
use Madeit\Rentals\Settings;
use Madeit\Rentals\Repository;

function expectRental(bool $condition, string $message): void
{
    if (! $condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
}

Repository::install();
update_option('woocommerce_currency', 'PLN');
update_option('woocommerce_calc_taxes', 'no');

$product = new WC_Product_Simple;
$product->set_name('Kamera testowa (wynajem)');
$product->set_regular_price('100');
$product->set_status('publish');
$product->set_virtual(true);
$product->update_meta_data('_madeit_rental', 'yes');
$product->update_meta_data('_madeit_rental_units', '2');
$product->update_meta_data('_madeit_rental_min_nights', '2');
$product->update_meta_data('_madeit_rental_max_nights', '14');
$product->save();

$start = Calculator::addDays(Settings::today(), 10);
$end = Calculator::addDays($start, 3);
$_REQUEST['madeit_rental_start'] = $start;
$_REQUEST['madeit_rental_end'] = $end;

wc_load_cart();
WC()->cart->empty_cart();
$repository = new Repository;
$plugin = new Plugin($repository);

// WC_Cart::add_to_cart() nie waliduje; robi to formularz produktu (WC_Form_Handler) i Store API.
$addToCart = static function (int $productId, int $quantity) {
    if (! apply_filters('woocommerce_add_to_cart_validation', true, $productId, $quantity, 0, [])) {
        return false;
    }

    return WC()->cart->add_to_cart($productId, $quantity);
};

// Brak dat nie dodaje do koszyka.
unset($_REQUEST['madeit_rental_start'], $_REQUEST['madeit_rental_end']);
expectRental($addToCart($product->get_id(), 1) === false, 'Bez dat produkt nie trafia do koszyka.');
wc_clear_notices();
$_REQUEST['madeit_rental_start'] = $start;
$_REQUEST['madeit_rental_end'] = $end;

// Za krótki pobyt.
$_REQUEST['madeit_rental_end'] = Calculator::addDays($start, 1);
expectRental($addToCart($product->get_id(), 1) === false, 'Pobyt poniżej minimum jest odrzucany.');
wc_clear_notices();
$_REQUEST['madeit_rental_end'] = $end;

$key = $addToCart($product->get_id(), 1);
expectRental(is_string($key), 'Poprawny termin trafia do koszyka.');
unset($_REQUEST['madeit_rental_start'], $_REQUEST['madeit_rental_end']);
$withoutDates = WC()->cart->add_to_cart($product->get_id(), 1);
$_REQUEST['madeit_rental_start'] = $start;
$_REQUEST['madeit_rental_end'] = $end;
expectRental(is_string($withoutDates) && WC()->cart->get_cart()[$withoutDates] && empty(WC()->cart->get_cart()[$withoutDates]['madeit_rental']), 'Pozycja dodana pominięciem formularza nie ma dat.');
$cartErrors = new ReflectionMethod($plugin, 'cartErrors');
expectRental(count($cartErrors->invoke($plugin, WC()->cart)) === 1, 'Koszyk z pozycją bez dat zgłasza błąd przy płatności.');
WC()->cart->remove_cart_item($withoutDates);
WC()->cart->calculate_totals();
$item = WC()->cart->get_cart()[$key];
expectRental((float) $item['data']->get_price() === 300.0, 'Cena pozycji to 3 noce × 100 zł.');
expectRental(count(WC()->cart->get_fees()) === 0, 'Without the add-on there are no deposit or prepayment fees.');
expectRental(abs((float) WC()->cart->get_total('edit') - 300.0) < 0.001, 'Total is 300 (got '.WC()->cart->get_total('edit').').');

// Zamówienie tworzy rezerwację.
$orderId = WC()->checkout()->create_order(['payment_method' => 'cod', 'billing_email' => 'klient@example.test', 'billing_first_name' => 'Test', 'billing_last_name' => 'Klient']);
expectRental(! is_wp_error($orderId) && $orderId > 0, 'Zamówienie zostało utworzone.');
$order = wc_get_order($orderId);
do_action('woocommerce_checkout_order_processed', $orderId, [], $order);
do_action('woocommerce_checkout_order_processed', $orderId, [], $order);
$bookings = $repository->forOrder($orderId);
expectRental(count($bookings) === 1, 'Dokładnie jedna rezerwacja na pozycję (bez duplikatów).');
expectRental($bookings[0]['status'] === 'pending' && $bookings[0]['start_date'] === $start && (int) $bookings[0]['deposit_cents'] === 0, 'Booking awaits payment with no deposit.');

// Dostępność: 2 sztuki, jedna zajęta.
$quote = $plugin->quote($product, $start, $end, 2);
expectRental((bool) $quote['errors'], 'Dwie sztuki nie są już dostępne.');
$quote = $plugin->quote($product, $start, $end, 1);
expectRental(! $quote['errors'], 'Jedna sztuka jest jeszcze dostępna.');

// Płatność potwierdza, anulowanie zwalnia.
$order->update_status('processing');
expectRental($repository->find((int) $bookings[0]['id'])['status'] === 'confirmed', 'Opłacone zamówienie potwierdza rezerwację.');
$order->update_status('cancelled');
expectRental($repository->find((int) $bookings[0]['id'])['status'] === 'cancelled', 'Anulowane zamówienie anuluje rezerwację.');
expectRental(! $plugin->quote($product, $start, $end, 2)['errors'], 'Po anulowaniu sztuki są znów wolne.');

// REST: dostępność.
$response = rest_do_request(new WP_REST_Request('GET', '/madeit-rentals/v1/availability'));
expectRental($response->get_status() === 400, 'Dostępność wymaga produktu.');
$request = new WP_REST_Request('GET', '/madeit-rentals/v1/availability');
$request->set_param('product', $product->get_id());
$data = rest_do_request($request)->get_data();
expectRental($data['units'] === 2 && isset($data['first'], $data['last']), 'Endpoint dostępności zwraca sztuki i zakres dat.');
$request = new WP_REST_Request('GET', '/madeit-rentals/v1/quote');
$request->set_param('product', $product->get_id());
$request->set_param('start', $start);
$request->set_param('end', $end);
$data = rest_do_request($request)->get_data();
expectRental($data['valid'] === true && str_contains($data['summary'], '300'), 'Endpoint wyceny zwraca podsumowanie.');

WC()->cart->empty_cart();
$order->delete(true);
$product->delete(true);
echo "OK\n";
