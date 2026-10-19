<?php

namespace Madeit\Rentals;

defined('ABSPATH') || exit;

/**
 * Pure rental logic (no WordPress dependency): dates, availability and prices.
 * A booking occupies the nights from the pick-up day to the day before the return (the return day stays free).
 */
final class Calculator
{
    public const DATE_FORMAT = 'Y-m-d';

    public static function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!'.self::DATE_FORMAT, $value, new \DateTimeZone('UTC'));

        return $date !== false && $date->format(self::DATE_FORMAT) === $value;
    }

    public static function nights(string $start, string $end): int
    {
        if (! self::isDate($start) || ! self::isDate($end)) {
            return 0;
        }
        $days = (int) (new \DateTimeImmutable($start, new \DateTimeZone('UTC')))->diff(new \DateTimeImmutable($end, new \DateTimeZone('UTC')))->format('%r%a');

        return max(0, $days);
    }

    public static function addDays(string $date, int $days): string
    {
        return (new \DateTimeImmutable($date, new \DateTimeZone('UTC')))->modify(($days >= 0 ? '+' : '').$days.' days')->format(self::DATE_FORMAT);
    }

    /**
     * All nights (the date of the first day of each night) from pick-up to return, without the return day.
     *
     * @return array<int, string>
     */
    public static function nightDates(string $start, string $end): array
    {
        $dates = [];
        for ($i = 0, $count = self::nights($start, $end); $i < $count; $i++) {
            $dates[] = self::addDays($start, $i);
        }

        return $dates;
    }

    /**
     * Date errors: an empty list means the dates meet the product and shop rules.
     *
     * @param  array{min_nights: int, max_nights: int, lead_days: int, horizon_days: int}  $rules
     * @return array<int, string> error codes: invalid, order, min, max, lead, horizon
     */
    public static function dateErrors(string $start, string $end, array $rules, string $today): array
    {
        if (! self::isDate($start) || ! self::isDate($end)) {
            return ['invalid'];
        }
        $nights = self::nights($start, $end);
        if ($nights < 1) {
            return ['order'];
        }
        $errors = [];
        if ($nights < max(1, $rules['min_nights'])) {
            $errors[] = 'min';
        }
        if ($rules['max_nights'] > 0 && $nights > $rules['max_nights']) {
            $errors[] = 'max';
        }
        if ($start < self::addDays($today, max(0, $rules['lead_days']))) {
            $errors[] = 'lead';
        }
        if ($rules['horizon_days'] > 0 && $end > self::addDays($today, $rules['horizon_days'])) {
            $errors[] = 'horizon';
        }

        return $errors;
    }

    /**
     * Occupancy of every night in the from–to range (including the buffers before and after bookings).
     *
     * @param  array<int, array{start: string, end: string, quantity: int}>  $bookings
     * @return array<string, int> date => occupied units (only nights that are occupied)
     */
    public static function occupancy(array $bookings, int $bufferDays, string $from, string $to): array
    {
        $buffer = max(0, $bufferDays);
        $occupied = [];
        foreach ($bookings as $booking) {
            if (! self::isDate($booking['start']) || ! self::isDate($booking['end'])) {
                continue;
            }
            $first = max($from, self::addDays($booking['start'], -$buffer));
            $last = min($to, self::addDays($booking['end'], $buffer));
            for ($date = $first; $date < $last; $date = self::addDays($date, 1)) {
                $occupied[$date] = ($occupied[$date] ?? 0) + max(0, $booking['quantity']);
            }
        }

        return $occupied;
    }

    /**
     * @param  array<int, array{start: string, end: string, quantity: int}>  $bookings
     */
    public static function isAvailable(int $units, array $bookings, int $bufferDays, string $start, string $end, int $quantity): bool
    {
        if ($quantity < 1 || $quantity > $units || self::nights($start, $end) < 1) {
            return false;
        }
        $occupied = self::occupancy($bookings, $bufferDays, $start, $end);
        foreach (self::nightDates($start, $end) as $night) {
            if (($occupied[$night] ?? 0) + $quantity > $units) {
                return false;
            }
        }

        return true;
    }

    /**
     * How many units stay free on nights when anything is occupied.
     *
     * @param  array<int, array{start: string, end: string, quantity: int}>  $bookings
     * @return array<string, int> date => free units (0 to units)
     */
    public static function remainingByDate(int $units, array $bookings, int $bufferDays, string $from, string $to): array
    {
        $remaining = [];
        foreach (self::occupancy($bookings, $bufferDays, $from, $to) as $date => $count) {
            $remaining[$date] = max(0, $units - $count);
        }
        ksort($remaining);

        return $remaining;
    }

    /**
     * Flat price of a stay for one unit, in cents, with the payment split all due now.
     *
     * @return array{nights: int, subtotal: int, discount_percent: int, discount: int, total: int, average: int, now: int, later: int, deposit: int, online: int}
     */
    public static function stay(int $nightlyCents, string $start, string $end): array
    {
        $nights = count(self::nightDates($start, $end));
        $total = max(0, $nightlyCents) * $nights;

        return [
            'nights' => $nights,
            'subtotal' => $total,
            'discount_percent' => 0,
            'discount' => 0,
            'total' => $total,
            'average' => $nights > 0 ? (int) round($total / $nights) : 0,
            'now' => $total,
            'later' => 0,
            'deposit' => 0,
            'online' => $total,
        ];
    }
}
