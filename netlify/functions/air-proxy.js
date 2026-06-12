const https = require('https');
const http = require('http');

exports.handler = async function(event) {
  const action = (event.queryStringParameters && event.queryStringParameters.action) || 'load';
  const target = 'http://serverres.dyndns.org:81/informes/prueba/Proveedores/sync2.php?action=' + action;

  return new Promise(function(resolve) {
    http.get(target, { timeout: 30000 }, function(res) {
      var body = '';
      res.on('data', function(chunk){ body += chunk; });
      res.on('end', function(){
        resolve({
          statusCode: 200,
          headers: {
            'Content-Type': 'application/json',
            'Access-Control-Allow-Origin': '*'
          },
          body: body
        });
      });
    }).on('error', function(e){
      resolve({
        statusCode: 500,
        headers: { 'Access-Control-Allow-Origin': '*' },
        body: JSON.stringify({ ok: false, error: e.message })
      });
    });
  });
};
