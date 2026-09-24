#!/usr/bin/env node
'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const php = [
  'includes/class-api-client.php',
  'includes/class-plugin.php',
  'includes/class-settings.php',
].map(read).join('\n');
const js = [
  'assets/js/delivery-runtime.js',
  'assets/js/delivery-adapter.js',
  'assets/js/pickup.js',
].map(read).join('\n');

assert(!/glow[\s_-]?me|goflow/i.test(php + js), 'Legacy product identifiers must not ship.');
assert(php.includes('ProviderStateStore'), 'Server-owned provider state must be integrated.');
assert(js.includes('createDeliveryStateMachine'), 'The bundled provider state machine must be used.');
assert(js.includes('createCheckoutBridge'), 'Woo checkout refresh must use the shared bridge contract.');
assert(php.includes('ranau_cdek_delivery_settings'), 'Credentials must belong to this standalone plugin.');
assert(!/permission_callback'\s*=>\s*'__return_true'[\s\S]{0,80}CREATABLE/.test(php), 'Mutating REST routes must require a nonce.');

const client = read('includes/class-api-client.php');
const allowlistMatch = client.match(/private const ALLOWED_ENDPOINTS = array\(([\s\S]*?)\);/);
assert(allowlistMatch, 'CDEK API client must expose a closed endpoint allowlist.');
const allowlist = allowlistMatch[1];
['POST /oauth/token', 'GET /deliverypoints', 'GET /location/cities', 'POST /calculator/tarifflist'].forEach((entry) => {
  assert(allowlist.includes(entry), `Missing allowed endpoint: ${entry}`);
});
['/orders', '/print/', '/intakes', '/webhooks', '/registries'].forEach((endpoint) => {
  assert(!allowlist.includes(endpoint), `Fulfillment endpoint must not be allowed: ${endpoint}`);
});
assert(client.includes("throw new RuntimeException('endpoint_not_allowed')"), 'Unknown endpoints must fail closed.');

process.stdout.write('Ranau CDEK Delivery contract checks passed.\n');
