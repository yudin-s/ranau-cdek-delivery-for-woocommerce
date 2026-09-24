<?php

declare(strict_types=1);

namespace Ranau\CdekDelivery;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use DomainException;
use Ranau\CdekDelivery\Internal\DeliveryState\ProviderStateStore;
use RuntimeException;

defined('ABSPATH') || exit;

final class Plugin
{
    private const METHOD_ID = 'ranau_cdek_pickup';
    private const STORE_API_NAMESPACE = 'ranau-cdek-delivery-for-woocommerce';
    private const SESSION_POINT = 'ranau_cdek_pickup_point';
    private const SESSION_QUOTE = 'ranau_cdek_pickup_quote';

    private static ?Plugin $instance = null;
    private bool $store_api_data_registered = false;
    private bool $store_api_update_registered = false;

    public static function instance(): Plugin
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init(): void
    {
        add_action('woocommerce_shipping_init', array($this, 'load_shipping_method'));
        add_filter('woocommerce_shipping_methods', array($this, 'register_shipping_method'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('woocommerce_after_shipping_rate', array($this, 'render_classic_selector'), 10, 2);
        add_action('woocommerce_after_checkout_validation', array($this, 'validate_classic_checkout'), 15, 2);
        add_action('woocommerce_checkout_create_order', array($this, 'save_classic_order'), 15, 2);
        add_action('woocommerce_blocks_loaded', array($this, 'register_store_api_data'));
        add_action('woocommerce_blocks_loaded', array($this, 'register_store_api_update'));
        add_action('woocommerce_store_api_checkout_update_order_from_request', array($this, 'validate_blocks_checkout'), 15, 2);
        add_action('woocommerce_store_api_checkout_update_order_from_request', array($this, 'save_blocks_order'), 20, 2);

        if (function_exists('woocommerce_store_api_register_endpoint_data')) {
            $this->register_store_api_data();
        }
        if (function_exists('woocommerce_store_api_register_update_callback')) {
            $this->register_store_api_update();
        }
    }

    public function load_shipping_method(): void
    {
        require_once RANAU_CDEK_DELIVERY_DIR . 'includes/class-shipping-method.php';
    }

    public function register_shipping_method(array $methods): array
    {
        $methods[self::METHOD_ID] = ShippingMethod::class;
        return $methods;
    }

    public function register_rest_routes(): void
    {
        register_rest_route('ranau-cdek-delivery-for-woocommerce/v1', '/points', array(
            'methods' => \WP_REST_Server::READABLE,
            'callback' => array($this, 'get_points'),
            'permission_callback' => array($this, 'check_nonce'),
            'args' => array(
                'city' => array('type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field'),
                'postcode' => array('type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field'),
            ),
        ));
        register_rest_route('ranau-cdek-delivery-for-woocommerce/v1', '/selection', array(
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => array($this, 'update_selection'),
            'permission_callback' => array($this, 'check_nonce'),
        ));
        register_rest_route('ranau-cdek-delivery-for-woocommerce/v1', '/commit', array(
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => array($this, 'commit_rest_selection'),
            'permission_callback' => array($this, 'check_nonce'),
        ));
    }

    public function check_nonce(\WP_REST_Request $request): bool
    {
        $nonce = (string) $request->get_header('x_wp_nonce');
        return $nonce !== '' && wp_verify_nonce($nonce, 'wp_rest');
    }

    public function get_points(\WP_REST_Request $request): \WP_REST_Response
    {
        $city = sanitize_text_field((string) $request->get_param('city'));
        $postcode = sanitize_text_field((string) $request->get_param('postcode'));
        if ($city === '' && $postcode === '') {
            return new \WP_REST_Response(array('code' => 'destination_missing', 'message' => __('Укажите город или индекс.', 'ranau-cdek-delivery-for-woocommerce')), 400);
        }
        try {
            $items = (new ApiClient())->points($city, $postcode);
        } catch (RuntimeException $exception) {
            return new \WP_REST_Response(array(
                'code' => sanitize_key($exception->getMessage()),
                'message' => __('Не удалось загрузить ПВЗ CDEK. Проверьте настройки API и повторите попытку.', 'ranau-cdek-delivery-for-woocommerce'),
            ), 502);
        }
        $points = array_map(array($this, 'public_point'), $items);
        $points = array_values(array_filter($points, static fn (array $point): bool => $point !== array()));
        return rest_ensure_response(array('points' => $points));
    }

    public function update_selection(\WP_REST_Request $request): \WP_REST_Response
    {
        $this->ensure_cart_loaded();
        $data = $this->normalize_array($request->get_json_params());
        $code = sanitize_text_field((string) ($data['code'] ?? ''));
        if ($code === '') {
            $this->clear_selection();
            return rest_ensure_response(array('point' => array(), 'quote' => array(), 'commit' => array()));
        }
        try {
            $raw = (new ApiClient())->point($code);
            $point = $this->public_point($raw);
            if (!$point) {
                throw new RuntimeException('invalid_point');
            }
            $quote = (new ApiClient())->quote((new PackageResolver())->resolve_cart(), $raw);
        } catch (RuntimeException $exception) {
            return new \WP_REST_Response(array(
                'code' => sanitize_key($exception->getMessage()),
                'message' => __('Не удалось проверить ПВЗ или рассчитать доставку CDEK.', 'ranau-cdek-delivery-for-woocommerce'),
            ), 502);
        }
        if (empty($quote['ok'])) {
            return new \WP_REST_Response($quote, 422);
        }
        $point['_ranau_committed'] = false;
        $quote = $this->apply_free_shipping($quote);
        $this->set_session(self::SESSION_POINT, $point);
        $this->set_session(self::SESSION_QUOTE, $quote);
        $this->clear_shipping_cache();
        $this->persist_session();
        try {
            $commit = $this->begin_provider_state($point, $quote);
        } catch (DomainException $exception) {
            return new \WP_REST_Response(array(
                'code' => sanitize_key($exception->getMessage()),
                'message' => __('Сначала выберите способ доставки CDEK.', 'ranau-cdek-delivery-for-woocommerce'),
            ), 409);
        }
        return rest_ensure_response(array('point' => $point, 'quote' => $quote, 'commit' => $commit));
    }

    public function commit_rest_selection(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $committed = $this->commit_provider_state($this->normalize_array($request->get_json_params()));
        } catch (DomainException $exception) {
            return new \WP_REST_Response(array(
                'code' => sanitize_key($exception->getMessage()),
                'message' => __('Выбор CDEK устарел. Выберите ПВЗ ещё раз.', 'ranau-cdek-delivery-for-woocommerce'),
            ), 409);
        }
        return rest_ensure_response(array('committed' => $committed));
    }

    public function enqueue_assets(): void
    {
        if ((!function_exists('is_checkout') || !is_checkout()) && (!function_exists('is_cart') || !is_cart())) {
            return;
        }
        wp_enqueue_style('ranau-cdek-delivery-for-woocommerce', RANAU_CDEK_DELIVERY_URL . 'assets/css/pickup.css', array(), $this->asset_version('assets/css/pickup.css'));
        wp_enqueue_script('ranau-cdek-delivery-runtime', RANAU_CDEK_DELIVERY_URL . 'assets/js/delivery-runtime.js', array(), $this->asset_version('assets/js/delivery-runtime.js'), true);
        wp_enqueue_script('ranau-cdek-delivery-adapter', RANAU_CDEK_DELIVERY_URL . 'assets/js/delivery-adapter.js', array('ranau-cdek-delivery-runtime'), $this->asset_version('assets/js/delivery-adapter.js'), true);
        wp_enqueue_script('ranau-cdek-delivery-for-woocommerce', RANAU_CDEK_DELIVERY_URL . 'assets/js/pickup.js', array('ranau-cdek-delivery-adapter', 'wc-blocks-checkout'), $this->asset_version('assets/js/pickup.js'), true);
        wp_localize_script('ranau-cdek-delivery-for-woocommerce', 'RanauCdekDelivery', array(
            'restUrl' => esc_url_raw(rest_url('ranau-cdek-delivery-for-woocommerce/v1')),
            'commitUrl' => esc_url_raw(rest_url('ranau-cdek-delivery-for-woocommerce/v1/commit')),
            'restNonce' => wp_create_nonce('wp_rest'),
            'storeApiNamespace' => self::STORE_API_NAMESPACE,
            'selectedPoint' => $this->session_array(self::SESSION_POINT),
            'quote' => $this->current_quote(),
            'defaultCity' => 'Москва',
            'packageFingerprint' => $this->package_fingerprint(),
        ));
    }

    public function render_classic_selector($rate, int $index): void
    {
        if (!is_object($rate) || !method_exists($rate, 'get_method_id') || $rate->get_method_id() !== self::METHOD_ID) {
            return;
        }
        echo '<div class="ranau-cdek-selector" data-ranau-cdek-classic data-ranau-delivery-method="' . esc_attr(self::METHOD_ID) . '" data-ranau-delivery-complete="0">';
        echo '<div class="ranau-cdek-selector__head"><div><strong>' . esc_html__('ПУНКТ ВЫДАЧИ CDEK', 'ranau-cdek-delivery-for-woocommerce') . '</strong><span>' . esc_html__('Выберите ПВЗ или постамат из актуального справочника CDEK.', 'ranau-cdek-delivery-for-woocommerce') . '</span></div>';
        echo '<button type="button" class="button ranau-cdek-open">' . esc_html__('Выбрать ПВЗ', 'ranau-cdek-delivery-for-woocommerce') . '</button></div>';
        echo '<div class="ranau-cdek-selected" aria-live="polite">' . esc_html__('Пункт выдачи пока не выбран', 'ranau-cdek-delivery-for-woocommerce') . '</div>';
        echo '<button type="button" class="ranau-cdek-clear" hidden>' . esc_html__('Сбросить выбор', 'ranau-cdek-delivery-for-woocommerce') . '</button></div>';
    }

    public function register_store_api_data(): void
    {
        if ($this->store_api_data_registered || !function_exists('woocommerce_store_api_register_endpoint_data')) {
            return;
        }
        woocommerce_store_api_register_endpoint_data(array(
            'endpoint' => 'checkout',
            'namespace' => self::STORE_API_NAMESPACE,
            'schema_callback' => static function (): array {
                return array('point_code' => array(
                    'description' => __('Код выбранного ПВЗ CDEK.', 'ranau-cdek-delivery-for-woocommerce'),
                    'type' => 'string',
                    'context' => array('view', 'edit'),
                    'required' => false,
                ));
            },
        ));
        $this->store_api_data_registered = true;
    }

    public function register_store_api_update(): void
    {
        if ($this->store_api_update_registered || !function_exists('woocommerce_store_api_register_update_callback')) {
            return;
        }
        woocommerce_store_api_register_update_callback(array(
            'namespace' => self::STORE_API_NAMESPACE,
            'callback' => array($this, 'update_cart_from_store_api'),
        ));
        $this->store_api_update_registered = true;
    }

    public function update_cart_from_store_api($data): void
    {
        $data = $this->normalize_array($data);
        if (empty($data['commit_token'])) {
            $this->clear_selection();
            return;
        }
        try {
            $this->commit_provider_state($data);
        } catch (DomainException $exception) {
            $message = __('Выбор CDEK устарел. Выберите ПВЗ ещё раз.', 'ranau-cdek-delivery-for-woocommerce');
            if (class_exists(RouteException::class)) {
                throw new RouteException(esc_attr($exception->getMessage()), esc_html($message), 409);
            }
            throw new \RuntimeException(esc_html($message));
        }
    }

    public function validate_classic_checkout(array $data, \WP_Error $errors): void
    {
        if ($this->selected_method_id() === self::METHOD_ID && $this->selection_error() !== '') {
            $errors->add('ranau_cdek_delivery_incomplete', $this->selection_error());
        }
    }

    public function validate_blocks_checkout(\WC_Order $order, \WP_REST_Request $request): void
    {
        if ($this->is_totals_request($request) || $this->selected_method_id($order) !== self::METHOD_ID) {
            return;
        }
        $message = $this->selection_error($order);
        if ($message !== '') {
            if (class_exists(RouteException::class)) {
                throw new RouteException('ranau_cdek_delivery_incomplete', esc_html($message), 400);
            }
            throw new \RuntimeException(esc_html($message));
        }
    }

    public function save_classic_order(\WC_Order $order, array $data): void
    {
        $this->save_order_delivery($order);
    }

    public function save_blocks_order(\WC_Order $order, \WP_REST_Request $request): void
    {
        if (!$this->is_totals_request($request)) {
            $this->save_order_delivery($order);
        }
    }

    /** @param array<string, mixed> $raw
     *  @return array<string, mixed>
     */
    public function public_point(array $raw): array
    {
        $code = sanitize_text_field((string) ($raw['code'] ?? ''));
        $location = is_array($raw['location'] ?? null) ? $raw['location'] : array();
        if ($code === '' || empty($location['address'])) {
            return array();
        }
        return array(
            'code' => $code,
            'name' => sanitize_text_field((string) ($raw['name'] ?? '')),
            'type' => sanitize_key((string) ($raw['type'] ?? 'PVZ')),
            'address' => sanitize_text_field((string) ($location['address_full'] ?? $location['address'] ?? '')),
            'city' => sanitize_text_field((string) ($location['city'] ?? '')),
            'city_code' => absint($location['city_code'] ?? 0),
            'postal_code' => sanitize_text_field((string) ($location['postal_code'] ?? '')),
            'longitude' => (float) ($location['longitude'] ?? 0),
            'latitude' => (float) ($location['latitude'] ?? 0),
            'work_time' => sanitize_text_field((string) ($raw['work_time'] ?? '')),
            'phones' => array_values(array_filter(array_map(static function ($phone): string {
                return is_array($phone) ? sanitize_text_field((string) ($phone['number'] ?? '')) : '';
            }, (array) ($raw['phones'] ?? array())))),
        );
    }

    private function save_order_delivery(\WC_Order $order): void
    {
        if ($this->selected_method_id($order) !== self::METHOD_ID || $this->selection_error($order) !== '') {
            return;
        }
        $point = $this->session_array(self::SESSION_POINT);
        $quote = $this->current_quote();
        $order->update_meta_data('_ranau_delivery_provider', 'cdek');
        $order->update_meta_data('_ranau_delivery_fulfillment_owner', 'external');
        $order->update_meta_data('_ranau_delivery_mode', 'pickup');
        $order->update_meta_data('_ranau_cdek_no_fulfillment', 'yes');
        $order->update_meta_data('_ranau_cdek_point_code', sanitize_text_field((string) ($point['code'] ?? '')));
        $order->update_meta_data('_ranau_cdek_point_type', sanitize_key((string) ($point['type'] ?? '')));
        $order->update_meta_data('_ranau_cdek_point_name', sanitize_text_field((string) ($point['name'] ?? '')));
        $order->update_meta_data('_ranau_cdek_point_address', sanitize_text_field((string) ($point['address'] ?? '')));
        $order->update_meta_data('_ranau_cdek_point_latitude', (float) ($point['latitude'] ?? 0));
        $order->update_meta_data('_ranau_cdek_point_longitude', (float) ($point['longitude'] ?? 0));
        $order->update_meta_data('_ranau_cdek_tariff_code', absint($quote['tariff_code'] ?? 0));
        $order->update_meta_data('_ranau_cdek_tariff_name', sanitize_text_field((string) ($quote['tariff_name'] ?? '')));
        $order->update_meta_data('_ranau_cdek_carrier_price', (float) ($quote['carrier_price'] ?? $quote['price'] ?? 0));
        $order->update_meta_data('_ranau_cdek_customer_price', (float) ($quote['price'] ?? 0));
        $order->update_meta_data('_ranau_cdek_free_shipping', !empty($quote['free_shipping']) ? 'yes' : 'no');
        $order->update_meta_data('_ranau_cdek_period_min', absint($quote['period_min'] ?? 0));
        $order->update_meta_data('_ranau_cdek_period_max', absint($quote['period_max'] ?? 0));
        $order->set_shipping_address_1(sanitize_text_field((string) ($point['address'] ?? '')));
        $order->set_shipping_city(sanitize_text_field((string) ($point['city'] ?? '')));
        $order->set_shipping_postcode(sanitize_text_field((string) ($point['postal_code'] ?? '')));
        $order->set_shipping_country('RU');
    }

    private function selection_error(?\WC_Order $order = null): string
    {
        if ($order && !$this->selected_shipping_item_is_ready($order)) {
            return __('Выберите ПВЗ CDEK и дождитесь расчета стоимости.', 'ranau-cdek-delivery-for-woocommerce');
        }
        $point = $this->session_array(self::SESSION_POINT);
        if (empty($point['code']) || empty($point['_ranau_committed'])) {
            return __('Выберите пункт выдачи CDEK.', 'ranau-cdek-delivery-for-woocommerce');
        }
        $quote = $this->current_quote();
        return !empty($quote['ok']) ? '' : (string) ($quote['message'] ?? __('Стоимость доставки CDEK недоступна.', 'ranau-cdek-delivery-for-woocommerce'));
    }

    /** @param array<string, mixed> $point
     *  @param array<string, mixed> $quote
     *  @return array<string, mixed>
     */
    private function begin_provider_state(array $point, array $quote): array
    {
        $rate_id = $this->selected_full_rate_id();
        $fingerprint = $this->package_fingerprint();
        return $this->provider_state_store($rate_id)->beginPending($this->context_key($point, $fingerprint), $fingerprint, $point, $quote);
    }

    /** @param array<string, mixed> $data
     *  @return array<string, mixed>
     */
    private function commit_provider_state(array $data): array
    {
        $rate_id = sanitize_text_field((string) ($data['rate_id'] ?? ''));
        if (explode(':', $rate_id)[0] !== self::METHOD_ID) {
            throw new DomainException('invalid_rate');
        }
        $point = $this->session_array(self::SESSION_POINT);
        $fingerprint = $this->package_fingerprint();
        $committed = $this->provider_state_store($rate_id)->commit($data, $this->selected_full_rate_id(), $this->context_key($point, $fingerprint), $fingerprint);
        $selection = (array) ($committed['selection'] ?? array());
        $selection['_ranau_committed'] = true;
        $this->set_session(self::SESSION_POINT, $selection);
        $this->set_session(self::SESSION_QUOTE, (array) ($committed['quote'] ?? array()));
        $this->clear_shipping_cache();
        $this->persist_session();
        return $committed;
    }

    private function provider_state_store(string $rate_id): ProviderStateStore
    {
        if ($rate_id === '') {
            throw new DomainException('missing_rate_id');
        }
        $key = 'ranau_cdek_delivery_state_' . md5($rate_id);
        return new ProviderStateStore(
            $rate_id,
            function () use ($key): array { return $this->session_array($key); },
            function (array $value) use ($key): void { $this->set_session($key, $value); $this->persist_session(); },
            function () use ($key): void {
                if (function_exists('WC') && WC()->session) {
                    WC()->session->__unset($key);
                    $this->persist_session();
                }
            }
        );
    }

    private function selected_full_rate_id(): string
    {
        if (!function_exists('WC') || !WC()->session) {
            return '';
        }
        foreach ((array) WC()->session->get('chosen_shipping_methods', array()) as $rate_id) {
            $rate_id = sanitize_text_field((string) $rate_id);
            if (explode(':', $rate_id)[0] === self::METHOD_ID) {
                return $rate_id;
            }
        }
        return '';
    }

    private function selected_method_id(?\WC_Order $order = null): string
    {
        if ($order) {
            foreach ((array) $order->get_items('shipping') as $item) {
                if (is_object($item) && method_exists($item, 'get_method_id')) {
                    $method = (string) $item->get_method_id();
                    if ($method === self::METHOD_ID || strpos($method, self::METHOD_ID . ':') === 0) {
                        return self::METHOD_ID;
                    }
                }
            }
        }
        return $this->selected_full_rate_id() !== '' ? self::METHOD_ID : '';
    }

    private function selected_shipping_item_is_ready(\WC_Order $order): bool
    {
        foreach ((array) $order->get_items('shipping') as $item) {
            if (is_object($item) && method_exists($item, 'get_method_id') && (string) $item->get_method_id() === self::METHOD_ID) {
                return method_exists($item, 'get_meta') && (string) $item->get_meta('ranau_cdek_pickup_ready') === 'yes';
            }
        }
        return false;
    }

    /** @param array<string, mixed> $point */
    private function context_key(array $point, string $fingerprint): string
    {
        return implode('|', array_map(static function ($value): string {
            return strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $value)));
        }, array('RU', '', (string) ($point['city'] ?? ''), (string) ($point['postal_code'] ?? ''), $fingerprint)));
    }

    private function package_fingerprint(): string
    {
        return hash('sha256', (string) wp_json_encode((new PackageResolver())->resolve_cart()));
    }

    /** @return array<string, mixed> */
    private function current_quote(): array
    {
        return $this->apply_free_shipping($this->session_array(self::SESSION_QUOTE));
    }

    /** @param array<string, mixed> $quote
     *  @return array<string, mixed>
     */
    private function apply_free_shipping(array $quote): array
    {
        $policy = new FreeShippingPolicy(self::METHOD_ID);
        $threshold = $policy->threshold();
        if (!$policy->is_eligible(array(), $threshold)) {
            return $quote;
        }
        $carrier_price = !empty($quote['ok']) && isset($quote['price']) ? (float) $quote['price'] : null;
        $quote['ok'] = true;
        $quote['price'] = 0.0;
        $quote['free_shipping'] = true;
        $quote['free_shipping_threshold'] = $threshold;
        $quote['subtotal_before_discounts'] = $policy->subtotal_before_discounts();
        $quote['carrier_price'] = $carrier_price;
        return $quote;
    }

    private function clear_selection(): void
    {
        $this->set_session(self::SESSION_POINT, array());
        $this->set_session(self::SESSION_QUOTE, array());
        $rate_id = $this->selected_full_rate_id();
        if ($rate_id !== '') {
            $this->provider_state_store($rate_id)->invalidate();
        }
        $this->clear_shipping_cache();
        $this->persist_session();
    }

    private function session_array(string $key): array
    {
        if (!function_exists('WC') || !WC()->session) {
            return array();
        }
        return $this->normalize_array(WC()->session->get($key, array()));
    }

    private function normalize_array($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value)) {
            return json_decode((string) wp_json_encode($value), true) ?: array();
        }
        return array();
    }

    private function set_session(string $key, array $value): void
    {
        if (function_exists('WC') && WC()->session) {
            WC()->session->set($key, $value);
        }
    }

    private function clear_shipping_cache(): void
    {
        if (!function_exists('WC') || !WC()->session) {
            return;
        }
        $packages = WC()->cart ? WC()->cart->get_shipping_packages() : array();
        foreach (array_keys((array) $packages) as $index) {
            WC()->session->__unset('shipping_for_package_' . $index);
        }
    }

    private function ensure_cart_loaded(): void
    {
        if (!function_exists('WC')) {
            return;
        }
        if (!WC()->session && method_exists(WC(), 'initialize_session')) {
            WC()->initialize_session();
        }
        if (!WC()->cart && method_exists(WC(), 'initialize_cart')) {
            WC()->initialize_cart();
        }
    }

    private function persist_session(): void
    {
        if (function_exists('WC') && WC()->session && method_exists(WC()->session, 'save_data')) {
            WC()->session->save_data();
        }
    }

    private function is_totals_request(\WP_REST_Request $request): bool
    {
        return in_array($request->get_param('__experimental_calc_totals'), array(true, 'true', 1, '1'), true);
    }

    private function asset_version(string $relative_path): string
    {
        $path = RANAU_CDEK_DELIVERY_DIR . ltrim($relative_path, '/');
        clearstatcache(true, $path);
        $mtime = is_readable($path) ? filemtime($path) : false;
        return $mtime ? (string) $mtime : RANAU_CDEK_DELIVERY_VERSION;
    }
}
