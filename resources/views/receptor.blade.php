<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receptor - WebSockets Reverb</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <!-- SweetAlert2 por CDN (mencionado en el ejercicio) -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --bg-dark: #090d16;
            --card-bg: rgba(22, 27, 46, 0.85);
            --border-color: rgba(255, 255, 255, 0.12);
            --accent: #10b981;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
        }
        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            padding: 32px 16px;
            background: radial-gradient(circle at top, #0f172a 0%, #030712 100%);
            min-height: 100vh;
            color: var(--text-main);
            box-sizing: border-box;
        }
        .wrap {
            max-width: 860px;
            margin: 0 auto;
        }
        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }
        h1 {
            font-size: 28px;
            font-weight: 700;
            letter-spacing: -0.5px;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            padding: 4px 12px;
            border-radius: 9999px;
            background: rgba(16, 185, 129, 0.15);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: #6ee7b7;
        }
        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #10b981;
            box-shadow: 0 0 8px #10b981;
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.4; transform: scale(0.9); }
        }
        .nav-links a {
            color: #818cf8;
            text-decoration: none;
            font-size: 14px;
            padding: 6px 12px;
            border-radius: 6px;
            background: rgba(129, 140, 248, 0.1);
            border: 1px solid rgba(129, 140, 248, 0.25);
            transition: all 0.2s;
        }
        .nav-links a:hover {
            background: rgba(129, 140, 248, 0.2);
        }
        .info-panel {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 14px 18px;
            margin-bottom: 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 14px;
            color: var(--text-muted);
        }
        .info-panel code {
            color: #38bdf8;
            background: rgba(56, 189, 248, 0.1);
            padding: 2px 6px;
            border-radius: 4px;
        }
        .stage {
            min-height: 280px;
            background: rgba(15, 23, 42, 0.5);
            border: 1px dashed rgba(255, 255, 255, 0.18);
            border-radius: 20px;
            padding: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: inset 0 2px 8px rgba(0, 0, 0, 0.4);
            position: relative;
            overflow: hidden;
        }
        #target-div {
            padding: 20px;
            border: 1px dashed #bbb;
            border-radius: 12px;
            font-size: 18px;
            color: #94a3b8;
            text-align: center;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .history {
            margin-top: 30px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 16px;
        }
        .history h3 {
            margin: 0 0 12px 0;
            font-size: 15px;
            color: #cbd5e1;
        }
        .history-list {
            list-style: none;
            padding: 0;
            margin: 0;
            max-height: 160px;
            overflow-y: auto;
            font-family: monospace;
            font-size: 13px;
        }
        .history-item {
            padding: 8px 12px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            display: flex;
            justify-content: space-between;
            color: #94a3b8;
        }
        .history-item span.time {
            color: #64748b;
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="header">
        <h1>
            <span>📡 Receptor</span>
            <span class="status-badge">
                <span class="status-dot" id="status-dot"></span>
                <span id="status-text">Escuchando WebSocket</span>
            </span>
        </h1>
        <div class="nav-links">
            <a href="{{ route('emisor') }}" target="_blank">Ir a Emisor ↗</a>
        </div>
    </div>

    <div class="info-panel">
        <div>
            Canal: <code>style-channel</code> &bull; Evento: <code>.RemoteStyleUpdate</code>
        </div>
        <div id="event-count">Eventos recibidos: 0</div>
    </div>

    <div class="stage">
        <!-- Contenedor requerido por el ejercicio -->
        <div id="target-div">Esperando mensaje...</div>
    </div>

    <div class="history">
        <h3>Historial de transmisiones recibidas</h3>
        <ul class="history-list" id="history-list">
            <li class="history-item">
                <span>Esperando la primera emisión en vivo...</span>
                <span class="time">--:--:--</span>
            </li>
        </ul>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const targetDiv = document.getElementById('target-div');
    const statusText = document.getElementById('status-text');
    const statusDot = document.getElementById('status-dot');
    const historyList = document.getElementById('history-list');
    const eventCount = document.getElementById('event-count');
    let count = 0;

    if (!window.Echo) {
        console.error('Laravel Echo no está definido.');
        statusText.innerText = 'Echo no cargado';
        statusDot.style.background = '#ef4444';
        return;
    }

    // Suscripción al canal público y escucha del evento tal como pide el documento
    window.Echo.channel('style-channel')
        .listen('.RemoteStyleUpdate', (e) => {
            console.log('Evento recibido en style-channel:', e);
            count++;
            eventCount.innerText = `Eventos recibidos: ${count}`;

            // 1. Actualizar el contenedor como indica el documento
            targetDiv.innerText = e.newText ?? '';
            targetDiv.style.cssText = e.newStyle ?? '';

            // 2. Agregar al historial
            const time = new Date().toLocaleTimeString();
            if (count === 1) {
                historyList.innerHTML = '';
            }
            const li = document.createElement('li');
            li.className = 'history-item';
            li.innerHTML = `<span><strong>${escapeHtml(e.newText || '')}</strong></span><span class="time">${time}</span>`;
            historyList.prepend(li);

            // 3. Disparar SweetAlert (mostrado en páginas 9 y 10 del documento)
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    toast: false,
                    position: 'center',
                    icon: 'warning',
                    title: e?.newText ? `Nuevo mensaje: ${e.newText}` : 'Nuevo mensaje recibido',
                    showConfirmButton: false,
                    timer: 3000,
                    timerProgressBar: true
                });
            }
        });

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.innerText = text;
        return div.innerHTML;
    }
});
</script>
</body>
</html>
