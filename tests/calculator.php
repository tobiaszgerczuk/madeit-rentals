<?php

/**
 * Logika wynajmu (daty, dostępność, ceny). Uruchom: php tests/calculator.php
 */

define("ABSPATH", "/");
require __DIR__."/../src/Calculator.php";

use Madeit\Rentals\Calculator;

function expectThat(bool $condition, string $message): void
{
    if (! $condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
}

// Daty i noce.
expectThat(Calculator::isDate('2026-02-28') && ! Calculator::isDate('2026-02-30') && ! Calculator::isDate('jutro'), 'Walidacja dat.');
expectThat(Calculator::nights('2026-10-10', '2026-10-13') === 3, 'Trzy noce.');
expectThat(Calculator::nights('2026-10-13', '2026-10-10') === 0, 'Odwrócony zakres to zero nocy.');
expectThat(Calculator::nightDates('2026-12-30', '2027-01-02') === ['2026-12-30', '2026-12-31', '2027-01-01'], 'Noce przez Nowy Rok bez dnia zwrotu.');

// Zasady terminu.
$rules = ['min_nights' => 2, 'max_nights' => 7, 'lead_days' => 1, 'horizon_days' => 365];
expectThat(Calculator::dateErrors('2026-10-20', '2026-10-23', $rules, '2026-10-16') === [], 'Poprawny termin.');
expectThat(Calculator::dateErrors('2026-10-20', '2026-10-21', $rules, '2026-10-16') === ['min'], 'Za krótki pobyt.');
expectThat(Calculator::dateErrors('2026-10-20', '2026-10-29', $rules, '2026-10-16') === ['max'], 'Za długi pobyt.');
expectThat(Calculator::dateErrors('2026-10-16', '2026-10-19', $rules, '2026-10-16') === ['lead'], 'Brak wymaganego wyprzedzenia.');
expectThat(Calculator::dateErrors('2026-10-20', '2026-10-20', $rules, '2026-10-16') === ['order'], 'Zwrot tego samego dnia.');
expectThat(Calculator::dateErrors('2028-10-20', '2028-10-22', $rules, '2026-10-16') === ['horizon'], 'Poza horyzontem rezerwacji.');
expectThat(Calculator::dateErrors('abc', '2026-10-22', $rules, '2026-10-16') === ['invalid'], 'Błędna data.');
expectThat(Calculator::dateErrors('2026-10-20', '2026-10-21', ['min_nights' => 0, 'max_nights' => 0, 'lead_days' => 0, 'horizon_days' => 0], '2026-10-16') === [], 'Zera oznaczają brak ograniczeń.');

// Dostępność: 3 sztuki, rezerwacje 20–23 (2 szt.) i 22–25 (1 szt.).
$bookings = [
    ['start' => '2026-10-20', 'end' => '2026-10-23', 'quantity' => 2],
    ['start' => '2026-10-22', 'end' => '2026-10-25', 'quantity' => 1],
];
expectThat(Calculator::isAvailable(3, $bookings, 0, '2026-10-20', '2026-10-22', 1), '1 szt. na 20–22 mieści się (zajęte 2 z 3).');
expectThat(! Calculator::isAvailable(3, $bookings, 0, '2026-10-20', '2026-10-22', 2), '2 szt. na 20–22 się nie mieszczą.');
expectThat(! Calculator::isAvailable(3, $bookings, 0, '2026-10-21', '2026-10-24', 1), 'Noc 22 jest zajęta w całości (2+1 z 3).');
expectThat(Calculator::isAvailable(3, $bookings, 0, '2026-10-23', '2026-10-24', 2), 'Dzień zwrotu 23 jest wolny dla nowej rezerwacji (zajęte 1 z 3).');
expectThat(Calculator::isAvailable(3, $bookings, 0, '2026-10-25', '2026-10-27', 3), 'Po zwrocie wszystko wolne.');
expectThat(! Calculator::isAvailable(3, [], 0, '2026-10-25', '2026-10-27', 4), 'Nie wypożyczysz więcej niż jest sztuk.');
expectThat(! Calculator::isAvailable(3, [], 0, '2026-10-25', '2026-10-25', 1), 'Zero nocy to brak rezerwacji.');

// Bufor: 1 dzień przed i po (np. serwis); rezerwacja 20–23 blokuje noce 19–23.
$single = [['start' => '2026-10-20', 'end' => '2026-10-23', 'quantity' => 1]];
expectThat(Calculator::isAvailable(1, $single, 0, '2026-10-23', '2026-10-25', 1), 'Bez bufora zwrot i odbiór tego samego dnia.');
expectThat(! Calculator::isAvailable(1, $single, 1, '2026-10-23', '2026-10-25', 1), 'Bufor blokuje dzień po zwrocie.');
expectThat(! Calculator::isAvailable(1, $single, 1, '2026-10-17', '2026-10-20', 1), 'Bufor blokuje dzień przed odbiorem.');
expectThat(Calculator::isAvailable(1, $single, 1, '2026-10-24', '2026-10-26', 1), 'Po buforze znów wolne.');

$remaining = Calculator::remainingByDate(3, $bookings, 0, '2026-10-01', '2026-12-31');
expectThat($remaining === ['2026-10-20' => 1, '2026-10-21' => 1, '2026-10-22' => 0, '2026-10-23' => 2, '2026-10-24' => 2], 'Wolne sztuki dla dni z zajętością.');
expectThat(Calculator::remainingByDate(3, $bookings, 0, '2026-10-22', '2026-10-24') === ['2026-10-22' => 0, '2026-10-23' => 2], 'Zakres ucina wynik.');

// Flat stay price: three nights at 100.00, everything due now.
$stay = Calculator::stay(10000, '2026-10-20', '2026-10-23');
expectThat($stay['nights'] === 3 && $stay['total'] === 30000 && $stay['now'] === 30000 && $stay['later'] === 0 && $stay['deposit'] === 0, 'Flat stay price.');

echo "PASS: calculator\n";
