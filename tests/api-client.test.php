<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);

$GLOBALS['ranau_cdek_test_transients'] = array();
$GLOBALS['ranau_cdek_test_requests'] = array();

function __($value, $domain = '') { return $value; }
function absint($value) { return abs((int) $value); }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function wp_json_encode($value) { return json_encode($value); }
function get_option($name, $default = array()) {
    if ($name !== 'ranau_cdek_delivery_settings') { return $default; }
    return array(
        'environment' => 'production',
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'origin_city_code' => '44',
        'origin_postal_code' => '',
        'shipment_point' => '',
        'allowed_tariff_codes' => '136',
    );
}
function get_transient($key) { return $GLOBALS['ranau_cdek_test_transients'][$key] ?? false; }
function set_transient($key, $value, $ttl) { $GLOBALS['ranau_cdek_test_transients'][$key] = $value; return true; }
function add_query_arg($args, $url) { return $url . (strpos($url, '?') === false ? '?' : '&') . http_build_query($args); }
function is_wp_error($value) { return false; }
function wp_remote_retrieve_response_code($response) { return $response['response']['code']; }
function wp_remote_retrieve_body($response) { return $response['body']; }
function wp_safe_remote_request($url, $args) {
    $GLOBALS['ranau_cdek_test_requests'][] = array('url' => $url, 'args' => $args);
    if (strpos($url, '/oauth/token') !== false) {
        return array('response' => array('code' => 200), 'body' => json_encode(array('access_token' => 'opaque-token', 'expires_in' => 3600)));
    }
    if (strpos($url, '/location/cities') !== false) {
        return array('response' => array('code' => 200), 'body' => json_encode(array(array('code' => 44, 'city' => 'Москва'))));
    }
    if (strpos($url, '/deliverypoints') !== false) {
        return array('response' => array('code' => 200), 'body' => json_encode(array(array(
            'code' => 'MSK1',
            'name' => 'Test point',
            'type' => 'PVZ',
            'location' => array('city_code' => 44, 'city' => 'Москва', 'address' => 'Test address'),
        ))));
    }
    if (strpos($url, '/calculator/tarifflist') !== false) {
        return array('response' => array('code' => 200), 'body' => json_encode(array('tariff_codes' => array(array(
            'tariff_code' => 136,
            'tariff_name' => 'Warehouse to warehouse',
            'delivery_mode' => 4,
            'delivery_sum' => 320.5,
            'period_min' => 2,
            'period_max' => 3,
        )))));
    }
    throw new RuntimeException('Unexpected URL: ' . $url);
}

require_once dirname(__DIR__) . '/includes/class-settings.php';
require_once dirname(__DIR__) . '/includes/class-api-client.php';

$client = new \Ranau\CdekDelivery\ApiClient();
$points = $client->points('Москва');
assert(count($points) === 1);
assert($points[0]['code'] === 'MSK1');
$point = $client->point('MSK1');
$quote = $client->quote(array('ok' => true, 'weight' => 1000, 'length' => 20, 'width' => 15, 'height' => 10), $point);
assert($quote['ok'] === true);
assert($quote['tariff_code'] === 136);
assert($quote['price'] === 320.5);

$requests = $GLOBALS['ranau_cdek_test_requests'];
$paths = array_map(static function (array $request): string {
    return (string) parse_url($request['url'], PHP_URL_PATH);
}, $requests);
assert(in_array('/v2/oauth/token', $paths, true));
assert(in_array('/v2/location/cities', $paths, true));
assert(in_array('/v2/deliverypoints', $paths, true));
assert(in_array('/v2/calculator/tarifflist', $paths, true));
foreach ($paths as $path) {
    assert(!preg_match('~/(orders|print|intakes|webhooks|registries)(?:/|$)~', $path));
}

$calculator = null;
foreach ($requests as $request) {
    if (strpos($request['url'], '/calculator/tarifflist') !== false) {
        $calculator = json_decode((string) $request['args']['body'], true);
    }
}
assert(is_array($calculator));
assert($calculator['from_location']['code'] === 44);
assert($calculator['to_location']['code'] === 44);
assert($calculator['delivery_point'] === 'MSK1');
assert($calculator['packages'][0] === array('weight' => 1000, 'length' => 20, 'width' => 15, 'height' => 10));

echo "Ranau CDEK API client fixture checks passed.\n";
