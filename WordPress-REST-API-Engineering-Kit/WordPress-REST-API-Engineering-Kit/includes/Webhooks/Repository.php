<?php

declare(strict_types=1);

namespace RAEK\Webhooks;

use RAEK\Support\Secrets;

/** Data access for webhook subscriptions and delivery attempts (prepared statements only). */
final class Repository
{
    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'raek_webhooks';
    }

    public static function deliveries_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'raek_deliveries';
    }

    /** @param string[] $events @return array{0:int,1:string} [id, plaintext secret (shown once)] */
    public function create(int $user_id, string $url, array $events, string $description): array
    {
        global $wpdb;
        $secret = $this->new_secret();
        $ok = $wpdb->insert(self::table(), [
            'user_id' => $user_id,
            'url' => $url,
            'description' => $description,
            'events' => (string) wp_json_encode(array_values($events)),
            'secret' => Secrets::encrypt($secret),
            'active' => 1,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ], ['%d', '%s', '%s', '%s', '%s', '%d', '%s']);
        if (!$ok) {
            throw new \RuntimeException('Could not create the webhook.');
        }
        return [(int) $wpdb->insert_id, $secret];
    }

    public function new_secret(): string
    {
        return 'whsec_' . bin2hex(random_bytes(24));
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', $id), ARRAY_A); // phpcs:ignore
        return $row ?: null;
    }

    /** @return array{0:list<array<string,mixed>>,1:int} */
    public function list_for_user(int $user_id, int $page, int $per_page): array
    {
        global $wpdb;
        $t = self::table();
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE user_id = %d ORDER BY id DESC LIMIT %d OFFSET %d", $user_id, $per_page, ($page - 1) * $per_page), ARRAY_A); // phpcs:ignore
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE user_id = %d", $user_id)); // phpcs:ignore
        return [$rows ?: [], $total];
    }

    public function count_for_user(int $user_id): int
    {
        return $this->list_for_user($user_id, 1, 1)[1];
    }

    /** @param array<string,mixed> $fields column => value (only whitelisted columns are written) */
    public function update(int $id, array $fields): void
    {
        global $wpdb;
        $allowed = ['url' => '%s', 'description' => '%s', 'events' => '%s', 'secret' => '%s', 'active' => '%d', 'failure_count' => '%d'];
        $fields = array_intersect_key($fields, $allowed);
        if ($fields) {
            $wpdb->update(self::table(), $fields, ['id' => $id], array_map(static fn($k) => $allowed[$k], array_keys($fields)), ['%d']);
        }
    }

    public function delete(int $id): void
    {
        global $wpdb;
        $wpdb->delete(self::deliveries_table(), ['webhook_id' => $id], ['%d']);
        $wpdb->delete(self::table(), ['id' => $id], ['%d']);
    }

    /** @return list<array<string,mixed>> Active subscriptions of $user_id for $event. */
    public function active_for_event(int $user_id, string $event): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE user_id = %d AND active = 1', $user_id), ARRAY_A); // phpcs:ignore
        return array_values(array_filter($rows ?: [], static fn($r) => in_array($event, (array) json_decode((string) $r['events'], true), true)));
    }

    public function record_failure(int $id, int $disable_after): void
    {
        global $wpdb;
        $t = self::table();
        $wpdb->query($wpdb->prepare("UPDATE $t SET failure_count = failure_count + 1, active = IF(failure_count >= %d, 0, active) WHERE id = %d", $disable_after, $id)); // phpcs:ignore
    }

    public function record_success(int $id): void
    {
        $this->update($id, ['failure_count' => 0]);
    }

    public function add_delivery(int $webhook_id, string $event, string $event_id, string $payload): int
    {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->insert(self::deliveries_table(), [
            'webhook_id' => $webhook_id, 'event' => $event, 'event_id' => $event_id, 'payload' => $payload,
            'status' => 'pending', 'attempt' => 0, 'created_at' => $now, 'updated_at' => $now,
        ], ['%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s']);
        return (int) $wpdb->insert_id;
    }

    /** @param array<string,mixed> $fields */
    public function update_delivery(int $id, array $fields): void
    {
        global $wpdb;
        $allowed = ['status' => '%s', 'attempt' => '%d', 'response_code' => '%d', 'response_body' => '%s', 'duration_ms' => '%d'];
        $fields = array_intersect_key($fields, $allowed);
        $wpdb->update(
            self::deliveries_table(),
            $fields + ['updated_at' => gmdate('Y-m-d H:i:s')],
            ['id' => $id],
            array_merge(array_map(static fn($k) => $allowed[$k], array_keys($fields)), ['%s']),
            ['%d']
        );
    }

    /** @return array<string,mixed>|null */
    public function find_delivery(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::deliveries_table() . ' WHERE id = %d', $id), ARRAY_A); // phpcs:ignore
        return $row ?: null;
    }

    /** @return array{0:list<array<string,mixed>>,1:int} */
    public function deliveries(int $webhook_id, ?string $status, int $page, int $per_page): array
    {
        global $wpdb;
        $t = self::deliveries_table();
        $where = 'webhook_id = %d';
        $args = [$webhook_id];
        if ($status !== null) {
            $where .= ' AND status = %s';
            $args[] = $status;
        }
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, event, event_id, status, attempt, response_code, response_body, duration_ms, created_at, updated_at FROM $t WHERE $where ORDER BY id DESC LIMIT %d OFFSET %d", ...[...$args, $per_page, ($page - 1) * $per_page]), ARRAY_A); // phpcs:ignore
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE $where", ...$args)); // phpcs:ignore
        return [$rows ?: [], $total];
    }

    /** Public representation: the secret is never included. @param array<string,mixed> $row @return array<string,mixed> */
    public function present(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'url' => (string) $row['url'],
            'description' => (string) $row['description'],
            'events' => array_values((array) json_decode((string) $row['events'], true)),
            'active' => (bool) $row['active'],
            'failure_count' => (int) $row['failure_count'],
            'created_at' => str_replace(' ', 'T', (string) $row['created_at']) . 'Z',
        ];
    }
}
