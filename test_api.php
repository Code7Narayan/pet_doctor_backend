#!/usr/bin/env php
<?php
// backend/test_api.php
// Quick sanity-check script — run from browser or CLI to verify each endpoint
// DELETE or restrict access to this file in production!
//
// Browser:  https://pet-doctor.aspryde.com/test_api.php
// CLI:      php test_api.php

$BASE = 'https://pet-doctor.aspryde.com/api';

function hit(string $method, string $url, array $body = [], string $token = ''): array {
    $ch = curl_init($url);
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($token) $headers[] = "Authorization: Bearer $token";

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CUSTOMREQUEST  => $method,
    ]);

    if ($body) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));

    $raw      = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    return [
        'code'    => $httpCode,
        'curl_err'=> $curlErr,
        'body'    => $raw,
        'json'    => json_decode($raw, true),
    ];
}

$results = [];
$token   = '';

// ── 1. DB connectivity (try login) ───────────────────────────
$r = hit('POST', "$BASE/auth/login", [
    'phone'    => '9623327931',
    'password' => 'test1234',  // CHANGE to your actual test password
    'role'     => 'owner',
]);
$results['1. Login (owner)'] = [
    'http' => $r['code'],
    'ok'   => ($r['json']['success'] ?? false) ? '✅' : '❌',
    'msg'  => $r['json']['message'] ?? $r['curl_err'] ?? 'unknown',
];
if ($r['json']['success'] ?? false) {
    $token = $r['json']['data']['access_token'];
}

// ── 2. Doctor login ───────────────────────────────────────────
$r = hit('POST', "$BASE/auth/login", [
    'phone'    => '7821005595',
    'password' => 'test1234',  // CHANGE
    'role'     => 'doctor',
]);
$results['2. Login (doctor)'] = [
    'http' => $r['code'],
    'ok'   => ($r['json']['success'] ?? false) ? '✅' : '❌',
    'msg'  => $r['json']['message'] ?? $r['curl_err'],
];
$doctorToken = $r['json']['data']['access_token'] ?? '';

// ── 3. Animals list ───────────────────────────────────────────
if ($token) {
    $r = hit('GET', "$BASE/animals?owner_id=1", [], $token);
    $results['3. Get animals'] = [
        'http' => $r['code'],
        'ok'   => ($r['json']['success'] ?? false) ? '✅' : '❌',
        'msg'  => $r['json']['message'] ?? '',
        'count'=> count($r['json']['data']['animals'] ?? []),
    ];
}

// ── 4. Nearby doctors ─────────────────────────────────────────
if ($token) {
    $r = hit('GET', "$BASE/doctors/nearby?lat=17.3013&lng=74.1877&radius=50", [], $token);
    $results['4. Nearby doctors'] = [
        'http' => $r['code'],
        'ok'   => ($r['json']['success'] ?? false) ? '✅' : '❌',
        'msg'  => $r['json']['message'] ?? '',
        'count'=> count($r['json']['data']['doctors'] ?? []),
    ];
}

// ── 5. Inventory (doctor) ─────────────────────────────────────
if ($doctorToken) {
    $r = hit('GET', "$BASE/inventory", [], $doctorToken);
    $results['5. Inventory list'] = [
        'http' => $r['code'],
        'ok'   => ($r['json']['success'] ?? false) ? '✅' : '❌',
        'msg'  => $r['json']['message'] ?? '',
    ];
}

// ── 6. Customers (doctor) ─────────────────────────────────────
if ($doctorToken) {
    $r = hit('GET', "$BASE/customers", [], $doctorToken);
    $results['6. Customer list'] = [
        'http' => $r['code'],
        'ok'   => ($r['json']['success'] ?? false) ? '✅' : '❌',
        'msg'  => $r['json']['message'] ?? '',
    ];
}

// ── Output ────────────────────────────────────────────────────
if (php_sapi_name() === 'cli') {
    foreach ($results as $test => $res) {
        echo str_pad($test, 35) . " {$res['ok']}  HTTP {$res['http']}  {$res['msg']}\n";
    }
} else {
    echo '<pre style="font-family:monospace;font-size:14px;padding:20px">';
    echo '<b>VetCare API Test Results</b>' . PHP_EOL . str_repeat('─', 70) . PHP_EOL;
    foreach ($results as $test => $res) {
        $extra = isset($res['count']) ? "  (found {$res['count']})" : '';
        echo str_pad($test, 35) . " {$res['ok']}  HTTP {$res['http']}  {$res['msg']}{$extra}" . PHP_EOL;
    }
    echo '</pre>';
}