<?php

declare(strict_types=1);

namespace RAEK\Webhooks;

use RAEK\Support\Secrets;

/**
 * Domain events -> signed HTTP deliveries.
 *
 * Reliability model: at-least-once. Each event becomes a persisted delivery row, sent from
 * WP-Cron (so API latency never depends on a customer's server). Failures retry with
 * exponential backoff (30s, 2m, 8m, 32m); subscriptions that keep failing are auto-disabled.
 * Receivers must be idempotent: dedupe on the stable `X-RAEK-Event-Id`.
 */
final class Dispatcher
{
    public const EVENTS = ['item.created', 'item.updated', 'item.deleted'];
    public const MAX_ATTEMPTS = 5;
    private const DISABLE_AFTER = 20;

    public function __construct(private Repository $repository)
    {
    }

    public function register(): void
    {
        add_action('raek_item_created', fn(int $id, array $item, int $owner) => $this->enqueue('item.created', $owner, $item), 10, 3);
        add_action('raek_item_updated', fn(int $id, array $item, int $owner) => $this->enqueue('item.updated', $owner, $item), 10, 3);
        add_action('raek_item_deleted', fn(int $id, array $item, int $owner) => $this->enqueue('item.deleted', $owner, $item), 10, 3);
        add_action('raek_deliver_webhook', [$this, 'deliver'], 10, 1);
    }

    /** @param array<string,mixed> $object */
    public function enqueue(string $event, int $owner_id, array $object): void
    {
        foreach ($this->repository->active_for_event($owner_id, $event) as $hook) {
            $this->create_delivery((int) $hook['id'], $event, ['object' => $object]);
        }
    }

    /** Sends a synthetic `ping` so integrators can verify their endpoint and signature code. */
    public function ping(int $webhook_id): int
    {
        return $this->create_delivery($webhook_id, 'ping', ['message' => 'pong']);
    }

    /** @param array<string,mixed> $data */
    private function create_delivery(int $webhook_id, string $event, array $data): int
    {
        $event_id = wp_generate_uuid4();
        $payload = (string) wp_json_encode([
            'id' => $event_id,
            'type' => $event,
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'data' => $data,
        ]);
        $id = $this->repository->add_delivery($webhook_id, $event, $event_id, $payload);
        $this->schedule($id, 0);
        return $id;
    }

    private function schedule(int $delivery_id, int $delay): void
    {
        wp_schedule_single_event(time() + $delay, 'raek_deliver_webhook', [$delivery_id]);
        if ($delay === 0 && function_exists('spawn_cron')) {
            spawn_cron();
        }
    }

    public function deliver(int $delivery_id): void
    {
        $delivery = $this->repository->find_delivery($delivery_id);
        if (!$delivery || $delivery['status'] === 'success') {
            return;
        }

        $hook = $this->repository->find((int) $delivery['webhook_id']);
        $secret = $hook ? Secrets::decrypt((string) $hook['secret']) : null;
        if (!$hook || !(int) $hook['active'] || $secret === null) {
            $this->repository->update_delivery($delivery_id, ['status' => 'failed', 'response_body' => 'webhook_inactive']);
            return;
        }

        $attempt = (int) $delivery['attempt'] + 1;
        $timestamp = time();
        $started = microtime(true);

        $response = wp_safe_remote_post((string) $hook['url'], [
            'timeout' => 5,
            'redirection' => 0, // a redirect could point at an internal address
            'user-agent' => 'RAEK-Webhooks/' . \RAEK\VERSION,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-RAEK-Event' => (string) $delivery['event'],
                'X-RAEK-Event-Id' => (string) $delivery['event_id'],
                'X-RAEK-Attempt' => (string) $attempt,
                'X-RAEK-Timestamp' => (string) $timestamp,
                'X-RAEK-Signature' => Signer::sign($secret, $timestamp, (string) $delivery['payload']),
            ],
            'body' => (string) $delivery['payload'],
        ]);

        $duration = (int) round((microtime(true) - $started) * 1000);
        if (is_wp_error($response)) {
            $code = null;
            $body = $response->get_error_message();
            $ok = false;
        } else {
            $code = (int) wp_remote_retrieve_response_code($response);
            $body = wp_strip_all_tags(wp_remote_retrieve_body($response));
            $ok = $code >= 200 && $code < 300;
        }

        $result = ['attempt' => $attempt, 'duration_ms' => $duration, 'response_body' => substr((string) $body, 0, 500)];
        if ($code !== null) {
            $result['response_code'] = $code;
        }

        if ($ok) {
            $this->repository->update_delivery($delivery_id, $result + ['status' => 'success']);
            $this->repository->record_success((int) $hook['id']);
        } elseif ($attempt < self::MAX_ATTEMPTS) {
            $this->repository->update_delivery($delivery_id, $result + ['status' => 'pending']);
            $this->schedule($delivery_id, 30 * (4 ** ($attempt - 1)));
        } else {
            $this->repository->update_delivery($delivery_id, $result + ['status' => 'failed']);
            $this->repository->record_failure((int) $hook['id'], self::DISABLE_AFTER);
        }
    }
}
