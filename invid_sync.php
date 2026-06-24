<?php
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

define('INVID_BASE',       'https://invidcomputers.com/api');
define('INVID_TOKEN_FILE', __DIR__ . '/invid_token.json');
define('INVID_CACHE_FILE', __DIR__ . '/invid_cache.json');
define('INVID_USER',       '2026163237');
define('INVID_PASS',       'Ristoff123');

// ─── Auth ────────────────────────────────────────────────────────────────────

function invid_get_token() {
    if (file_exists(INVID_TOKEN_FILE)) {
        $t = json_decode(file_get_contents(INVID_TOKEN_FILE), true);
        // token válido si faltan más de 5 min para expirar
        if (!empty($t['access_token']) && isset($t['expires_at']) && time() < $t['expires_at'] - 300) {
            return $t['access_token'];
        }
    }
    return invid_login();
}

function invid_login() {
    $ch = curl_init(INVID_BASE . '/v1/auth.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_POSTFIELDS     => json_encode(['username' => INVID_USER, 'password' => INVID_PASS]),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) die(json_encode(['ok' => false, 'error' => 'cURL: ' . $err]));

    $data = json_decode($raw, true);
    if ($code !== 201 || empty($data['access_token'])) {
        die(json_encode(['ok' => false, 'error' => 'Auth fallida', 'detail' => $data]));
    }

    $data['expires_at'] = time() + (int)($data['expiration_time'] ?? 86400);
    file_put_contents(INVID_TOKEN_FILE, json_encode($data));
    return $data['access_token'];
}

// ─── Artículos ───────────────────────────────────────────────────────────────

function invid_fetch_all($token) {
    $page     = 1;
    $todos    = [];
    $pageSize = 100; // máximo según la API

    do {
        $url = INVID_BASE . '/v1/articulo.php?page=' . $page . '&limit=' . $pageSize;
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err || $code !== 200) break;

        $data  = json_decode($raw, true);
        $items = $data['articulos'] ?? $data['data'] ?? (is_array($data) ? $data : []);
        if (empty($items)) break;

        // Normalizar cada artículo al formato del sistema
        foreach ($items as $a) {
            $todos[] = [
                'codigo'      => $a['CODIART']   ?? $a['codigo']      ?? '',
                'descripcion' => $a['DESCRIP']   ?? $a['descripcion'] ?? '',
                'precio'      => (float)($a['PRECIO'] ?? $a['precio'] ?? 0),
                'stock'       => (int)($a['STOCK'] ?? $a['stock'] ?? 0),
                'imagen'      => $a['IMAGE_URL']  ?? $a['imagen']      ?? '',
                'marca'       => $a['MARCA']      ?? $a['marca']       ?? '',
                'categoria'   => $a['CATEGORIA']  ?? $a['categoria']   ?? '',
            ];
        }

        $page++;
        // Si devolvió menos de pageSize ya no hay más páginas
    } while (count($items) >= $pageSize);

    return $todos;
}

// ─── Acciones ────────────────────────────────────────────────────────────────

$action = $_GET['action'] ?? 'sync';

if ($action === 'sync') {
    $token    = invid_get_token();
    $articulos = invid_fetch_all($token);

    $cache = [
        'ts'        => time(),
        'total'     => count($articulos),
        'articulos' => $articulos,
    ];
    file_put_contents(INVID_CACHE_FILE, json_encode($cache, JSON_UNESCAPED_UNICODE));
    echo json_encode(['ok' => true, 'total' => count($articulos), 'ts' => $cache['ts']]);

} elseif ($action === 'get') {
    if (!file_exists(INVID_CACHE_FILE)) {
        echo json_encode(['ok' => false, 'error' => 'Sin caché. Ejecuta sync primero.']);
        exit;
    }
    echo file_get_contents(INVID_CACHE_FILE);

} elseif ($action === 'token') {
    // Debug: fuerza re-login y muestra info del token (sin exponer el valor)
    $token = invid_login();
    $t = json_decode(file_get_contents(INVID_TOKEN_FILE), true);
    echo json_encode([
        'ok'         => true,
        'username'   => $t['username']   ?? '',
        'expires_at' => date('Y-m-d H:i:s', $t['expires_at'] ?? 0),
    ]);

} else {
    echo json_encode(['ok' => false, 'error' => 'Acción inválida. Usa: sync | get | token']);
}
