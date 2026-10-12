<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bomber Arena — Multijugador WebSocket</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@500;700;900&family=Rajdhani:wght@500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #060814;
            --neon-cyan: #00f0ff;
            --neon-pink: #ff007f;
            --neon-yellow: #ffe600;
            --neon-green: #00ff66;
            --card-bg: rgba(13, 17, 38, 0.85);
            --border-glow: rgba(0, 240, 255, 0.3);
        }
        * { box-sizing: border-box; }
        body {
            background: radial-gradient(circle at 50% 20%, #151a42 0%, #060814 80%);
            min-height: 100vh;
            margin: 0;
            font-family: 'Rajdhani', sans-serif;
            color: #e2e8f0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
        }
        .arcade-font {
            font-family: 'Orbitron', monospace;
            letter-spacing: 1px;
        }
        .glow-title {
            text-shadow: 0 0 15px rgba(0, 240, 255, 0.8), 0 0 35px rgba(0, 240, 255, 0.4);
        }
        .glow-pink {
            text-shadow: 0 0 15px rgba(255, 0, 127, 0.8), 0 0 35px rgba(255, 0, 127, 0.4);
        }
        .panel {
            background: var(--card-bg);
            border: 1px solid var(--border-glow);
            box-shadow: 0 0 30px rgba(0, 240, 255, 0.15), inset 0 0 20px rgba(0, 240, 255, 0.05);
            backdrop-filter: blur(12px);
            border-radius: 20px;
        }
        .btn-arcade {
            font-family: 'Orbitron', monospace;
            font-weight: 700;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        .btn-cyan {
            background: linear-gradient(135deg, #00f0ff, #0088cc);
            color: #060814;
            box-shadow: 0 0 20px rgba(0, 240, 255, 0.4);
        }
        .btn-cyan:hover {
            box-shadow: 0 0 30px rgba(0, 240, 255, 0.8);
            transform: translateY(-2px);
        }
        .btn-pink {
            background: linear-gradient(135deg, #ff007f, #b30059);
            color: #ffffff;
            box-shadow: 0 0 20px rgba(255, 0, 127, 0.4);
        }
        .btn-pink:hover {
            box-shadow: 0 0 30px rgba(255, 0, 127, 0.8);
            transform: translateY(-2px);
        }
        .input-arcade {
            background: rgba(6, 8, 20, 0.8);
            border: 1px solid rgba(0, 240, 255, 0.3);
            color: #ffffff;
            font-family: 'Orbitron', monospace;
            transition: all 0.2s;
        }
        .input-arcade:focus {
            outline: none;
            border-color: var(--neon-cyan);
            box-shadow: 0 0 15px rgba(0, 240, 255, 0.5);
        }
        .pulse-dot {
            animation: pulseGlow 2s infinite;
        }
        @keyframes pulseGlow {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.4; transform: scale(0.9); }
        }
    </style>
</head>
<body>

    <!-- Header / Logo -->
    <div class="text-center mb-8">
        <div class="inline-flex items-center gap-3 px-4 py-1.5 rounded-full bg-cyan-950/60 border border-cyan-500/30 text-xs font-semibold uppercase tracking-wider text-cyan-300 mb-3">
            <span class="w-2.5 h-2.5 rounded-full bg-cyan-400 pulse-dot" id="ws-indicator-dot"></span>
            <span id="ws-indicator-text">Conectando a Reverb WebSocket...</span>
        </div>
        <h1 class="text-5xl md:text-7xl font-black arcade-font text-white tracking-wider glow-title uppercase">
            BOMBER <span class="text-pink-500 glow-pink">ARENA</span>
        </h1>
        <p class="text-lg md:text-xl text-slate-400 mt-2 font-medium tracking-wide">
            Videojuego Multijugador en Tiempo Real con <strong class="text-cyan-400">Laravel 13 & Reverb</strong>
        </p>
    </div>

    <!-- Main Container -->
    <div class="w-full max-w-4xl grid grid-cols-1 md:grid-cols-12 gap-8">
        
        <!-- Left Column: Lobby Actions -->
        <div class="md:col-span-7 panel p-6 md:p-8 flex flex-col justify-between">
            <div>
                <h2 class="arcade-font text-2xl font-bold text-white mb-6 flex items-center gap-2">
                    <span class="text-cyan-400">🕹️</span> ZONA DE DESPLIEGUE
                </h2>

                <!-- Nickname Input -->
                <div class="mb-6">
                    <label class="block text-sm font-bold uppercase tracking-wider text-slate-300 mb-2">
                        Tu Nombre de Combate
                    </label>
                    <div class="relative">
                        <input type="text" id="nickname" value="{{ $nickname }}" maxlength="18"
                            class="input-arcade w-full px-4 py-3 rounded-xl text-lg font-bold tracking-wide"
                            placeholder="Ej: BomberZero">
                        <span class="absolute right-3 top-3 text-sm text-cyan-400/60 font-mono">ID: {{ substr($playerId, 0, 6) }}</span>
                    </div>
                </div>

                <!-- Create Room Section -->
                <div class="p-5 rounded-xl bg-slate-900/60 border border-slate-700/60 mb-6">
                    <div class="flex items-center justify-between mb-3">
                        <span class="arcade-font font-bold text-white text-base">Crear Nueva Partida</span>
                        <span class="text-xs px-2 py-0.5 rounded bg-cyan-900/40 text-cyan-400 border border-cyan-500/30 font-mono">2 a 4 Jugadores</span>
                    </div>
                    <p class="text-sm text-slate-400 mb-4">
                        Conviértete en anfitrión, genera una sala segura e invita a tus amigos o alumnos.
                    </p>
                    <button type="button" id="btn-create-room" onclick="createRoom()"
                        class="btn-arcade btn-cyan w-full py-3.5 rounded-xl text-base tracking-wider uppercase cursor-pointer">
                        ⚡ CREAR SALA AHORA
                    </button>
                </div>

                <!-- Join Room Section -->
                <div class="p-5 rounded-xl bg-slate-900/60 border border-slate-700/60">
                    <span class="block arcade-font font-bold text-white text-base mb-2">Unirse con Código</span>
                    <div class="flex gap-3">
                        <input type="text" id="join-code" maxlength="6"
                            class="input-arcade flex-1 px-4 py-3 rounded-xl text-center text-xl font-black uppercase tracking-widest"
                            placeholder="CÓDIGO">
                        <button type="button" id="btn-join-room" onclick="joinRoom()"
                            class="btn-arcade btn-pink px-6 py-3 rounded-xl text-sm tracking-wider uppercase cursor-pointer">
                            UNIRSE
                        </button>
                    </div>
                </div>
            </div>

            <!-- Error message container -->
            <div id="error-msg" class="hidden mt-4 p-3 rounded-lg bg-red-950/80 border border-red-500/60 text-red-200 text-sm font-semibold"></div>
        </div>

        <!-- Right Column: Instructions & Education Monitor -->
        <div class="md:col-span-5 flex flex-col gap-6">
            
            <!-- Controls & Power-ups Card -->
            <div class="panel p-6">
                <h3 class="arcade-font text-lg font-bold text-white mb-4 flex items-center gap-2">
                    <span class="text-pink-400">📖</span> CONTROLES Y MEJORAS
                </h3>
                
                <div class="space-y-4 text-sm text-slate-300">
                    <div class="flex items-center justify-between pb-2 border-b border-slate-800">
                        <span class="font-semibold text-slate-200">Moverse:</span>
                        <span class="font-mono bg-slate-800/80 px-2 py-1 rounded text-cyan-400 border border-cyan-500/20">W, A, S, D o Flechas</span>
                    </div>
                    <div class="flex items-center justify-between pb-2 border-b border-slate-800">
                        <span class="font-semibold text-slate-200">Poner Bomba:</span>
                        <span class="font-mono bg-slate-800/80 px-2 py-1 rounded text-pink-400 border border-pink-500/20">Barra Espaciadora</span>
                    </div>
                    <div class="flex items-center justify-between pb-2 border-b border-slate-800">
                        <span class="font-semibold text-slate-200">Dispositivo Móvil:</span>
                        <span class="font-mono bg-slate-800/80 px-2 py-1 rounded text-yellow-400 border border-yellow-500/20">D-Pad y Botón Táctil</span>
                    </div>
                </div>

                <div class="mt-5 pt-3 border-t border-slate-800">
                    <div class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-2">Power-ups Ocultos:</div>
                    <div class="grid grid-cols-3 gap-2 text-center text-xs">
                        <div class="p-2 rounded bg-cyan-950/40 border border-cyan-500/30 text-cyan-300">
                            <div class="text-base">⚡</div>
                            <span class="font-semibold">Velocidad</span>
                        </div>
                        <div class="p-2 rounded bg-pink-950/40 border border-pink-500/30 text-pink-300">
                            <div class="text-base">💣</div>
                            <span class="font-semibold">+1 Bomba</span>
                        </div>
                        <div class="p-2 rounded bg-yellow-950/40 border border-yellow-500/30 text-yellow-300">
                            <div class="text-base">🔥</div>
                            <span class="font-semibold">+1 Alcance</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Teacher Monitoring Card -->
            <div class="panel p-6 border-indigo-500/40 bg-indigo-950/20">
                <div class="flex items-center gap-2 mb-2">
                    <span class="text-lg">🎓</span>
                    <h3 class="arcade-font text-base font-bold text-indigo-300">PANEL DEL DOCENTE</h3>
                </div>
                <p class="text-xs text-slate-400 mb-4 leading-relaxed">
                    Monitorea en vivo los paquetes WebSocket, canales, origen/destino, marcas de tiempo y evolución de partidas sin interferir en el juego.
                </p>
                <a href="{{ route('docente.monitoreo') }}" target="_blank"
                    class="block text-center py-2.5 px-4 rounded-xl bg-indigo-600/30 hover:bg-indigo-600/50 border border-indigo-500/50 text-indigo-200 font-bold text-xs uppercase tracking-wider transition-all">
                    Abrir Monitor de WebSockets ↗
                </a>
            </div>

        </div>

    </div>

    <!-- Scripts -->
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const wsDot = document.getElementById('ws-indicator-dot');
            const wsText = document.getElementById('ws-indicator-text');

            if (window.Echo) {
                // Check connection state via Pusher underlying client
                if (window.Echo.connector && window.Echo.connector.pusher) {
                    const pusher = window.Echo.connector.pusher;
                    pusher.connection.bind('connected', () => {
                        wsDot.className = 'w-2.5 h-2.5 rounded-full bg-emerald-400 pulse-dot';
                        wsText.innerText = 'WebSocket Reverb: Conectado (ws://127.0.0.1:8080)';
                        wsText.className = 'text-emerald-400 font-semibold';
                    });
                    pusher.connection.bind('disconnected', () => {
                        wsDot.className = 'w-2.5 h-2.5 rounded-full bg-red-500';
                        wsText.innerText = 'WebSocket Desconectado';
                        wsText.className = 'text-red-400 font-semibold';
                    });
                    if (pusher.connection.state === 'connected') {
                        wsDot.className = 'w-2.5 h-2.5 rounded-full bg-emerald-400 pulse-dot';
                        wsText.innerText = 'WebSocket Reverb: Conectado (ws://127.0.0.1:8080)';
                        wsText.className = 'text-emerald-400 font-semibold';
                    }
                }
            }
        });

        function showError(msg) {
            const el = document.getElementById('error-msg');
            el.innerText = msg;
            el.classList.remove('hidden');
        }

        async function createRoom() {
            const nickname = document.getElementById('nickname').value.trim() || 'Comandante';
            const btn = document.getElementById('btn-create-room');
            btn.disabled = true;
            btn.innerText = 'CREANDO SALA...';

            try {
                const response = await fetch('{{ route('api.rooms.create') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({ nickname })
                });

                const data = await response.json();
                if (data.success && data.redirect) {
                    window.location.href = data.redirect;
                } else {
                    showError(data.error || 'No se pudo crear la sala');
                    btn.disabled = false;
                    btn.innerText = '⚡ CREAR SALA AHORA';
                }
            } catch (err) {
                console.error(err);
                showError('Error de red al crear la sala');
                btn.disabled = false;
                btn.innerText = '⚡ CREAR SALA AHORA';
            }
        }

        async function joinRoom() {
            const code = document.getElementById('join-code').value.trim().toUpperCase();
            const nickname = document.getElementById('nickname').value.trim() || 'Piloto';
            const btn = document.getElementById('btn-join-room');

            if (!code || code.length < 4) {
                showError('Ingresa un código de sala válido');
                return;
            }

            btn.disabled = true;
            btn.innerText = '...';

            try {
                const response = await fetch('{{ route('api.rooms.join') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({ code, nickname })
                });

                const data = await response.json();
                if (data.success && data.redirect) {
                    window.location.href = data.redirect;
                } else {
                    showError(data.error || 'Código incorrecto o sala llena');
                    btn.disabled = false;
                    btn.innerText = 'UNIRSE';
                }
            } catch (err) {
                console.error(err);
                showError('Error de red al unirse a la sala');
                btn.disabled = false;
                btn.innerText = 'UNIRSE';
            }
        }
    </script>
</body>
</html>
