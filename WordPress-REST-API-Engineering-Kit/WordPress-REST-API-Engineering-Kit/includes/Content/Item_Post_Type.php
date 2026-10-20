<?php

declare(strict_types=1);

namespace RAEK\Content;

/**
 * Storage for the demo resource. Not exposed through core's wp/v2 routes:
 * the API is the only door in, so every rule lives in one place.
 */
final class Item_Post_Type
{
    public const POST_TYPE = 'raek_item';
    public const TAXONOMY = 'raek_tag';

    public function register(): void
    {
        add_action('init', [$this, 'register_types']);
    }

    public function register_types(): void
    {
        register_post_type(self::POST_TYPE, [
            'label' => 'API Items',
            'public' => false,
            'show_ui' => false,
            'show_in_rest' => false,
            'rewrite' => false,
            'supports' => ['title', 'editor', 'author'],
        ]);

        register_taxonomy(self::TAXONOMY, self::POST_TYPE, [
            'label' => 'API Item Tags',
            'public' => false,
            'show_ui' => false,
            'show_in_rest' => false,
            'rewrite' => false,
        ]);
    }
}
