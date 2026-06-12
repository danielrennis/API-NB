const http = require('http');

exports.handler = async function(event) {
  const qs = event.queryStringParameters || {};
  const action = qs.action || 'load';

  // Pasar todos los query params al servidor destino
  const params = Object.keys(qs).map(k => k + '=' + encodeURIComponent(qs[k])).join('&');
  const target = 'http://serverres.dyndns.org:81/informes/prueba/Proveedores/sync2.php?' + params;

  // sync puede tardar mucho — usar background function no es posible en free plan
  // timeout generoso de 25s (límite netlify functions es 26s)
  const timeoutMs = action === 'sync' ? 25000 : 15000;

  return new Promise(function(resolve) {
    const req = http.get(target, { timeout: timeoutMs }, function(res) {
      var body = '';
      res.on('data', function(chunk){ body += chunk; });
      res.on('end', function(){
        // Verificar que sea JSON válido
        try { JSON.parse(body); } catch(e) {
          body = JSON.stringify({ ok: false, error: 'Respuesta no-JSON del servidor: ' + body.substring(0, 200) });
        }
        resolve({
          statusCode: 200,
          headers: {
            'Content-Type': 'application/json; charset=utf-8',
            'Access-Control-Allow-Origin': '*',
            'Access-Control-Allow-Methods': 'GET, OPTIONS',
          },
          body: body
        });
      });
    });

    req.on('timeout', function() {
      req.destroy();
      resolve({
        statusCode: 200,
        headers: { 'Content-Type': 'application/json', 'Access-Control-Allow-Origin': '*' },
        body: JSON.stringify({ ok: false, error: 'Timeout: el servidor no respondió a tiempo. Si estás sincronizando, esperá unos minutos y recargá.' })
      });
    });

    req.on('error', function(e) {
      resolve({
        statusCode: 200,
        headers: { 'Content-Type': 'application/json', 'Access-Control-Allow-Origin': '*' },
        body: JSON.stringify({ ok: false, error: 'No se pudo conectar al servidor: ' + e.message })
      });
    });
  });
};
