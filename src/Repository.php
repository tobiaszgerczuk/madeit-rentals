<?php

namespace Madeit\Rentals;

use wpdb;

defined('ABSPATH') || exit;

// Bookings live in a custom table that must always be read fresh, so queries are intentionally not cached.
// The WHERE fragments and IN() placeholder lists are assembled from fixed strings; every value goes through prepare().
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

/**
 * Bookings in their own table. They occupy product units while their status is one of ACTIVE.
 */
final class Repository
{
    public const DB_VERSION = '1';

    /** Statuses that block units in the calendar. */
    public const ACTIVE = ['pending', 'confirmed', 'picked_up'];

    /**
     * @return array<string, string>
     */
    public static function statuses(): array
    {
        return [
            'pending' => __('Awaiting payment', 'madeit-rentals'),
            'confirmed' => __('Confirmed', 'madeit-rentals'),
            'picked_up' => __('Picked up', 'madeit-rentals'),
            'returned' => __('Returned', 'madeit-rentals'),
            'cancelled' => __('Cancelled', 'madeit-rentals'),
        ];
    }

    private wpdb $db;

    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->db = $wpdb;
        $this->table = $wpdb->prefix.'madeit_rentals_bookings';
    }

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$wpdb->prefix}madeit_rentals_bookings (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned NOT NULL,
            order_item_id bigint(20) unsigned NOT NULL DEFAULT 0,
            product_id bigint(20) unsigned NOT NULL,
            quantity int(10) unsigned NOT NULL DEFAULT 1,
            start_date date NOT NULL,
            end_date date NOT NULL,
            deposit_cents bigint(20) unsigned NOT NULL DEFAULT 0,
            later_cents bigint(20) unsigned NOT NULL DEFAULT 0,
            deposit_returned tinyint(1) NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'pending',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY product_dates (product_id, status, start_date, end_date),
            KEY order_id (order_id),
            KEY start_date (start_date)
        ) {$charset};");
        update_option('madeit_rentals_db_version', self::DB_VERSION, false);
    }

    public static function upgradeIfNeeded(): void
    {
        if (get_option('madeit_rentals_db_version') !== self::DB_VERSION) {
            self::install();
        }
    }

    /**
     * @param  array{order_id: int, order_item_id: int, product_id: int, quantity: int, start: string, end: string, deposit_cents: int, later_cents: int, status: string}  $booking
     */
    public function create(array $booking): int
    {
        $this->db->insert($this->table, [
            'order_id' => $booking['order_id'],
            'order_item_id' => $booking['order_item_id'],
            'product_id' => $booking['product_id'],
            'quantity' => $booking['quantity'],
            'start_date' => $booking['start'],
            'end_date' => $booking['end'],
            'deposit_cents' => $booking['deposit_cents'],
            'later_cents' => $booking['later_cents'],
            'status' => $booking['status'],
            'created_at' => current_time('mysql', true),
        ]);

        return (int) $this->db->insert_id;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $row = $this->db->get_row($this->db->prepare("SELECT * FROM %i WHERE id = %d", $this->table, $id), ARRAY_A);

        return $row ?: null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forOrder(int $orderId): array
    {
        return $this->db->get_results($this->db->prepare("SELECT * FROM %i WHERE order_id = %d ORDER BY id", $this->table, $orderId), ARRAY_A) ?: [];
    }

    public function setStatus(int $id, string $status): void
    {
        if (array_key_exists($status, self::statuses())) {
            $this->db->update($this->table, ['status' => $status], ['id' => $id]);
        }
    }

    public function setStatusForOrder(int $orderId, string $status, array $from): void
    {
        if (! array_key_exists($status, self::statuses()) || ! $from) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($from), '%s'));
        $this->db->query($this->db->prepare(
            "UPDATE {$this->table} SET status = %s WHERE order_id = %d AND status IN ($placeholders)",
            $status,
            $orderId,
            ...$from
        ));
    }

    public function markDepositReturned(int $id, bool $returned): void
    {
        $this->db->update($this->table, ['deposit_returned' => $returned ? 1 : 0], ['id' => $id]);
    }

    /**
     * Active bookings of a product overlapping the date range (with room for the buffer).
     *
     * @return array<int, array{start: string, end: string, quantity: int}>
     */
    public function activeFor(int $productId, string $from, string $to, int $bufferDays = 0, int $ignoreOrderId = 0): array
    {
        $statuses = implode(',', array_fill(0, count(self::ACTIVE), '%s'));
        $buffer = max(0, $bufferDays);
        $rows = $this->db->get_results($this->db->prepare(
            "SELECT start_date, end_date, quantity FROM {$this->table}
             WHERE product_id = %d AND status IN ($statuses) AND order_id <> %d
             AND end_date > DATE_SUB(%s, INTERVAL %d DAY) AND start_date < DATE_ADD(%s, INTERVAL %d DAY)",
            $productId,
            ...[...self::ACTIVE, $ignoreOrderId, $from, $buffer, $to, $buffer]
        ), ARRAY_A) ?: [];

        return array_map(static fn (array $row): array => ['start' => $row['start_date'], 'end' => $row['end_date'], 'quantity' => (int) $row['quantity']], $rows);
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function search(string $search, string $status, int $page, int $perPage): array
    {
        [$where, $parameters] = $this->filters($search, $status);
        $countSql = "SELECT COUNT(*) FROM {$this->table} WHERE {$where}";
        $total = (int) $this->db->get_var($parameters ? $this->db->prepare($countSql, ...$parameters) : $countSql);
        $rows = $this->db->get_results($this->db->prepare(
            "SELECT * FROM {$this->table} WHERE {$where} ORDER BY start_date DESC, id DESC LIMIT %d OFFSET %d",
            ...[...$parameters, $perPage, max(0, ($page - 1) * $perPage)]
        ), ARRAY_A) ?: [];

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * @return array{total: int, upcoming: int, out: int, next_start: string}
     */
    public function summary(string $today): array
    {
        $statuses = "'".implode("','", self::ACTIVE)."'";
        $row = $this->db->get_row($this->db->prepare(
            "SELECT COUNT(*) AS total,
                SUM(CASE WHEN status IN ('pending','confirmed') AND start_date >= %s THEN 1 ELSE 0 END) AS upcoming,
                SUM(CASE WHEN status = 'picked_up' THEN 1 ELSE 0 END) AS out_now,
                MIN(CASE WHEN status IN ('pending','confirmed') AND start_date >= %s THEN start_date END) AS next_start
             FROM {$this->table} WHERE status IN ($statuses)",
            $today,
            $today
        ), ARRAY_A) ?: [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'upcoming' => (int) ($row['upcoming'] ?? 0),
            'out' => (int) ($row['out_now'] ?? 0),
            'next_start' => (string) ($row['next_start'] ?? ''),
        ];
    }

    /**
     * @return array{0: string, 1: array<int, int|string>}
     */
    private function filters(string $search, string $status): array
    {
        $where = '1=1';
        $parameters = [];
        if ($search !== '' && ctype_digit($search)) {
            $where .= ' AND (id = %d OR order_id = %d OR product_id = %d)';
            array_push($parameters, (int) $search, (int) $search, (int) $search);
        }
        if (array_key_exists($status, self::statuses())) {
            $where .= ' AND status = %s';
            $parameters[] = $status;
        }

        return [$where, $parameters];
    }
}
