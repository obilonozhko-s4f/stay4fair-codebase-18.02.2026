<?php

declare(strict_types=1);

namespace StayFlow\BusinessModel;

final class BusinessModelServiceProvider
{
    public function boot(): void
    {
        /**
         * RU:
         * Исторически здесь был sync цен: owner_price -> MPHB rates для Model B.
         * По новой зафиксированной архитектуре это запрещено.
         *
         * Поэтому hook оставляем, но handler делает только guard + no-op.
         * Это защищает от падений и сохраняет совместимость, если где-то ожидают этот hook.
         */
        add_action('acf/save_post', [$this, 'handleAcfSavePost'], 30);

        // Woo tax hooks могут быть нужны для WooCommerce (оставляем как было).
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

        if ($postId <= 0) {
            return;
        }

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

        /**
         * RU:
         * НОВАЯ ЛОГИКА:
         * - Model B: source of truth цены гостя = MPHB Rates/Season Prices.
         *           owner_price_per_night НЕ влияет на цену и может быть пустым.
         *           Мы НЕ пишем в mphb_price, НЕ пишем в mphb_season_prices, НЕ синхроним rates.
         *
         * - Model A: guest price = MPHB Rates/Season Prices.
         *           owner_price_per_night используется для payout/маржи, но не для rates.
         *
         * Поэтому здесь intentionally NO-OP.
         */
        return;
    }
}
