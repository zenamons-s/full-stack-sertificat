const http = require('node:http');

const server = http.createServer((request, response) => {
  if (request.url === '/' || request.url === '/api/health') {
    response.writeHead(200, { 'content-type': 'application/json' });
    response.end(JSON.stringify({ status: 'ok' }));
    return;
  }

  response.writeHead(404, { 'content-type': 'application/json' });
  response.end(JSON.stringify({ status: 'not_found' }));
});

server.listen(3000, '0.0.0.0');
