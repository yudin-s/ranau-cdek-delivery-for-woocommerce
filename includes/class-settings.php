<?php

declare(strict_types=1);

namespace Ranau\CdekDelivery;

defined('ABSPATH') || exit;

final class Settings
{
    public const OPTION = 'ranau_cdek_delivery_settings';

    public function init(): void
    {
        add_action('admin_menu', array($this, 'add_page'));
        add_action('admin_init', array($this, 'register'));
    }

    public function add_page(): void
    {
        add_submenu_page(
            'woocommerce',
            __('Ranau CDEK Delivery', 'ranau-cdek-delivery-for-woocommerce'),
            __('Ranau CDEK Delivery', 'ranau-cdek-delivery-for-woocommerce'),
            'manage_woocommerce',
            'ranau-cdek-delivery',
            array($this, 'render')
        );
    }

    public function register(): void
    {
        register_setting('ranau_cdek_delivery', self::OPTION, array(
            'type' => 'array',
            'sanitize_callback' => array($this, 'sanitize'),
            'default' => array(),
        ));
        add_settings_section(
            'ranau_cdek_delivery_api',
            __('CDEK API v2', 'ranau-cdek-delivery-for-woocommerce'),
            '__return_false',
            'ranau_cdek_delivery'
        );
        foreach ($this->fields() as $key => $field) {
            add_settings_field(
                $key,
                $field['label'],
                array($this, 'render_field'),
                'ranau_cdek_delivery',
                'ranau_cdek_delivery_api',
                array('key' => $key, 'type' => $field['type'], 'description' => $field['description'])
            );
        }
    }

    /** @param mixed $value
     *  @return array<string, string>
     */
    public function sanitize($value): array
    {
        $value = is_array($value) ? $value : array();
        $clean = array();
        foreach ($this->fields() as $key => $field) {
            $raw = trim((string) ($value[$key] ?? ''));
            if ($key === 'environment') {
                $clean[$key] = in_array($raw, array('production', 'test'), true) ? $raw : 'production';
            } elseif ($key === 'allowed_tariff_codes') {
                $codes = array_filter(array_map('absint', preg_split('/[\s,;]+/', $raw) ?: array()));
                $clean[$key] = implode(',', array_values(array_unique($codes)));
            } else {
                $clean[$key] = sanitize_text_field($raw);
            }
        }

        return $clean;
    }

    /** @param array<string, string> $args */
    public function render_field(array $args): void
    {
        $key = sanitize_key((string) ($args['key'] ?? ''));
        $type = (string) ($args['type'] ?? 'text');
        $settings = self::all();
        $value = (string) ($settings[$key] ?? '');
        if ($type === 'select') {
            echo '<select name="' . esc_attr(self::OPTION . '[' . $key . ']') . '">';
            echo '<option value="production" ' . selected($value, 'production', false) . '>' . esc_html__('Production', 'ranau-cdek-delivery-for-woocommerce') . '</option>';
            echo '<option value="test" ' . selected($value, 'test', false) . '>' . esc_html__('Test', 'ranau-cdek-delivery-for-woocommerce') . '</option>';
            echo '</select>';
        } else {
            printf(
                '<input class="regular-text" type="%1$s" name="%2$s[%3$s]" value="%4$s" autocomplete="off">',
                esc_attr($type),
                esc_attr(self::OPTION),
                esc_attr($key),
                esc_attr($value)
            );
        }
        if (!empty($args['description'])) {
            echo '<p class="description">' . esc_html((string) $args['description']) . '</p>';
        }
    }

    public function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        echo '<div class="wrap"><h1>' . esc_html__('Ranau CDEK Delivery', 'ranau-cdek-delivery-for-woocommerce') . '</h1>';
        echo '<p>' . esc_html__('Плагин использует CDEK API только для авторизации, справочника городов, ПВЗ и расчета тарифа. Создание отправлений и печатных форм отсутствует.', 'ranau-cdek-delivery-for-woocommerce') . '</p>';
        echo '<form method="post" action="options.php">';
        settings_fields('ranau_cdek_delivery');
        do_settings_sections('ranau_cdek_delivery');
        submit_button();
        echo '</form></div>';
    }

    /** @return array<string, string> */
    public static function all(): array
    {
        $settings = get_option(self::OPTION, array());
        return is_array($settings) ? array_map('strval', $settings) : array();
    }

    /** @return array<string, array{label:string,type:string,description:string}> */
    private function fields(): array
    {
        return array(
            'environment' => array('label' => __('Среда', 'ranau-cdek-delivery-for-woocommerce'), 'type' => 'select', 'description' => __('Production использует api.cdek.ru, Test — api.edu.cdek.ru.', 'ranau-cdek-delivery-for-woocommerce')),
            'client_id' => array('label' => __('Account / client ID', 'ranau-cdek-delivery-for-woocommerce'), 'type' => 'text', 'description' => __('Идентификатор интеграции из личного кабинета CDEK.', 'ranau-cdek-delivery-for-woocommerce')),
            'client_secret' => array('label' => __('Secure password / client secret', 'ranau-cdek-delivery-for-woocommerce'), 'type' => 'password', 'description' => __('Секрет хранится только в настройках WordPress.', 'ranau-cdek-delivery-for-woocommerce')),
            'origin_city_code' => array('label' => __('Код города отправления CDEK', 'ranau-cdek-delivery-for-woocommerce'), 'type' => 'number', 'description' => __('Числовой код города из справочника CDEK.', 'ranau-cdek-delivery-for-woocommerce')),
            'origin_postal_code' => array('label' => __('Индекс отправления', 'ranau-cdek-delivery-for-woocommerce'), 'type' => 'text', 'description' => __('Необязательно; используется, если код города не указан.', 'ranau-cdek-delivery-for-woocommerce')),
            'shipment_point' => array('label' => __('Код ПВЗ отправления', 'ranau-cdek-delivery-for-woocommerce'), 'type' => 'text', 'description' => __('Необязательно. Если товар передается в CDEK через ПВЗ, укажите его код.', 'ranau-cdek-delivery-for-woocommerce')),
            'allowed_tariff_codes' => array('label' => __('Разрешенные тарифы', 'ranau-cdek-delivery-for-woocommerce'), 'type' => 'text', 'description' => __('Коды через запятую. Пусто — самый выгодный доступный тариф с доставкой до ПВЗ.', 'ranau-cdek-delivery-for-woocommerce')),
        );
    }
}
