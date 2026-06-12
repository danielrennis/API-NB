const http = require('http');
const dns = require('dns');

exports.handler = async function(event) {
  const qs = event.queryStringParameters || {};
  const action = qs.action || 'load';
  const params = Object.keys(qs).map(k => k + '=' + encodeURIComponent(qs[k])).join('&');
  const targetUrl = 'http://serverres.dyndns.org:81/prueba/Proveedores/sync2.php?' + params;

  // Primero resolver el DNS para ver si llega
  const resolvedIp = await new Promise(function(resolve) {
    dns.lookup('serverres.dyndns.org', function(err, address) {
      resolve(err ? ('DNS_ERROR:' + err.message) : address);
    });
  });

  const timeoutMs = action === 'sync' ? 25000 : 15000;

  return new Promise(function(resolve) {
    const options = {
      hostname: 'serverres.dyndns.org',
      port: 81,
      path: '/prueba/Proveedores/sync2.php?' + params,
      method: 'GET',
      timeout: timeoutMs,
      headers: {
        'User-Agent': 'Mozilla/5.0',
        'Accept': 'application/json'
      }
    };

    const req = http.request(options, function(res) {
      var body = '';
      res.on('data', function(chunk){ body += chunk; });
      res.on('end', function(){
        try { JSON.parse(body); } catch(e) {
          body = JSON.stringify({ ok: false, error: 'Respuesta no-JSON: ' + body.substring(0, 300), resolvedIp: resolvedIp });
        }
        resolve({
          statusCode: 200,
          headers: { 'Content-Type': 'application/json; charset=utf-8', 'Access-Control-Allow-Origin': '*' },
          body: body
        });
      });
    });

    req.on('timeout', function() {
      req.destroy();
      resolve({
        statusCode: 200,
        headers: { 'Content-Type': 'application/json', 'Access-Control-Allow-Origin': '*' },
        body: JSON.stringify({ ok: false, error: 'Timeout conectando a ' + targetUrl, resolvedIp: resolvedIp })
      });
    });

    req.on('error', function(e) {
      resolve({
        statusCode: 200,
        headers: { 'Content-Type': 'application/json', 'Access-Control-Allow-Origin': '*' },
        body: JSON.stringify({ ok: false, error: e.message + ' (code:' + e.code + ')', resolvedIp: resolvedIp, target: targetUrl })
      });
    });

    req.end();
  });
};
