<?php

declare(strict_types=1);

namespace StayFlow\BusinessModel;

final class WooTaxAdapter
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function registerHooks(): void
    {
        add_filter('woocommerce_product_is_taxable', [$this, 'makeModelBNonTaxable'], 10, 2);
        add_action('woocommerce_before_calculate_totals', [$this, 'beforeCalculateTotals'], 99);
    }

    public function makeModelBNonTaxable(bool $isTaxable, mixed $product): bool
    {
        if (!$product || !is_object($product) || !method_exists($product, 'get_id')) {
            return $isTaxable;
        }

        $roomTypeId = get_post_meta((int) $product->get_id(), '_mphb_room_type_id', true);

        if ($roomTypeId) {
            $model = get_post_meta((int) $roomTypeId, BSBT_META_MODEL, true) ?: 'model_a';
            if ($model === 'model_b') {
                return false;
            }
        }

        return $isTaxable;
    }

    public function beforeCalculateTotals(mixed $cart): void
    {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        if (!$cart || !is_object($cart)) {
            return;
        }

        foreach ($cart->get_cart() as $item) {
            if (empty($item['data']) || !is_object($item['data'])) {
                continue;
            }

            $product = $item['data'];

            if (!method_exists($product, 'get_id')) {
                continue;
            }

            $roomTypeId = get_post_meta((int) $product->get_id(), '_mphb_room_type_id', true);

            if ($roomTypeId) {
                $model = get_post_meta((int) $roomTypeId, BSBT_META_MODEL, true) ?: 'model_a';
                if ($model === 'model_b') {
                    // Для Model B: никаких налогов на товар в WooCommerce.
                    $product->set_tax_status('none');
                    $product->set_tax_class('');
                }
            }
        }
    }
}
