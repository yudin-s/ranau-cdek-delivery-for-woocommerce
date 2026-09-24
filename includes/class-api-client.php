<?php

declare(strict_types=1);

namespace Ranau\CdekDelivery;

use RuntimeException;

defined('ABSPATH') || exit;

final class ApiClient
{
    private const ALLOWED_ENDPOINTS = array(
        'POST /oauth/token',
        'GET /deliverypoints',
        'GET /location/cities',
        'POST /calculator/tarifflist',
    );

    /** @return array<int, array<string, mixed>> */
    public function points(string $city, string $postcode = ''): array
    {
        $city_code = $this->resolve_city_code($city, $postcode);
        if ($city_code <= 0) {
            throw new RuntimeException('city_not_found');
        }

        $cache_key = 'ranau_cdek_points_' . $city_code;
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $response = $this->request('GET', '/deliverypoints', array(
            'city_code' => $city_code,
            'type' => 'ALL',
            'is_handout' => 'true',
        ));

        $points = is_array($response) ? array_values(array_filter($response, 'is_array')) : array();
        set_transient($cache_key, $points, HOUR_IN_SECONDS);
        return $points;
    }

    /** @return array<string, mixed> */
    public function point(string $code): array
    {
        $code = trim($code);
        $cache_key = 'ranau_cdek_point_' . md5($code);
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }
        $response = $this->request('GET', '/deliverypoints', array('code' => $code));
        if (!is_array($response) || !isset($response[0]) || !is_array($response[0])) {
            throw new RuntimeException('point_not_found');
        }

        set_transient($cache_key, $response[0], HOUR_IN_SECONDS);
        return $response[0];
    }

    /** @param array<string, mixed> $package
     *  @param array<string, mixed> $point
     *  @return array<string, mixed>
     */
    public function quote(array $package, array $point): array
    {
        if (empty($package['ok'])) {
            return $package;
        }
        $settings = Settings::all();
        $origin_code = absint($settings['origin_city_code'] ?? 0);
        $origin_postcode = sanitize_text_field((string) ($settings['origin_postal_code'] ?? ''));
        if ($origin_code <= 0 && $origin_postcode === '') {
            return $this->error('origin_missing', __('Укажите код города или индекс отправления в настройках CDEK.', 'ranau-cdek-delivery-for-woocommerce'));
        }

        $location = is_array($point['location'] ?? null) ? $point['location'] : array();
        $destination_code = absint($location['city_code'] ?? 0);
        if ($destination_code <= 0) {
            return $this->error('destination_missing', __('CDEK не вернул город выбранного ПВЗ.', 'ranau-cdek-delivery-for-woocommerce'));
        }

        $from = $origin_code > 0 ? array('code' => $origin_code) : array('postal_code' => $origin_postcode);
        $body = array(
            'type' => 1,
            'currency' => 1,
            'lang' => 'rus',
            'from_location' => $from,
            'to_location' => array('code' => $destination_code),
            'delivery_point' => sanitize_text_field((string) ($point['code'] ?? '')),
            'packages' => array(array(
                'weight' => absint($package['weight'] ?? 0),
                'length' => absint($package['length'] ?? 0),
                'width' => absint($package['width'] ?? 0),
                'height' => absint($package['height'] ?? 0),
            )),
        );
        if (!empty($settings['shipment_point'])) {
            $body['shipment_point'] = sanitize_text_field((string) $settings['shipment_point']);
        }

        try {
            $response = $this->request('POST', '/calculator/tarifflist', $body);
        } catch (RuntimeException $exception) {
            return $this->error('quote_failed', __('CDEK не смог рассчитать доставку. Проверьте настройки и повторите попытку.', 'ranau-cdek-delivery-for-woocommerce'));
        }
        $tariffs = isset($response['tariff_codes']) && is_array($response['tariff_codes']) ? $response['tariff_codes'] : array();
        $allowed = array_filter(array_map('absint', explode(',', (string) ($settings['allowed_tariff_codes'] ?? ''))));
        $eligible = array_values(array_filter($tariffs, static function ($tariff) use ($allowed): bool {
            if (!is_array($tariff)) {
                return false;
            }
            $mode = absint($tariff['delivery_mode'] ?? 0);
            $code = absint($tariff['tariff_code'] ?? 0);
            $price = (float) ($tariff['delivery_sum'] ?? $tariff['total_sum'] ?? 0);
            return in_array($mode, array(2, 4), true) && $code > 0 && $price >= 0 && (!$allowed || in_array($code, $allowed, true));
        }));
        usort($eligible, static function (array $left, array $right): int {
            $left_price = (float) ($left['delivery_sum'] ?? $left['total_sum'] ?? PHP_FLOAT_MAX);
            $right_price = (float) ($right['delivery_sum'] ?? $right['total_sum'] ?? PHP_FLOAT_MAX);
            return $left_price <=> $right_price;
        });

        if (!$eligible) {
            return $this->error('tariff_unavailable', __('Для выбранного ПВЗ нет доступного тарифа CDEK.', 'ranau-cdek-delivery-for-woocommerce'));
        }

        $tariff = $eligible[0];
        return array(
            'ok' => true,
            'price' => (float) ($tariff['delivery_sum'] ?? $tariff['total_sum'] ?? 0),
            'tariff_code' => absint($tariff['tariff_code'] ?? 0),
            'tariff_name' => sanitize_text_field((string) ($tariff['tariff_name'] ?? '')),
            'delivery_mode' => absint($tariff['delivery_mode'] ?? 0),
            'period_min' => absint($tariff['period_min'] ?? 0),
            'period_max' => absint($tariff['period_max'] ?? 0),
            'carrier' => 'cdek',
        );
    }

    private function resolve_city_code(string $city, string $postcode): int
    {
        $cache_key = 'ranau_cdek_city_' . md5($this->lower($city) . '|' . $postcode);
        $cached = get_transient($cache_key);
        if (is_numeric($cached)) {
            return (int) $cached;
        }
        $query = array('country_codes' => 'RU', 'size' => 20);
        if ($postcode !== '') {
            $query['postal_code'] = sanitize_text_field($postcode);
        } else {
            $query['city'] = sanitize_text_field($city);
        }
        $response = $this->request('GET', '/location/cities', $query);
        $items = is_array($response) ? $response : array();
        if (!$items) {
            return 0;
        }
        $needle = $this->lower($city);
        foreach ($items as $item) {
            if (is_array($item) && $needle !== '' && $this->lower((string) ($item['city'] ?? '')) === $needle) {
                $code = absint($item['code'] ?? 0);
                if ($code > 0) {
                    set_transient($cache_key, $code, DAY_IN_SECONDS);
                }
                return $code;
            }
        }
        $code = is_array($items[0] ?? null) ? absint($items[0]['code'] ?? 0) : 0;
        if ($code > 0) {
            set_transient($cache_key, $code, DAY_IN_SECONDS);
        }
        return $code;
    }

    /** @param array<string, mixed> $data
     *  @return array<string, mixed>|array<int, array<string, mixed>>
     */
    private function request(string $method, string $endpoint, array $data)
    {
        $method = strtoupper($method);
        if (!in_array($method . ' ' . $endpoint, self::ALLOWED_ENDPOINTS, true)) {
            throw new RuntimeException('endpoint_not_allowed');
        }
        $token = $endpoint === '/oauth/token' ? '' : $this->token();
        $url = $this->base_url() . $endpoint;
        $args = array('method' => $method, 'timeout' => 20, 'redirection' => 0, 'headers' => array('Accept' => 'application/json'));
        if ($token !== '') {
            $args['headers']['Authorization'] = 'Bearer ' . $token;
        }
        if ($method === 'GET') {
            $url = add_query_arg($data, $url);
        } elseif ($endpoint === '/oauth/token') {
            $args['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
            $args['body'] = $data;
        } else {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($data);
        }

        $response = wp_safe_remote_request($url, $args);
        if (is_wp_error($response)) {
            throw new RuntimeException('transport_error');
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || !is_array($decoded)) {
            throw new RuntimeException('api_error');
        }
        return $decoded;
    }

    private function token(): string
    {
        $settings = Settings::all();
        $client_id = trim((string) ($settings['client_id'] ?? ''));
        $secret = trim((string) ($settings['client_secret'] ?? ''));
        if ($client_id === '' || $secret === '') {
            throw new RuntimeException('credentials_missing');
        }
        $cache_key = 'ranau_cdek_token_' . md5($this->base_url() . '|' . $client_id);
        $cached = get_transient($cache_key);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        $response = $this->request('POST', '/oauth/token', array(
            'grant_type' => 'client_credentials',
            'client_id' => $client_id,
            'client_secret' => $secret,
        ));
        $token = sanitize_text_field((string) ($response['access_token'] ?? ''));
        if ($token === '') {
            throw new RuntimeException('token_missing');
        }
        $ttl = max(60, min(3500, absint($response['expires_in'] ?? 3600) - 60));
        set_transient($cache_key, $token, $ttl);
        return $token;
    }

    private function base_url(): string
    {
        $settings = Settings::all();
        return ($settings['environment'] ?? 'production') === 'test'
            ? 'https://api.edu.cdek.ru/v2'
            : 'https://api.cdek.ru/v2';
    }

    private function lower(string $value): string
    {
        $value = trim($value);
        return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
    }

    /** @return array<string, mixed> */
    private function error(string $code, string $message): array
    {
        return array('ok' => false, 'code' => $code, 'message' => $message);
    }
}
