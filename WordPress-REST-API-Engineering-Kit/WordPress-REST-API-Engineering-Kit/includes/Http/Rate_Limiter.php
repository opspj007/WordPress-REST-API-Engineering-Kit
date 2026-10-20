<?php

declare(strict_types=1);

namespace RAEK\Http;

/**
 * Fixed-window counter.
 *
 * With a persistent object cache (Redis/Memcached) increments are atomic (`wp_cache_incr`).
 * Without one it degrades to transients, which are not atomic: under heavy concurrency a few
 * extra requests may slip through. That is acceptable for abuse mitigation; use a gateway
 * (nginx/Cloudflare) when you need hard guarantees.
 */
final class Rate_Limiter
{
    private const GROUP = 'raek_rate_limit';

    /** @return array{allowed:bool,limit:int,remaining:int,reset:int,retry_after:int} */
    public function hit(string $bucket, string $identity, int $limit, int $window): array
    {
        $now = time();
        $window_id = intdiv($now, $window);
        $reset = ($window_id + 1) * $window;
        $key = 'rl_' . $bucket . '_' . md5($identity) . '_' . $window_id;
        $count = $this->increment($key, $reset - $now + 1);

        return [
            'allowed' => $count <= $limit,
            'limit' => $limit,
            'remaining' => max(0, $limit - $count),
            'reset' => $reset,
            'retry_after' => max(1, $reset - $now),
        ];
    }

    private function increment(string $key, int $ttl): int
    {
        if (wp_using_ext_object_cache()) {
            wp_cache_add($key, 0, self::GROUP, $ttl);
            $value = wp_cache_incr($key, 1, self::GROUP);
            if (is_int($value)) {
                return $value;
            }
        }
        $transient = 'raek_' . $key;
        $count = (int) get_transient($transient) + 1;
        set_transient($transient, $count, $ttl);
        return $count;
    }
}
