<?php

declare(strict_types=1);

namespace StayFlow\BusinessModel;

final class BusinessModelServiceProvider
{
    public function boot(): void
    {
        add_action('acf/save_post', [$this, 'handleAcfSavePost'], 30);

        $engine = BusinessModelEngine::instance();
        $engine->wooTax()->registerHooks();
    }

    public function handleAcfSavePost(mixed $postId): void
    {
        // ACF может вызывать save_post для 'options' и т.п.
        if (!is_numeric($postId)) {
            return;
        }

        $postId = (int) $postId;

        if (get_post_type($postId) !== 'mphb_room_type') {
            return;
        }

        // Безопасность от автосейва/ревизий (на всякий случай)
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (wp_is_post_revision($postId)) {
            return;
        }

        $model = get_post_meta($postId, BSBT_META_MODEL, true) ?: 'model_a';

        // Синхронизация только для Model B
        if ($model !== 'model_b') {
            return;
        }

        $engine = BusinessModelEngine::instance();

        $ownerPrice = (float) get_post_meta($postId, BSBT_META_OWNER_PRICE, true);
        $finalPrice = $engine->resolveFinalGrossPrice($ownerPrice, $model);

        if ($finalPrice > 0) {
            $engine->rates()->syncToMphbDatabase($postId, $finalPrice);
        }
    }
}
