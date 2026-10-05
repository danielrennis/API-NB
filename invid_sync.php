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
        CURLOPT_POSTFIELDS     => json_encode(array('username' => INVID_USER, 'password' => INVID_PASS, 'user_type' => 'item_subscriber')),
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
    for ($intento = 1; $intento <= 3; $intento++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_HTTPHEADER     => array(
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
            ),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 45,
        ));
        $raw   = curl_exec($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hsize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $err   = curl_error($ch);
        curl_close($ch);

        $headers = $raw ? substr($raw, 0, $hsize) : '';
        $body    = $raw ? substr($raw, $hsize) : '';

        if (!$err && $code === 200) {
            $parsed = json_decode($body, true);
            if ($parsed !== null) return $parsed;
        }

        // Si da 429 (Rate limit de Invid alcanzado, típicamente límite de 50 peticiones)
        if ($code === 429) {
            $espera = 60;
            if (preg_match('/Retry-After:\s*(\d+)/i', $headers, $m)) {
                $espera = (int)$m[1] + 3;
            } elseif (preg_match('/X-RateLimit-Reset:\s*(\d+)/i', $headers, $m)) {
                $espera = max(5, (int)$m[1] - time() + 3);
            }
            sleep($espera);
            continue;
        }

        // Si da 400 (offset excedido al final del catálogo), terminar
        if ($code === 400) {
            return null;
        }

        // Pausa breve antes de reintentar errores temporales
        usleep(500000);
    }
    return null;
}

function invid_fetch_all($token, $start_offset = 0) {
    $todos  = array();
    $offset = (int)$start_offset;

    while (true) {
        $url  = INVID_ART_URL . '?offset=' . $offset;
        $data = invid_fetch_page($token, $url);
        if (!$data || !isset($data['data']) || !is_array($data['data']) || count($data['data']) === 0) break;

        foreach ($data['data'] as $a) {
            $precio_raw = arr($a, 'PRICE', '0');
            $precio = (float) preg_replace('/[^0-9.]/', '', str_replace(',', '.', $precio_raw));
            $todos[] = array(
                'codigo'      => arr($a, 'ID',           ''),
                'descripcion' => arr($a, 'TITLE',        ''),
                'precio'      => $precio,
                'iva'         => arr($a, 'IVA_PERCENT',  0),
                'stock'       => arr($a, 'STOCK_STATUS', ''),
                'imagen'      => arr($a, 'IMAGE_URL',    ''),
                'marca'       => arr($a, 'BRAND',        ''),
                'categoria'   => arr($a, 'CATEGORY',     ''),
                'part_number' => arr($a, 'PART_NUMBER',  ''),
            );
        }

        if (count($data['data']) < 100) break;
        $offset += 100;
        // Pausa breve entre páginas para reducir probabilidad de rate limit
        usleep(150000);
    }

    return $todos;
}

define('INVID_STOCK_PRECIO_URL', 'https://www.invidcomputers.com/api/v1/stock-precio.php');

function invid_fetch_stock_precios($token) {
    $cambios = array();
    $offset = 0;
    while (true) {
        $url = INVID_STOCK_PRECIO_URL . '?offset=' . $offset;
        $ch  = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => array('Authorization: Bearer ' . $token, 'Accept: application/json'),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 30,
        ));
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200) break;
        $data = json_decode($raw, true);
        if (!isset($data['data']) || !is_array($data['data']) || count($data['data']) === 0) break;

        foreach ($data['data'] as $item) {
            $cod = arr($item, 'ID', '');
            if (!$cod) continue;
            $precio = (float) arr($item, 'PRICE', 0);
            $stock  = arr($item, 'STOCK_STATUS', '');
            $cambios[$cod] = array('precio' => $precio, 'stock' => $stock);
        }

        if (count($data['data']) < 500) break;
        $offset += 500;
        usleep(100000);
    }
    return $cambios;
}

$action = isset($_GET['action']) ? $_GET['action'] : 'sync';

if ($action === 'sync') {
    set_time_limit(600);
    $token = invid_get_token();

    // 1. Cargar caché previo
    $articulos_map = array();
    if (file_exists(INVID_CACHE_FILE)) {
        $prev = json_decode(file_get_contents(INVID_CACHE_FILE), true);
        if (isset($prev['articulos']) && is_array($prev['articulos'])) {
            foreach ($prev['articulos'] as $a) {
                if (!empty($a['codigo'])) {
                    $articulos_map[$a['codigo']] = $a;
                }
            }
        }
    }

    // 2. Si no hay catálogo en caché o tiene pocos artículos, descargar artículos completos
    if (count($articulos_map) < 3000) {
        $nuevos = invid_fetch_all($token);
        foreach ($nuevos as $a) {
            $articulos_map[$a['codigo']] = $a;
        }
    }

    // 3. Actualizar stock y precios en tiempo real vía stock-precio.php (ultra-rápido, sin rate limit)
    $sp = invid_fetch_stock_precios($token);
    if (!empty($sp)) {
        foreach ($sp as $cod => $info) {
            if (isset($articulos_map[$cod])) {
                $articulos_map[$cod]['precio'] = $info['precio'];
                $articulos_map[$cod]['stock']  = $info['stock'];
            }
        }
    }

    $articulos = array_values($articulos_map);
    if (count($articulos) === 0) {
        echo json_encode(array('ok' => false, 'error' => 'No se pudieron obtener artículos'));
        exit;
    }

    $cache = array(
        'ts'        => time(),
        'total'     => count($articulos),
        'articulos' => $articulos,
    );
    file_put_contents(INVID_CACHE_FILE, json_encode($cache));
    echo json_encode(array('ok' => true, 'total' => count($articulos), 'ts' => $cache['ts'], 'sp_actualizados' => count($sp)));

} elseif ($action === 'sync_catalog') {
    set_time_limit(900);
    $token = invid_get_token();
    $start_offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

    $articulos_map = array();
    if (file_exists(INVID_CACHE_FILE)) {
        $prev = json_decode(file_get_contents(INVID_CACHE_FILE), true);
        if (isset($prev['articulos']) && is_array($prev['articulos'])) {
            foreach ($prev['articulos'] as $a) {
                if (!empty($a['codigo'])) $articulos_map[$a['codigo']] = $a;
            }
        }
    }

    $nuevos = invid_fetch_all($token, $start_offset);
    foreach ($nuevos as $a) {
        $articulos_map[$a['codigo']] = $a;
    }

    $articulos = array_values($articulos_map);
    $cache = array(
        'ts'        => time(),
        'total'     => count($articulos),
        'articulos' => $articulos,
    );
    file_put_contents(INVID_CACHE_FILE, json_encode($cache));
    echo json_encode(array('ok' => true, 'total' => count($articulos), 'nuevos_leidos' => count($nuevos), 'ts' => $cache['ts']));

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
    $param = isset($_GET['p']) ? $_GET['p'] : 'offset=0';
    $ep    = isset($_GET['ep']) ? trim($_GET['ep']) : 'articulo.php';
    $url   = 'https://www.invidcomputers.com/api/v1/' . $ep . '?' . $param;
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_HTTPHEADER     => array('Authorization: Bearer ' . $token, 'Accept: application/json'),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 30,
    ));
    $response = curl_exec($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsize    = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $headers  = substr($response, 0, $hsize);
    $body     = substr($response, $hsize);
    $decoded = json_decode($body, true);
    echo json_encode(array(
        'url'     => $url,
        'code'    => $code,
        'headers' => $headers,
        'raw'     => $decoded,
    ));
} elseif ($action === 'detail') {
    $id = isset($_GET['id']) ? trim($_GET['id']) : '';
    if (!$id) { echo json_encode(array('ok' => false, 'error' => 'ID requerido')); exit; }
    $token = invid_get_token();
    $ch = curl_init(INVID_ART_URL . '?id=' . urlencode($id));
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => array('Authorization: Bearer ' . $token, 'Accept: application/json'),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 15,
    ));
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($raw, true);
    // Con ?id=X la API devuelve data como objeto, no array
    if (isset($data['data']) && is_array($data['data'])) {
        $item = isset($data['data'][0]) ? $data['data'][0] : $data['data'];
    } else {
        $item = null;
    }

    // Fallback a caché si la API devuelve error o 429
    if (!$item && file_exists(INVID_CACHE_FILE)) {
        $cdata = json_decode(file_get_contents(INVID_CACHE_FILE), true);
        if (isset($cdata['articulos']) && is_array($cdata['articulos'])) {
            foreach ($cdata['articulos'] as $a) {
                if ($a['codigo'] === $id) {
                    $item = array(
                        'ID'                => $a['codigo'],
                        'TITLE'             => $a['descripcion'],
                        'PRICE'             => $a['precio'],
                        'IVA_PERCENT'       => $a['iva'],
                        'STOCK_STATUS'      => $a['stock'],
                        'IMAGE_URL'         => $a['imagen'],
                        'BRAND'             => $a['marca'],
                        'CATEGORY'          => $a['categoria'],
                        'PART_NUMBER'       => $a['part_number'],
                        'DESCRIPTION'       => '',
                        'LONG_DESCRIPTION'  => '',
                    );
                    break;
                }
            }
        }
    }

    if (!$item) { echo json_encode(array('ok' => false, 'error' => 'Producto no encontrado')); exit; }
    echo json_encode(array(
        'ok'               => true,
        'codigo'           => arr($item, 'ID',               ''),
        'descripcion'      => arr($item, 'TITLE',            ''),
        'descripcion_corta'=> arr($item, 'DESCRIPTION',      ''),
        'descripcion_larga'=> arr($item, 'LONG_DESCRIPTION', ''),
        'precio'           => arr($item, 'PRICE',            '0'),
        'precio_final'     => arr($item, 'FINAL_PRICE',      '0'),
        'iva'              => arr($item, 'IVA_PERCENT',       0),
        'moneda'           => arr($item, 'CURRENCY',         'USD'),
        'stock'            => arr($item, 'STOCK_STATUS',     ''),
        'imagen'           => arr($item, 'IMAGE_URL',        ''),
        'marca'            => arr($item, 'BRAND',            ''),
        'categoria'        => arr($item, 'CATEGORY',         ''),
        'part_number'      => arr($item, 'PART_NUMBER',      ''),
    ));

} else {
    echo json_encode(array('ok' => false, 'error' => 'Accion invalida'));
}

