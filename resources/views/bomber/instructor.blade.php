<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Centro de Monitoreo de WebSockets — Panel Docente</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600;700&family=Orbitron:wght@600;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-base: #070913;
            --card-base: #0c1021;
            --border-base: rgba(99, 102, 241, 0.25);
            --accent-cyan: #00f0ff;
            --accent-purple: #818cf8;
            --accent-green: #10b981;
        }
        * { box-sizing: border-box; }
        body {
            background: var(--bg-base);
            font-family: 'JetBrains Mono', monospace;
            color: #cbd5e1;
            margin: 0;
            padding: 20px;
            min-height: 100vh;
        }
        .header-title {
            font-family: 'Orbitron', monospace;
        }
        .card {
            background: var(--card-base);
            border: 1px solid var(--border-base);
            border-radius: 14px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.5);
        }
        .pulse-live {
            animation: pulseLive 2s infinite;
        }
        @keyframes pulseLive {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.3; transform: scale(0.9); }
        }
        .table-container {
            max-height: 520px;
            overflow-y: auto;
        }
        .table-container::-webkit-scrollbar {
            width: 6px;
        }
        .table-container::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.15);
            border-radius: 3px;
        }
        .event-badge {
            font-size: 11px;
            padding: 2px 8px;
            border-radius: 6px;
            font-weight: 600;
        }
        .ev-move { background: rgba(56, 189, 248, 0.15); color: #38bdf8; border: 1px solid rgba(56, 189, 248, 0.3); }
        .ev-bomb { background: rgba(244, 63, 94, 0.15); color: #f43f5e; border: 1px solid rgba(244, 63, 94, 0.3); }
        .ev-explode { background: rgba(245, 158, 11, 0.15); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.3); }
        .ev-room { background: rgba(168, 85, 247, 0.15); color: #c084fc; border: 1px solid rgba(168, 85, 247, 0.3); }
        .ev-game { background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3); }
    </style>
</head>
<body>

    <!-- Header -->
    <header class="flex flex-col md:flex-row items-start md:items-center justify-between pb-6 border-b border-indigo-900/40 mb-6 gap-4">
        <div>
            <div class="flex items-center gap-3">
                <span class="text-2xl">📡</span>
                <h1 class="header-title text-2xl md:text-3xl font-black text-white tracking-wide">
                    PANEL DE MONITOREO EDUCATIVO DE WEBSOCKETS
                </h1>
            </div>
            <p class="text-xs text-indigo-300/80 mt-1">
                Visualización de telemetría en tiempo real &bull; Motor Laravel Reverb &bull; Bomber Arena
            </p>
        </div>

        <div class="flex items-center gap-3">
            <div class="flex items-center gap-2 px-3 py-1.5 rounded-full bg-slate-900 border border-emerald-500/30 text-xs text-emerald-400 font-semibold">
                <span class="w-2.5 h-2.5 rounded-full bg-emerald-400 pulse-live" id="stream-dot"></span>
                <span id="stream-status">STREAM EN VIVO</span>
            </div>
            <button type="button" onclick="togglePauseStream()" id="btn-pause"
                class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-xs font-bold text-slate-300 border border-slate-700">
                ⏸ PAUSAR
            </button>
            <button type="button" onclick="clearTelemetry()"
                class="px-3 py-1.5 rounded-lg bg-rose-950/60 hover:bg-rose-900 text-xs font-bold text-rose-300 border border-rose-500/40">
                🗑 LIMPIAR
            </button>
            <a href="{{ route('lobby') }}" target="_blank"
                class="px-3 py-1.5 rounded-lg bg-indigo-600/30 hover:bg-indigo-600/50 text-xs font-bold text-indigo-300 border border-indigo-500/40">
                LOBBY ↗
            </a>
        </div>
    </header>

    <!-- Metrics Cards Grid -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="card p-4">
            <div class="text-xs text-indigo-300/70 font-semibold mb-1 uppercase tracking-wider">Eventos Capturados</div>
            <div class="text-3xl font-black text-white" id="stat-total-events">{{ $totalLogsCount }}</div>
            <div class="text-[10px] text-emerald-400 mt-1">Sincronizado vía Reverb</div>
        </div>
        <div class="card p-4">
            <div class="text-xs text-indigo-300/70 font-semibold mb-1 uppercase tracking-wider">Salas Activas</div>
            <div class="text-3xl font-black text-cyan-400" id="stat-active-rooms">{{ $rooms->count() }}</div>
            <div class="text-[10px] text-slate-400 mt-1">Capacidad: 2 a 4 jug.</div>
        </div>
        <div class="card p-4">
            <div class="text-xs text-indigo-300/70 font-semibold mb-1 uppercase tracking-wider">Latencia Estimada</div>
            <div class="text-3xl font-black text-emerald-400" id="stat-avg-latency">~12 ms</div>
            <div class="text-[10px] text-slate-400 mt-1">Loopback Reverb ws://</div>
        </div>
        <div class="card p-4">
            <div class="text-xs text-indigo-300/70 font-semibold mb-1 uppercase tracking-wider">Canal de Telemetría</div>
            <div class="text-lg font-bold text-purple-400 truncate mt-1">instructor-monitor</div>
            <div class="text-[10px] text-indigo-300 mt-1">Zero-impact on gameplay</div>
        </div>
    </div>

    <!-- Main Content: Event Stream Table & Room Inspector -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">

        <!-- Left Column: Live Event Stream Table (8 Cols) -->
        <div class="lg:col-span-8 card p-5">
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-2">
                    <span class="text-sm font-bold text-white uppercase tracking-wider">Trazabilidad de Paquetes en Vivo</span>
                    <span class="text-xs px-2 py-0.5 rounded bg-indigo-950 text-indigo-300 border border-indigo-800" id="events-count-badge">0 nuevos</span>
                </div>
                <div class="flex items-center gap-2">
                    <input type="text" id="filter-room" oninput="applyFilters()" placeholder="Filtrar por Sala (ej: AB12CD)"
                        class="px-2.5 py-1 text-xs bg-slate-900 border border-slate-700 rounded text-white focus:outline-none focus:border-indigo-500">
                </div>
            </div>

            <div class="table-container">
                <table class="w-full text-left text-xs border-collapse">
                    <thead>
                        <tr class="border-b border-indigo-900/50 text-indigo-300 font-semibold sticky top-0 bg-[#0c1021]">
                            <th class="py-2.5 px-3">Hora (ms)</th>
                            <th class="py-2.5 px-3">Sala</th>
                            <th class="py-2.5 px-3">Evento</th>
                            <th class="py-2.5 px-3">Origen Lógico</th>
                            <th class="py-2.5 px-3">Destino (Canal)</th>
                            <th class="py-2.5 px-3">Latencia</th>
                            <th class="py-2.5 px-3 text-right">Detalle</th>
                        </tr>
                    </thead>
                    <tbody id="telemetry-tbody">
                        @forelse ($recentLogs as $log)
                            <tr class="border-b border-slate-900 hover:bg-slate-900/50 transition-colors" data-room="{{ $log->room_code }}" data-id="{{ $log->id }}">
                                <td class="py-2 px-3 text-slate-400 font-mono">{{ $log->created_at->format('H:i:s.v') }}</td>
                                <td class="py-2 px-3 font-bold text-white">{{ $log->room_code }}</td>
                                <td class="py-2 px-3">
                                    <span class="event-badge {{ str_contains($log->event_name, 'move') ? 'ev-move' : (str_contains($log->event_name, 'bomb') ? 'ev-bomb' : (str_contains($log->event_name, 'explode') ? 'ev-explode' : 'ev-game')) }}">
                                        {{ $log->event_name }}
                                    </span>
                                </td>
                                <td class="py-2 px-3 text-slate-300">{{ $log->source }}</td>
                                <td class="py-2 px-3 font-mono text-cyan-400/80">{{ $log->destination }}</td>
                                <td class="py-2 px-3 text-emerald-400 font-mono">{{ $log->latency_ms === null ? 'Sin medición' : $log->latency_ms.' ms' }}</td>
                                <td class="py-2 px-3 text-right">
                                    <button type="button" onclick='inspectPayload(@json($log->payload))' class="text-xs text-indigo-400 hover:underline">
                                        JSON
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr id="no-events-row">
                                <td colspan="7" class="py-8 text-center text-slate-500">
                                    Esperando transmisiones en tiempo real... Crea o interactúa en una sala para observar paquetes.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Right Column: Active Rooms & State Inspector (4 Cols) -->
        <div class="lg:col-span-4 flex flex-col gap-6">

            <!-- Active Rooms Card -->
            <div class="card p-5">
                <h3 class="text-sm font-bold text-white uppercase tracking-wider mb-3 flex items-center justify-between">
                    <span>Salas Recientes</span>
                    <button type="button" onclick="refreshPoll()" class="text-xs text-indigo-400 hover:text-indigo-200">↻ Refrescar</button>
                </h3>
                <div class="space-y-3" id="rooms-list">
                    @forelse ($rooms as $r)
                        <div class="p-3 rounded-lg bg-slate-900/60 border border-slate-800 flex items-center justify-between cursor-pointer hover:border-indigo-500 transition-colors"
                            onclick="selectRoomInspect('{{ $r->code }}')">
                            <div>
                                <div class="font-bold text-white flex items-center gap-2">
                                    <span>{{ $r->code }}</span>
                                    <span class="text-[10px] px-1.5 py-0.2 rounded {{ $r->status === 'playing' ? 'bg-emerald-950 text-emerald-400' : 'bg-slate-800 text-slate-400' }}">
                                        {{ strtoupper($r->status) }}
                                    </span>
                                </div>
                                <div class="text-[11px] text-slate-400 mt-0.5">
                                    {{ $r->players->count() }}/{{ $r->max_players }} jugadores conectados
                                </div>
                            </div>
                            <a href="{{ route('arena', ['code' => $r->code]) }}" target="_blank" class="text-xs text-cyan-400 hover:underline">
                                Ver ↗
                            </a>
                        </div>
                    @empty
                        <div class="text-xs text-slate-500 text-center py-4">No hay salas creadas aún</div>
                    @endforelse
                </div>
            </div>

            <!-- JSON Payload Inspector Modal / Box -->
            <div class="card p-5 flex-1">
                <h3 class="text-sm font-bold text-white uppercase tracking-wider mb-2 flex items-center justify-between">
                    <span>Inspector de Payload</span>
                    <span class="text-[10px] text-slate-400" id="inspector-status">Haz clic en "JSON"</span>
                </h3>
                <pre id="json-viewer" class="bg-black/80 p-3 rounded-lg text-[11px] text-emerald-400 font-mono overflow-auto max-h-72 border border-slate-800">
// Los paquetes seleccionados se inspeccionarán aquí.
{
  "protocol": "Reverb-WebSocket",
  "version": "1.12.0",
  "status": "ready"
}
                </pre>
            </div>

        </div>

    </div>

    <!-- Scripts -->
    <script>
        let isPaused = false;
        let lastLogId = {{ $recentLogs->first()->id ?? 0 }};

        document.addEventListener('DOMContentLoaded', () => {
            initEchoMonitor();
            // Polling backup every 2.5s for seamless update
            setInterval(fetchLatestLogs, 2500);
        });

        function initEchoMonitor() {
            if (!window.Echo) {
                console.warn('Echo no disponible');
                return;
            }

            console.log('Suscribiéndose al canal de telemetría: instructor-monitor');
            window.Echo.channel('instructor-monitor')
                .listen('.TelemetryEvent', (e) => {
                    console.log('Paquete de telemetría capturado:', e);
                    if (!isPaused) {
                        appendEventRow({
                            id: Date.now(),
                            time: new Date(e.timestamp * 1000).toLocaleTimeString() + '.' + String(Math.floor((e.timestamp % 1) * 1000)).padStart(3, '0'),
                            room_code: e.roomCode,
                            event_name: e.eventName,
                            source: e.source,
                            destination: e.destination,
                            latency_ms: e.latencyMs,
                            payload: e.payload
                        });
                        incrementEventCount();
                    }
                });
        }

        function appendEventRow(ev) {
            const tbody = document.getElementById('telemetry-tbody');
            const noRow = document.getElementById('no-events-row');
            if (noRow) noRow.remove();

            const tr = document.createElement('tr');
            tr.className = 'border-b border-slate-900 hover:bg-slate-900/50 transition-colors animate-fade-in';
            tr.setAttribute('data-room', ev.room_code);
            tr.setAttribute('data-id', ev.id);

            let badgeClass = 'ev-game';
            if (ev.event_name.includes('move')) badgeClass = 'ev-move';
            else if (ev.event_name.includes('bomb')) badgeClass = 'ev-bomb';
            else if (ev.event_name.includes('explode')) badgeClass = 'ev-explode';
            else if (ev.event_name.includes('room')) badgeClass = 'ev-room';

            tr.innerHTML = `
                <td class="py-2 px-3 text-slate-400 font-mono">${ev.time}</td>
                <td class="py-2 px-3 font-bold text-white">${ev.room_code}</td>
                <td class="py-2 px-3"><span class="event-badge ${badgeClass}">${ev.event_name}</span></td>
                <td class="py-2 px-3 text-slate-300">${escapeHtml(ev.source || 'Servidor')}</td>
                <td class="py-2 px-3 font-mono text-cyan-400/80">${escapeHtml(ev.destination || '')}</td>
                <td class="py-2 px-3 text-emerald-400 font-mono">${ev.latency_ms == null ? 'Sin medición' : ev.latency_ms + ' ms'}</td>
                <td class="py-2 px-3 text-right">
                    <button type="button" class="text-xs text-indigo-400 hover:underline json-btn">JSON</button>
                </td>
            `;

            tr.querySelector('.json-btn').addEventListener('click', () => {
                inspectPayload(ev.payload);
            });

            tbody.prepend(tr);

            // Limit DOM rows to 100
            if (tbody.children.length > 100) {
                tbody.removeChild(tbody.lastChild);
            }
        }

        async function fetchLatestLogs() {
            if (isPaused) return;
            try {
                const res = await fetch(`/api/docente/telemetry?since_id=${lastLogId}`);
                const data = await res.json();
                if (data.logs && data.logs.length > 0) {
                    data.logs.forEach(log => {
                        if (log.id > lastLogId) {
                            lastLogId = log.id;
                            const d = new Date(log.created_at);
                            appendEventRow({
                                id: log.id,
                                time: d.toLocaleTimeString() + '.' + String(d.getMilliseconds()).padStart(3, '0'),
                                room_code: log.room_code,
                                event_name: log.event_name,
                                source: log.source,
                                destination: log.destination,
                                latency_ms: log.latency_ms,
                                payload: log.payload
                            });
                        }
                    });
                }
                if (data.stats) {
                    document.getElementById('stat-total-events').innerText = data.stats.total_events;
                    document.getElementById('stat-avg-latency').innerText = `~${data.stats.avg_latency} ms`;
                }
            } catch (err) {
                console.error(err);
            }
        }

        function incrementEventCount() {
            const countEl = document.getElementById('stat-total-events');
            let val = parseInt(countEl.innerText) || 0;
            countEl.innerText = val + 1;
        }

        function togglePauseStream() {
            isPaused = !isPaused;
            const btn = document.getElementById('btn-pause');
            const dot = document.getElementById('stream-dot');
            const status = document.getElementById('stream-status');

            if (isPaused) {
                btn.innerText = '▶ REANUDAR';
                dot.className = 'w-2.5 h-2.5 rounded-full bg-amber-400';
                status.innerText = 'PAUSADO';
                status.className = 'text-amber-400 font-semibold';
            } else {
                btn.innerText = '⏸ PAUSAR';
                dot.className = 'w-2.5 h-2.5 rounded-full bg-emerald-400 pulse-live';
                status.innerText = 'STREAM EN VIVO';
                status.className = 'text-emerald-400 font-semibold';
            }
        }

        async function clearTelemetry() {
            if (!confirm('¿Deseas vaciar el historial de eventos de telemetría?')) return;
            try {
                await fetch('{{ route('api.docente.clear') }}', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' }
                });
                document.getElementById('telemetry-tbody').innerHTML = '<tr id="no-events-row"><td colspan="7" class="py-8 text-center text-slate-500">Historial vaciado.</td></tr>';
                document.getElementById('stat-total-events').innerText = '0';
            } catch (err) {
                console.error(err);
            }
        }

        function inspectPayload(payload) {
            const viewer = document.getElementById('json-viewer');
            const status = document.getElementById('inspector-status');
            status.innerText = 'Cargado';
            viewer.innerText = JSON.stringify(payload, null, 2);
        }

        function selectRoomInspect(roomCode) {
            document.getElementById('filter-room').value = roomCode;
            applyFilters();
        }

        function applyFilters() {
            const query = document.getElementById('filter-room').value.trim().toUpperCase();
            const rows = document.querySelectorAll('#telemetry-tbody tr[data-room]');
            rows.forEach(r => {
                const room = r.getAttribute('data-room');
                if (!query || room.includes(query)) {
                    r.style.display = '';
                } else {
                    r.style.display = 'none';
                }
            });
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.innerText = text;
            return div.innerHTML;
        }

        function refreshPoll() {
            fetchLatestLogs();
        }
    </script>
</body>
</html>
