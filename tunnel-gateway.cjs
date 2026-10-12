/**
 * Tunnel Gateway for Cloudflare
 * Routes HTTP traffic to Laravel (port 8000)
 * Routes WebSocket traffic (upgrade /app) to Laravel Reverb (port 8080)
 */
const http = require('http');
const net = require('net');

const HTTP_PORT = 8000;
const WS_PORT = 8080;
const GATEWAY_PORT = 8090;

const server = http.createServer((clientReq, clientRes) => {
    // If request path is an /app/ or /apps/ Reverb endpoint, forward to Reverb port 8080
    const isReverbHttp = clientReq.url && (clientReq.url.startsWith('/app') || clientReq.url.startsWith('/apps'));
    const targetPort = isReverbHttp ? WS_PORT : HTTP_PORT;

    const options = {
        hostname: '127.0.0.1',
        port: targetPort,
        path: clientReq.url,
        method: clientReq.method,
        headers: {
            ...clientReq.headers,
            'x-forwarded-host': clientReq.headers.host,
            'x-forwarded-proto': clientReq.headers['x-forwarded-proto'] || 'https',
            host: clientReq.headers.host || `127.0.0.1:${targetPort}`,
        },
    };

    const proxyReq = http.request(options, (proxyRes) => {
        clientRes.writeHead(proxyRes.statusCode, proxyRes.headers);
        proxyRes.pipe(clientRes, { end: true });
    });

    proxyReq.on('error', (err) => {
        console.error(`[Gateway Error HTTP -> :${targetPort}]`, err.message);
        if (!clientRes.headersSent) {
            clientRes.writeHead(502, { 'Content-Type': 'text/plain' });
        }
        clientRes.end('Gateway Error connecting to local service');
    });

    clientReq.pipe(proxyReq, { end: true });
});

// Handle WebSocket upgrade connections (WSS -> Reverb)
server.on('upgrade', (req, socket, head) => {
    // Pipe all WebSocket upgrades to Reverb on 8080
    const targetSocket = net.connect(WS_PORT, '127.0.0.1', () => {
        // Construct the HTTP upgrade request for Reverb
        let rawReq = `${req.method} ${req.url} HTTP/1.1\r\n`;
        for (const [key, val] of Object.entries(req.headers)) {
            rawReq += `${key}: ${val}\r\n`;
        }
        rawReq += '\r\n';

        targetSocket.write(rawReq);
        if (head && head.length > 0) {
            targetSocket.write(head);
        }

        socket.pipe(targetSocket);
        targetSocket.pipe(socket);
    });

    targetSocket.on('error', (err) => {
        console.error('[Gateway Error WS -> :8080]', err.message);
        socket.destroy();
    });

    socket.on('error', () => {
        targetSocket.destroy();
    });
});

server.listen(GATEWAY_PORT, '0.0.0.0', () => {
    console.log(`[Tunnel Gateway] Listening on 0.0.0.0:${GATEWAY_PORT}`);
    console.log(`[Tunnel Gateway] HTTP  -> 127.0.0.1:${HTTP_PORT} (Laravel)`);
    console.log(`[Tunnel Gateway] WS    -> 127.0.0.1:${WS_PORT} (Reverb)`);
});
