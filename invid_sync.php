<?php
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

define('INVID_AUTH_URL',   'https://www.invidcomputers.com/api/v1/auth.php');
define('INVID_ART_URL',    'https://www.invidcomputers.com/api/v1/articulo.php');
define('INVID_TOKEN_FILE', __DIR__ . '/invid_token.json');
define('INVID_CACHE_FILE', __DIR__ . '/invid_cache.json');
define('INVID_USER',       'ristoffa');
define('INVID_PASS',       'Ristoff_A');

function arr($a, $k, $def) {
    return isset($a[$k]) ? $a[$k] : $def;
}

function invid_get_token() {
    if (file_exists(INVID_TOKEN_FILE)) {
        $t = json_decode(file_get_contents(INVID_TOKEN_FILE), true);
        if (!empty($t['access_token']) && isset($t['expires_at']) && time() < $t['expires_at'] - 300) {
            return $t['access_token'];
        }
    }
    return invid_login();
}

function invid_login() {
    $ch = curl_init(INVID_AUTH_URL);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => array('Content-Type: application/json', 'Accept: application/json'),
        CURLOPT_POSTFIELDS     => json_encode(array('username' => INVID_USER, 'password' => INVID_PASS)),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 15,
    ));
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) { echo json_encode(array('ok' => false, 'error' => 'cURL: ' . $err)); exit; }

    $data = json_decode($raw, true);
    if ($code !== 201 || empty($data['access_token'])) {
        echo json_encode(array('ok' => false, 'error' => 'Auth fallida', 'code' => $code, 'detail' => $data));
        exit;
    }

    $exp = isset($data['expiration_time']) ? (int)$data['expiration_time'] : 86400;
    $data['expires_at'] = time() + $exp;
    file_put_contents(INVID_TOKEN_FILE, json_encode($data));
    return $data['access_token'];
}

function invid_fetch_page($token, $url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => array(
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 30,
    ));
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err || $code !== 200) return null;
    return json_decode($raw, true);
}

function invid_fetch_all($token) {
    $todos   = array();
    $nextUrl = INVID_ART_URL;

    while ($nextUrl) {
        $data = invid_fetch_page($token, $nextUrl);
        if (!$data || !isset($data['data']) || !is_array($data['data'])) break;

        foreach ($data['data'] as $a) {
            $precio_raw = arr($a, 'PRICE', '0');
            $precio = (float) preg_replace('/[^0-9.]/', '', str_replace(',', '.', $precio_raw));
            $todos[] = array(
                'codigo'      => arr($a, 'ID',           ''),
                'descripcion' => arr($a, 'TITLE',        ''),
                'precio'      => $precio,
                'stock'       => arr($a, 'STOCK_STATUS', 0),
                'imagen'      => arr($a, 'IMAGE_URL',    ''),
                'marca'       => arr($a, 'BRAND',        ''),
                'categoria'   => arr($a, 'CATEGORY',     ''),
                'part_number' => arr($a, 'PART_NUMBER',  ''),
            );
        }

        $nextUrl = isset($data['next_page_url']) ? $data['next_page_url'] : null;
    }

    return $todos;
}

$action = isset($_GET['action']) ? $_GET['action'] : 'sync';

if ($action === 'sync') {
set_time_limit(300);	
    $token     = invid_get_token();
    $articulos = invid_fetch_all($token);
    $cache = array(
        'ts'        => time(),
        'total'     => count($articulos),
        'articulos' => $articulos,
    );
    file_put_contents(INVID_CACHE_FILE, json_encode($cache));
    echo json_encode(array('ok' => true, 'total' => count($articulos), 'ts' => $cache['ts']));

} elseif ($action === 'get') {
    if (!file_exists(INVID_CACHE_FILE)) {
        echo json_encode(array('ok' => false, 'error' => 'Sin cache. Ejecuta sync primero.'));
        exit;
    }
    echo file_get_contents(INVID_CACHE_FILE);

} elseif ($action === 'token') {
    $token = invid_login();
    $t = json_decode(file_get_contents(INVID_TOKEN_FILE), true);
    echo json_encode(array(
        'ok'         => true,
        'username'   => isset($t['username'])   ? $t['username']   : '',
        'expires_at' => isset($t['expires_at']) ? date('Y-m-d H:i:s', $t['expires_at']) : '',
    ));

} elseif ($action === 'test') {
    $token = invid_get_token();
    $ch = curl_init(INVID_ART_URL);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => array('Authorization: Bearer ' . $token, 'Accept: application/json'),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 30,
    ));
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = json_decode($raw, true);
    $keys = is_array($decoded) ? array_keys($decoded) : array();
    $first = (isset($decoded['data']) && is_array($decoded['data'])) ? array_slice($decoded['data'], 0, 1) : $decoded;
    echo json_encode(array('code' => $code, 'keys' => $keys, 'first' => $first));
} else {
    echo json_encode(array('ok' => false, 'error' => 'Accion invalida'));
}

