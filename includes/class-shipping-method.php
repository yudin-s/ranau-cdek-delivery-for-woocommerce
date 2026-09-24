<?php

declare(strict_types=1);

namespace Ranau\CdekDelivery;

defined('ABSPATH') || exit;

final class ShippingMethod extends \WC_Shipping_Method
{
    public function __construct($instance_id = 0)
    {
        $this->id = 'ranau_cdek_pickup';
        $this->instance_id = absint($instance_id);
        $this->method_title = __('СДЭК в ПВЗ', 'ranau-cdek-delivery-for-woocommerce');
        $this->method_description = __('Выбор ПВЗ или постамата СДЭК с живым расчетом.', 'ranau-cdek-delivery-for-woocommerce');
        $this->supports = array('shipping-zones', 'instance-settings');
        $this->init();
    }

    public function init(): void
    {
        $this->instance_form_fields = array(
            'title' => array(
                'title' => __('Название', 'ranau-cdek-delivery-for-woocommerce'),
                'type' => 'text',
                'default' => __('СДЭК в ПВЗ', 'ranau-cdek-delivery-for-woocommerce'),
            ),
            'free_shipping_threshold' => array(
                'title' => __('Бесплатная доставка от', 'ranau-cdek-delivery-for-woocommerce'),
                'type' => 'price',
                'default' => (string) FreeShippingPolicy::DEFAULT_THRESHOLD,
                'description' => __('Порог считается по сумме товаров до купонов. Укажите 0, чтобы отключить.', 'ranau-cdek-delivery-for-woocommerce'),
                'desc_tip' => true,
            ),
        );
        $this->title = (string) $this->get_option('title', __('СДЭК в ПВЗ', 'ranau-cdek-delivery-for-woocommerce'));
        add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
    }

    public function calculate_shipping($package = array()): void
    {
        $point = $this->session_array('ranau_cdek_pickup_point');
        $quote = $this->session_array('ranau_cdek_pickup_quote');
        $policy = new FreeShippingPolicy($this->id);
        $threshold = $policy->threshold($this->instance_id);
        $subtotal = $policy->subtotal_before_discounts((array) $package);
        $free = $policy->is_eligible((array) $package, $threshold);
        $quoted = !empty($quote['ok']) && isset($quote['price']);
        $ready = !empty($point['code']) && !empty($point['_ranau_committed']) && ($free || $quoted);
        $label = $this->title;
        if ($ready && !empty($quote['period_max'])) {
            /* translators: %d: maximum delivery time in days. */
            $label .= sprintf(__(' (%d дн.)', 'ranau-cdek-delivery-for-woocommerce'), (int) $quote['period_max']);
        }
        $this->add_rate(array(
            'id' => $this->get_rate_id(),
            'label' => $label,
            'cost' => $ready && !$free ? (float) $quote['price'] : 0.0,
            'package' => $package,
            'meta_data' => array(
                'ranau_cdek_pickup_ready' => $ready ? 'yes' : 'no',
                'ranau_cdek_pickup_free_shipping' => $free ? 'yes' : 'no',
                'ranau_cdek_pickup_free_shipping_threshold' => $threshold,
                'ranau_cdek_pickup_subtotal_before_discounts' => $subtotal,
                'ranau_cdek_pickup_point_code' => sanitize_text_field((string) ($point['code'] ?? '')),
                'ranau_cdek_pickup_error' => sanitize_key((string) ($quote['code'] ?? '')),
            ),
        ));
    }

    private function session_array(string $key): array
    {
        if (!function_exists('WC') || !WC()->session) {
            return array();
        }
        $value = WC()->session->get($key, array());
        return is_array($value) ? $value : array();
    }
}
