<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Sala {{ $room->code }} — Bomber Arena</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@600;700;800;900&family=Rajdhani:wght@500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-main: #0B1020;
            --panel-bg: #151D31;
            --panel-border: #233052;
            --neon-cyan: #38BDF8;
            --neon-violet: #A78BFA;
            --neon-coral: #FB7185;
            --neon-green: #34D399;
            --neon-yellow: #FBBF24;
        }
        * {
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }
        body {
            background-color: var(--bg-main);
            background-image: 
                radial-gradient(circle at 50% 15%, #182342 0%, #0B1020 80%),
                linear-gradient(rgba(35, 48, 82, 0.12) 1px, transparent 1px),
                linear-gradient(90deg, rgba(35, 48, 82, 0.12) 1px, transparent 1px);
            background-size: 100% 100%, 36px 36px, 36px 36px;
            margin: 0;
            font-family: 'Rajdhani', sans-serif;
            color: #F1F5F9;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            overflow-x: hidden;
            user-select: none;
            -webkit-user-select: none;
        }
        .arcade-font {
            font-family: 'Orbitron', monospace;
            letter-spacing: 1px;
        }

        /* Responsive Canvas Wrapper */
        .canvas-container {
            position: relative;
            box-shadow: 0 0 35px rgba(56, 189, 248, 0.25), 0 10px 40px rgba(0, 0, 0, 0.6);
            border: 2px solid #233052;
            border-radius: 16px;
            overflow: hidden;
            background: #0B1020;
            width: 100%;
            max-width: 650px;
            aspect-ratio: 13 / 11;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: center;
            touch-action: none;
            transition: aspect-ratio 0.2s ease, max-width 0.2s ease;
        }
        .canvas-container.is-portrait {
            aspect-ratio: 11 / 13;
            max-width: min(96vw, calc((100dvh - 250px) * 11 / 13));
            max-height: calc(100dvh - 250px);
        }
        #game-canvas {
            width: 100%;
            height: 100%;
            display: block;
            aspect-ratio: inherit;
            object-fit: contain;
        }

        /* Ergonomic Cyberpunk Mobile Controls */
        .gamepad-container {
            width: 100%;
            max-width: 550px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 12px;
            margin-top: 4px;
            touch-action: none;
            user-select: none;
            -webkit-user-select: none;
        }

        .dpad-cluster {
            display: grid;
            grid-template-columns: repeat(3, 48px);
            grid-template-rows: repeat(3, 48px);
            gap: 6px;
            position: relative;
        }
        @media (min-width: 480px) {
            .dpad-cluster {
                grid-template-columns: repeat(3, 54px);
                grid-template-rows: repeat(3, 54px);
                gap: 8px;
            }
        }

        .touch-pad-btn {
            background: linear-gradient(145deg, #1E294B, #0F172A);
            border: 1.5px solid #2A3B66;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--neon-cyan);
            user-select: none;
            cursor: pointer;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.4), inset 0 1px 0 rgba(255, 255, 255, 0.1);
            transition: transform 0.08s ease, background-color 0.08s ease, box-shadow 0.08s ease;
        }
        .touch-pad-btn:active, .touch-pad-btn.is-active {
            transform: scale(0.90);
            background: rgba(56, 189, 248, 0.35);
            border-color: var(--neon-cyan);
            box-shadow: 0 0 16px rgba(56, 189, 248, 0.7), inset 0 2px 6px rgba(0, 0, 0, 0.6);
            color: #FFFFFF;
        }

        .dpad-center {
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: rgba(255, 255, 255, 0.2);
            font-size: 10px;
        }

        /* Tactile Bomb Action Button */
        .bomb-cluster {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 4px;
        }
        .bomb-touch-btn {
            width: 78px;
            height: 78px;
            border-radius: 50%;
            background: radial-gradient(circle at 35% 35%, #FB7185 0%, #E11D48 65%, #881337 100%);
            border: 3px solid #FDA4AF;
            box-shadow: 0 0 22px rgba(251, 113, 133, 0.55), inset 0 2px 6px rgba(255, 255, 255, 0.4);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #FFFFFF;
            cursor: pointer;
            transition: transform 0.08s ease, box-shadow 0.08s ease;
            position: relative;
        }
        @media (min-width: 480px) {
            .bomb-touch-btn {
                width: 86px;
                height: 86px;
            }
        }
        .bomb-touch-btn:active, .bomb-touch-btn.is-active {
            transform: scale(0.88);
            box-shadow: 0 0 35px rgba(251, 113, 133, 0.9), inset 0 4px 12px rgba(0, 0, 0, 0.8);
            background: radial-gradient(circle at 50% 50%, #FB7185 0%, #9F1239 100%);
        }
        .bomb-label {
            font-family: 'Orbitron', monospace;
            font-size: 10px;
            font-weight: 700;
            color: var(--neon-coral);
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .cyber-panel {
            background: rgba(21, 29, 49, 0.92);
            backdrop-filter: blur(14px);
            border: 1px solid var(--panel-border);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.45);
        }
        .pulse-text {
            animation: pulseText 1.5s infinite;
        }
        @keyframes pulseText {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(0.95); }
        }
        i[data-lucide] {
            width: 18px;
            height: 18px;
            stroke-width: 2px;
            display: inline-block;
            vertical-align: middle;
        }
    </style>
</head>
<body class="p-1.5 sm:p-3">

    <!-- Top Bar Navigation & Info -->
    <header class="w-full max-w-5xl mx-auto flex items-center justify-between py-2 px-3 sm:px-4 rounded-2xl cyber-panel mb-2">
        <div class="flex items-center gap-2.5">
            <a href="{{ route('lobby') }}" class="text-xs uppercase font-bold text-slate-400 hover:text-cyan-400 transition-colors flex items-center gap-1">
                <i data-lucide="log-out" class="w-3.5 h-3.5"></i>
                <span class="hidden sm:inline">LOBBY</span>
            </a>
            <div class="h-3 w-px bg-slate-700"></div>
            <div class="arcade-font text-xs sm:text-sm font-bold text-cyan-400 flex items-center gap-1.5">
                <span class="text-slate-400">SALA:</span>
                <span class="bg-cyan-950/80 px-2.5 py-0.5 rounded-lg border border-cyan-500/40 text-white tracking-widest font-black" id="header-room-code">{{ $room->code }}</span>
                <button type="button" onclick="copyCode()" class="p-1 rounded hover:bg-slate-800 text-slate-400 hover:text-cyan-300 transition-colors" title="Copiar código">
                    <i data-lucide="copy" class="w-3.5 h-3.5"></i>
                </button>
            </div>
        </div>

        <div class="flex items-center gap-1.5 sm:gap-2">
            <!-- Orientation Toggle (Auto/Vertical/Horizontal) -->
            <button type="button" id="btn-toggle-orientation" onclick="toggleOrientationMode()" class="px-2 sm:px-2.5 py-1 rounded-xl bg-slate-900 text-[11px] font-bold text-cyan-400 border border-slate-700 flex items-center gap-1 cursor-pointer hover:bg-slate-800" title="Rotar orientación de pantalla">
                <i data-lucide="smartphone" id="orient-icon" class="w-3.5 h-3.5"></i>
                <span id="orient-label" class="hidden sm:inline">HORIZONTAL</span>
            </button>
            <!-- WebSocket Connection Status -->
            <div class="flex items-center gap-1.5 text-[11px] font-semibold px-2 sm:px-2.5 py-1 rounded-full bg-slate-900 border border-slate-700">
                <span class="w-2 h-2 rounded-full bg-emerald-400" id="arena-ws-dot"></span>
                <i data-lucide="wifi" class="w-3 h-3 text-emerald-400"></i>
                <span id="arena-ws-status" class="hidden md:inline text-slate-300">Reverb</span>
            </div>
            <!-- Haptic Vibration Toggle -->
            <button type="button" id="btn-toggle-haptics" onclick="toggleHaptics()" class="px-2 sm:px-2.5 py-1 rounded-xl bg-slate-900 text-[11px] font-bold text-cyan-300 border border-slate-700 flex items-center gap-1 cursor-pointer hover:bg-slate-800" title="Vibración Háptica">
                <i data-lucide="smartphone" class="w-3.5 h-3.5"></i>
                <span id="haptic-label" class="hidden sm:inline">VIB ON</span>
            </button>
            <!-- Audio Mute Button -->
            <button type="button" id="btn-toggle-audio" onclick="toggleAudio()" class="px-2 sm:px-2.5 py-1 rounded-xl bg-slate-900 text-[11px] font-bold text-slate-300 hover:text-white border border-slate-700 flex items-center gap-1 cursor-pointer hover:bg-slate-800" title="Silenciar / Activar sonido">
                <i data-lucide="volume-2" id="audio-icon" class="w-3.5 h-3.5 text-cyan-400"></i>
            </button>
        </div>
    </header>

    <!-- MAIN GAME AREA WRAPPER -->
    <main class="w-full max-w-5xl mx-auto flex-1 flex flex-col items-center justify-center relative">

        <!-- LOBBY WAITING SCREEN -->
        <div id="screen-lobby" class="w-full max-w-2xl cyber-panel p-5 sm:p-8 rounded-2xl text-center {{ $room->status === 'playing' ? 'hidden' : '' }}">
            <h2 class="arcade-font text-2xl sm:text-3xl font-black text-white mb-1 tracking-wide">
                SALA DE ESPERA <span class="text-cyan-400">[{{ $room->code }}]</span>
            </h2>
            <p class="text-slate-400 text-xs sm:text-sm mb-6">Comparte el código con tus contrincantes (2 a 4 jugadores).</p>

            <!-- Slots Grid -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6" id="lobby-slots-grid">
                <!-- Dynamically rendered slots with character SVG avatars -->
            </div>

            <!-- Action buttons -->
            <div class="flex flex-col sm:flex-row gap-3 justify-center">
                <button type="button" id="btn-ready-toggle" onclick="toggleReady()"
                    class="arcade-font px-5 py-3 rounded-xl font-bold text-xs sm:text-sm tracking-wider uppercase bg-slate-900 hover:bg-slate-800 border border-slate-700 text-slate-200 transition-all flex items-center justify-center gap-2 cursor-pointer">
                    <i data-lucide="check" class="w-4 h-4 text-emerald-400"></i>
                    <span>LISTO: <span id="ready-status-text" class="text-rose-400 font-black">NO</span></span>
                </button>

                <button type="button" id="btn-start-game" onclick="startGame()"
                    class="arcade-font px-6 py-3 rounded-xl font-black text-xs sm:text-sm tracking-wider uppercase bg-gradient-to-r from-cyan-400 to-blue-600 text-slate-950 shadow-lg shadow-cyan-500/30 hover:shadow-cyan-500/60 transition-all disabled:opacity-40 disabled:pointer-events-none flex items-center justify-center gap-2 cursor-pointer">
                    <i data-lucide="play" class="w-4 h-4"></i>
                    <span>INICIAR PARTIDA</span>
                </button>
            </div>
            <div id="lobby-warning" class="text-xs text-amber-400 mt-3.5 font-semibold hidden"></div>
        </div>

        <!-- PLAYING SCREEN -->
        <div id="screen-game" class="w-full flex flex-col items-center {{ $room->status === 'playing' ? '' : 'hidden' }}">
            
            <!-- In-game HUD -->
            <div class="w-full max-w-[650px] flex items-center justify-between mb-1.5 px-1 text-[11px] font-bold uppercase tracking-wider">
                <div class="flex items-center gap-2 overflow-x-auto py-0.5" id="hud-players">
                    <!-- Dynamic player chips with mini robot avatars -->
                </div>
                <div class="flex items-center gap-1.5 bg-slate-900/90 px-3 py-1 rounded-xl border border-cyan-500/30 shrink-0">
                    <i data-lucide="clock" class="w-3.5 h-3.5 text-cyan-400"></i>
                    <span class="arcade-font text-cyan-400 text-sm font-black" id="game-timer">03:00</span>
                </div>
            </div>

            <!-- Responsive Canvas Container -->
            <div class="canvas-container">
                <canvas id="game-canvas" width="650" height="550"></canvas>

                <!-- Countdown Overlay -->
                <div id="countdown-overlay" class="absolute inset-0 bg-black/75 backdrop-blur-sm flex items-center justify-center hidden z-20">
                    <div class="arcade-font text-6xl md:text-9xl font-black text-cyan-400 pulse-text" id="countdown-number">3</div>
                </div>

                <!-- Match Over Modal -->
                <div id="gameover-modal" class="absolute inset-0 bg-black/85 backdrop-blur-md flex flex-col items-center justify-center p-4 text-center hidden z-30">
                    <!-- Winner Avatar Preview Spot -->
                    <div id="gameover-avatar-podium" class="mb-2 flex items-center justify-center"></div>

                    <h3 class="arcade-font text-3xl sm:text-5xl font-black text-white mb-1" id="gameover-title">¡VICTORIA!</h3>
                    <p class="text-sm sm:text-base text-cyan-400 font-semibold mb-4" id="gameover-subtitle">Piloto 1 es el Campeón</p>

                    <!-- Scoreboard -->
                    <div class="w-full max-w-sm cyber-panel rounded-xl p-3 mb-4 text-xs" id="gameover-stats">
                        <!-- Stats rows -->
                    </div>

                    <div class="flex gap-3">
                        <button type="button" id="rematch-button" onclick="requestRematch()" class="arcade-font px-5 py-2.5 rounded-xl bg-cyan-400 text-slate-950 font-black text-xs uppercase tracking-wider shadow-lg shadow-cyan-400/40 hover:bg-cyan-300 flex items-center gap-2 cursor-pointer hidden">
                            <i data-lucide="rotate-ccw" class="w-3.5 h-3.5"></i>
                            <span>REVANCHA</span>
                        </button>
                        <a href="{{ route('lobby') }}" class="arcade-font px-5 py-2.5 rounded-xl bg-slate-800 text-slate-200 font-bold text-xs uppercase tracking-wider hover:bg-slate-700 flex items-center gap-2 cursor-pointer">
                            <i data-lucide="log-out" class="w-3.5 h-3.5"></i>
                            <span>LOBBY</span>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Ergonomic Touch Controls for Mobile -->
            <div class="gamepad-container md:hidden" id="mobile-controls">
                
                <!-- Directional D-Pad -->
                <div class="dpad-cluster">
                    <div></div>
                    <button type="button" id="dpad-up" class="touch-pad-btn" aria-label="Arriba">
                        <i data-lucide="arrow-up" class="w-5 h-5"></i>
                    </button>
                    <div></div>
                    <button type="button" id="dpad-left" class="touch-pad-btn" aria-label="Izquierda">
                        <i data-lucide="arrow-left" class="w-5 h-5"></i>
                    </button>
                    <div class="dpad-center">●</div>
                    <button type="button" id="dpad-right" class="touch-pad-btn" aria-label="Derecha">
                        <i data-lucide="arrow-right" class="w-5 h-5"></i>
                    </button>
                    <div></div>
                    <button type="button" id="dpad-down" class="touch-pad-btn" aria-label="Abajo">
                        <i data-lucide="arrow-down" class="w-5 h-5"></i>
                    </button>
                    <div></div>
                </div>

                <!-- Giant Bomb Button with Haptics -->
                <div class="bomb-cluster">
                    <button type="button" id="dpad-bomb" class="bomb-touch-btn" aria-label="Colocar Bomba">
                        <i data-lucide="bomb" class="w-9 h-9 text-white"></i>
                    </button>
                    <span class="bomb-label">BOMBA</span>
                </div>

            </div>

        </div>

    </main>

    <!-- Haptic Engine -->
    <script>
        class HapticEngine {
            constructor() {
                this.enabled = true;
                this.supported = 'vibrate' in navigator;
            }
            toggle() {
                this.enabled = !this.enabled;
                const label = document.getElementById('haptic-label');
                if (label) {
                    label.innerText = this.enabled ? 'VIB ON' : 'VIB OFF';
                }
                const btn = document.getElementById('btn-toggle-haptics');
                if (btn) {
                    btn.className = this.enabled 
                        ? 'px-2.5 py-1 rounded-xl bg-slate-900 text-[11px] font-bold text-cyan-300 border border-slate-700 flex items-center gap-1 cursor-pointer' 
                        : 'px-2.5 py-1 rounded-xl bg-slate-900 text-[11px] font-bold text-slate-500 border border-slate-700 flex items-center gap-1 cursor-pointer';
                }
                if (this.enabled) this.light();
            }
            light() {
                if (!this.enabled || !this.supported) return;
                try { navigator.vibrate(25); } catch (e) {}
            }
            heavy() {
                if (!this.enabled || !this.supported) return;
                try { navigator.vibrate(55); } catch (e) {}
            }
            explosion() {
                if (!this.enabled || !this.supported) return;
                try { navigator.vibrate([70, 30, 90]); } catch (e) {}
            }
            victory() {
                if (!this.enabled || !this.supported) return;
                try { navigator.vibrate([50, 40, 50, 40, 120]); } catch (e) {}
            }
        }
        const haptics = new HapticEngine();
        function toggleHaptics() {
            haptics.toggle();
        }
    </script>

    <!-- Client State & Canvas Game Loop -->
    <script>
        const ROOM_CODE = "{{ $room->code }}";
        const CURRENT_PLAYER_ID = "{{ $playerId }}";
        const CSRF_TOKEN = "{{ csrf_token() }}";

        let currentRoom = @json($room);
        let gameState = @json($room->game_state);
        let myPlayer = null;
        let isHost = false;
        let timerInterval = null;
        let remainingSeconds = {{ $room->round_duration_seconds }};

        const canvas = document.getElementById('game-canvas');
        let gameRenderer = null;
        let sfx = null;

        let serverClockOffset = 0;
        let lastProcessedVersion = (gameState && gameState.version) ? gameState.version : 0;
        let roundGeneration = 0;
        let countdownInterval = null;
        let isCountingDown = false;
        let rematchInFlight = false;
        let roomStateInFlight = false;
        const processedEventIds = new Set();
        const detonatedBombIds = new Set();
        let inputSequence = gameState?.input_sequences?.[CURRENT_PLAYER_ID] || 0;
        const diagnostics = { rtt: 0, corrections: 0, staleStates: 0, events: 0, lastReceived: 0 };
        const diagnosticsAllowed = {{ app()->environment('local') || auth()->user()?->is_admin ? 'true' : 'false' }};
        let diagnosticsEnabled = false;
        window.addEventListener('keydown', event => {
            if (diagnosticsAllowed && event.code === 'F3') {
                event.preventDefault();
                diagnosticsEnabled = !diagnosticsEnabled;
            }
        });

        function syncServerTime(serverUnixTimestamp) {
            if (typeof serverUnixTimestamp === 'number' && serverUnixTimestamp > 0) {
                serverClockOffset = (serverUnixTimestamp * 1000) - Date.now();
            }
        }

        function getCurrentServerTime() {
            return (Date.now() + serverClockOffset) / 1000;
        }

        let currentOrientationMode = 'auto'; // 'auto', 'portrait', 'landscape'

        function applyOrientation(portrait) {
            if (gameRenderer) {
                gameRenderer.setPortrait(portrait);
            }
            const container = document.querySelector('.canvas-container');
            const canvasEl = document.getElementById('game-canvas');
            const orientIcon = document.getElementById('orient-icon');
            const orientLabel = document.getElementById('orient-label');

            if (container && canvasEl) {
                if (portrait) {
                    container.classList.add('is-portrait');
                    if (orientLabel) orientLabel.innerText = 'VERTICAL';
                    if (orientIcon) {
                        orientIcon.className = 'w-3.5 h-3.5 rotate-90 text-cyan-400';
                    }
                } else {
                    container.classList.remove('is-portrait');
                    if (orientLabel) orientLabel.innerText = 'HORIZONTAL';
                    if (orientIcon) {
                        orientIcon.className = 'w-3.5 h-3.5 text-slate-400';
                    }
                }
            }
            if (window.lucide && window.lucide.createIcons) {
                window.lucide.createIcons();
            }
        }

        function checkAutoOrientation() {
            if (currentOrientationMode !== 'auto') return;
            // Screen width <= 800px and height > width indicates mobile vertical portrait
            const isPortrait = window.innerWidth <= 800 && window.innerHeight > window.innerWidth;
            applyOrientation(isPortrait);
        }

        function toggleOrientationMode() {
            haptics.light();
            const isCurrentPortrait = gameRenderer ? gameRenderer.portrait : false;
            const nextPortrait = !isCurrentPortrait;
            currentOrientationMode = nextPortrait ? 'portrait' : 'landscape';
            applyOrientation(nextPortrait);
        }

        document.addEventListener('DOMContentLoaded', () => {
            // Initialize Game Renderer & Audio Synthesizer
            if (window.BomberEngine) {
                gameRenderer = new window.BomberEngine.GameRenderer(canvas);
                sfx = new window.BomberEngine.SoundEngine();
            }

            checkAutoOrientation();
            window.addEventListener('resize', checkAutoOrientation);
            window.addEventListener('orientationchange', () => {
                setTimeout(checkAutoOrientation, 150);
            });

            initEcho();
            updateLobbyView();
            initKeyboard();
            initTouchControls();
            setInterval(() => {
                if (currentRoom.status !== 'playing') fetchRoomState();
            }, 2000);

            if (window.lucide && window.lucide.createIcons) {
                window.lucide.createIcons();
            }

            if (currentRoom.status === 'playing' && gameState) {
                startGameLoop();
            }
            if (gameState && gameState.is_game_over) {
                gameState.is_game_over = false;
                handleIncomingEvent({ type: 'game_over', data: { round_id: gameState.round_id, winner_id: gameState.winner_id, finish_reason: gameState.finish_reason, players: gameState.players } });
            }
        });

        function toggleAudio() {
            if (!sfx) return;
            const enabled = sfx.toggle();
            const icon = document.getElementById('audio-icon');
            if (icon) {
                icon.setAttribute('data-lucide', enabled ? 'volume-2' : 'volume-x');
                icon.className = enabled ? 'w-3.5 h-3.5 text-cyan-400' : 'w-3.5 h-3.5 text-slate-500';
            }
            if (window.lucide && window.lucide.createIcons) {
                window.lucide.createIcons();
            }
        }

        function copyCode() {
            navigator.clipboard.writeText(ROOM_CODE);
            haptics.light();
            alert(`Código ${ROOM_CODE} copiado al portapapeles!`);
        }

        // Setup Echo WebSocket listener for this room's channel
        function initEcho() {
            if (!window.Echo) {
                console.warn('Echo no inicializado');
                return;
            }

            const channelName = `arena.${ROOM_CODE}`;
            console.log(`Suscribiéndose al canal de Reverb: ${channelName}`);

            window.Echo.channel(channelName)
                .listen('.GameEvent', (e) => {
                    handleIncomingEvent(e);
                });
            window.Echo.connector?.pusher?.connection?.bind('connected', () => fetchRoomState());
        }

        function handleIncomingEvent(e) {
            const { type, data } = e;
            diagnostics.events++;
            diagnostics.lastReceived = performance.now();

            if (type === 'game_started') {
                if (!data.state || (gameState && data.state.started_at < gameState.started_at)) return;
                if (gameState && data.state.round_id === gameState.round_id && currentRoom.status === 'playing') return;
            } else if (data && data.round_id && (!gameState || data.round_id !== gameState.round_id)) {
                return;
            }

            if (data && data.event_id) {
                if (processedEventIds.has(data.event_id)) return;
                processedEventIds.add(data.event_id);
                if (processedEventIds.size > 512) processedEventIds.delete(processedEventIds.values().next().value);
            }

            if (data && data.server_time) {
                syncServerTime(data.server_time);
            }

            if (data && typeof data.version === 'number') {
                lastProcessedVersion = Math.max(lastProcessedVersion, data.version);
            }

            switch (type) {
                case 'state_updated':
                    applyStateSnapshot(data.state);
                    break;
                case 'player_joined':
                    fetchRoomState();
                    break;
                case 'player_ready':
                    fetchRoomState();
                    break;
                case 'game_started':
                    stopRound();
                    roundGeneration++;
                    if (actionRequestController) actionRequestController.abort();
                    if (tickRequestController) tickRequestController.abort();
                    isFlushingActions = false;
                    serverTickInFlight = false;
                    processedEventIds.clear();
                    detonatedBombIds.clear();
                    gameState = data.state;
                    lastProcessedVersion = gameState.version || 1;
                    lastMoveTime = 0;
                    inputSequence = gameState.input_sequences?.[CURRENT_PLAYER_ID] || 0;
                    lastServerTickTime = 0;
                    pendingActionQueue = [];
                    if (gameRenderer) gameRenderer.activeExplosions = [];
                    currentRoom.status = 'playing';
                    remainingSeconds = data.duration || 180;
                    if (gameRenderer) gameRenderer.invalidateBoard();
                    document.getElementById('screen-lobby').classList.add('hidden');
                    document.getElementById('screen-game').classList.remove('hidden');
                    document.getElementById('gameover-modal').classList.add('hidden');
                    haptics.heavy();
                    const countdownSecs = (data.rules && typeof data.rules.countdown_seconds === 'number')
                        ? data.rules.countdown_seconds : 3;
                    runCountdown(() => {
                        startGameLoop();
                    }, countdownSecs);
                    break;
                case 'player_moved':
                    if (gameState && gameState.players[data.player_id]) {
                        const player = gameState.players[data.player_id];
                        if (data.version && data.version < Math.max(player._lastMoveVersion || 0, gameState.version || 0)) { diagnostics.staleStates++; break; }
                        player._lastMoveVersion = data.version || 0;
                        if (data.stats) {
                            player.speed = data.stats.speed;
                            player.max_bombs = data.stats.max_bombs;
                            player.bomb_range = data.stats.bomb_range;
                            player.kills = data.stats.kills;
                        }
                        {
                            gameState.players[data.player_id].x = data.x;
                            gameState.players[data.player_id].y = data.y;
                            gameState.players[data.player_id].direction = data.direction;
                        }

                        if (data.collected_powerup) {
                            if (sfx) sfx.playPowerup();
                            if (data.player_id === CURRENT_PLAYER_ID) {
                                haptics.heavy();
                            }
                            (data.collected_keys || [`${data.x}_${data.y}`]).forEach(key => delete gameState.powerups[key]);
                        }
                    }
                    break;
                case 'bomb_placed':
                    if (gameState && data.bomb) {
                        if (data.version && data.version < gameState.version) { diagnostics.staleStates++; break; }
                        if (detonatedBombIds.has(data.bomb.id) || gameState.is_game_over) break;
                        if (!gameState.bombs) gameState.bombs = {};
                        // If placed by local player, remove optimistic placeholder
                        if (data.bomb.owner_id === CURRENT_PLAYER_ID) {
                            Object.keys(gameState.bombs).forEach(id => {
                                if (gameState.bombs[id]._optimistic &&
                                    gameState.bombs[id].x === data.bomb.x &&
                                    gameState.bombs[id].y === data.bomb.y) {
                                    delete gameState.bombs[id];
                                }
                            });
                        }
                        data.bomb.local_planted_at = performance.now();
                        gameState.bombs[data.bomb.id] = data.bomb;
                        if (data.bomb.owner_id !== CURRENT_PLAYER_ID && sfx) {
                            sfx.playBombDrop();
                        }
                    }
                    break;
                case 'bomb_exploded':
                    if (gameState) {
                        detonatedBombIds.add(data.root_bomb_id);
                        delete gameState.bombs[data.root_bomb_id];
                        if (data.detonated_bomb_ids) {
                            data.detonated_bomb_ids.forEach(id => {
                                detonatedBombIds.add(id);
                                delete gameState.bombs[id];
                            });
                        }

                        // Add explosion effect to modular GameRenderer
                        if (gameRenderer) {
                            gameRenderer.addExplosion(data.explosion_tiles || [], data.destroyed_blocks || []);
                        }

                        if (sfx) {
                            sfx.playExplosion();
                            if (data.destroyed_blocks && data.destroyed_blocks.length > 0) {
                                sfx.playBlockCrumble();
                            }
                        }
                        haptics.explosion();

                        // Destroy blocks in grid state
                        if (data.destroyed_blocks) {
                            data.destroyed_blocks.forEach(b => {
                                gameState.grid[b.y][b.x] = 0;
                            });
                        }

                        // Spawn power-ups
                        if (data.spawned_powerups) {
                            data.spawned_powerups.forEach(pu => {
                                if (!data.version || data.version >= gameState.version) gameState.powerups[`${pu.x}_${pu.y}`] = pu.type;
                            });
                        }

                        // Eliminations
                        if (data.eliminated_players) {
                            data.eliminated_players.forEach(p => {
                                if (gameState.players[p.player_id]) {
                                    gameState.players[p.player_id].alive = false;
                                }
                                if (sfx) sfx.playElimination();
                                if (p.player_id === CURRENT_PLAYER_ID) {
                                    haptics.explosion();
                                }
                            });
                        }

                        updateHud();
                    }
                    break;
                case 'game_over':
                    if (gameState && gameState.is_game_over) break;
                    if (data.players) gameState.players = data.players;
                    gameState.is_game_over = true;
                    gameState.winner_id = data.winner_id;
                    gameState.finish_reason = data.finish_reason;
                    currentRoom.status = data.finish_reason === 'ADMIN_CANCELLED' ? 'cancelled' : 'finished';
                    stopRound();
                    updateHud();
                    finishRoundPresentation(data);
                    break;
            }
        }

        async function fetchRoomState() {
            if (roomStateInFlight) return;
            roomStateInFlight = true;
            const generation = roundGeneration;
            try {
                const res = await fetch(`/api/rooms/${ROOM_CODE}/state`, { signal: AbortSignal.timeout(5000) });
                const data = await res.json();
                if (!res.ok || generation !== roundGeneration) return;
                if (data.game_state && gameState && data.game_state.started_at < gameState.started_at) return;
                if (data.game_state && gameState && data.game_state.round_id === gameState.round_id && data.game_state.version < lastProcessedVersion) return;
                currentRoom = data.room;
                currentRoom.players = data.players;
                applyStateSnapshot(data.game_state);
                if (data.server_time) syncServerTime(data.server_time);
                if (gameState && typeof gameState.version === 'number') {
                    lastProcessedVersion = Math.max(lastProcessedVersion, gameState.version);
                }
                updateLobbyView();
            } catch (err) {
                console.error(err);
            } finally {
                roomStateInFlight = false;
            }
        }

        function applyStateSnapshot(state) {
            if (!state) return;
            if (!gameState || state.round_id !== gameState.round_id) {
                handleIncomingEvent({ type: 'game_started', data: { state: { ...state, is_game_over: false }, rules: state.rules, duration: state.duration_seconds } });
                if (state.is_game_over && gameState.round_id === state.round_id) applyStateSnapshot(state);
                return;
            }
            if (state.version < lastProcessedVersion || (state.version === gameState.version && (state.tick ?? 0) < (gameState.tick ?? 0))) { diagnostics.staleStates++; return; }
            if (state.is_game_over && !gameState.is_game_over) {
                handleIncomingEvent({ type: 'game_over', data: { round_id: state.round_id, winner_id: state.winner_id, finish_reason: state.finish_reason, players: state.players } });
            }
            const previous = gameState.players?.[CURRENT_PLAYER_ID];
            const confirmed = state.players?.[CURRENT_PLAYER_ID];
            if (previous && confirmed && Math.abs(previous.x - confirmed.x) + Math.abs(previous.y - confirmed.y) > 1) diagnostics.corrections++;
            gameState = state;
            inputSequence = Math.max(inputSequence, state.input_sequences?.[CURRENT_PLAYER_ID] || 0);
            diagnostics.lastReceived = performance.now();
            lastProcessedVersion = state.version;
            updateHud();
        }

        function updateLobbyView() {
            const grid = document.getElementById('lobby-slots-grid');
            if (!grid) return;
            grid.innerHTML = '';

            myPlayer = currentRoom.players.find(p => p.player_id === CURRENT_PLAYER_ID);
            isHost = myPlayer && myPlayer.is_host;

            const colorMap = {
                'neon-blue': { border: 'border-cyan-400', text: 'text-cyan-400', bg: 'bg-cyan-950/40' },
                'neon-pink': { border: 'border-rose-400', text: 'text-rose-400', bg: 'bg-rose-950/40' },
                'neon-yellow': { border: 'border-amber-400', text: 'text-amber-400', bg: 'bg-amber-950/40' },
                'neon-green': { border: 'border-emerald-400', text: 'text-emerald-400', bg: 'bg-emerald-950/40' },
            };

            for (let i = 0; i < 4; i++) {
                const p = currentRoom.players[i];
                const card = document.createElement('div');
                card.className = 'p-3 rounded-2xl border flex flex-col items-center justify-between min-h-[140px] transition-all ';

                if (p) {
                    const c = colorMap[p.color] || colorMap['neon-blue'];
                    card.className += `${c.border} ${c.bg}`;
                    const avatarSVG = window.BomberEngine ? window.BomberEngine.getCharacterSVG(p.color, 54) : '';

                    card.innerHTML = `
                        <div class="text-[10px] uppercase font-bold tracking-widest ${c.text}">JUGADOR ${i + 1}</div>
                        <div class="my-1.5 flex items-center justify-center">
                            ${avatarSVG}
                        </div>
                        <div class="text-xs font-bold text-white truncate max-w-[110px]">${escapeHtml(p.nickname)}</div>
                        <div class="mt-1 text-[10px] font-bold ${p.is_ready ? 'text-emerald-400' : 'text-slate-400'}">
                            ${p.is_host ? '👑 ANFITRIÓN' : (p.is_ready ? '✓ LISTO' : '⏳ ESPERANDO')}
                        </div>
                    `;
                } else {
                    card.className += 'border-slate-800 bg-slate-900/40 text-slate-600 border-dashed';
                    card.innerHTML = `
                        <div class="text-[10px] uppercase font-bold tracking-widest text-slate-600">SLOT ${i + 1}</div>
                        <div class="w-12 h-12 rounded-full border border-slate-800 flex items-center justify-center text-sm my-2 text-slate-700 font-bold">
                            +
                        </div>
                        <div class="text-[10px] font-semibold text-slate-600">DISPONIBLE</div>
                    `;
                }
                grid.appendChild(card);
            }

            const readyBtnText = document.getElementById('ready-status-text');
            if (myPlayer && readyBtnText) {
                readyBtnText.innerText = myPlayer.is_ready ? 'SÍ' : 'NO';
                readyBtnText.className = myPlayer.is_ready ? 'text-emerald-400 font-black' : 'text-rose-400 font-black';
            }

            const startBtn = document.getElementById('btn-start-game');
            const warning = document.getElementById('lobby-warning');
            if (!isHost) {
                startBtn.classList.add('hidden');
            } else {
                startBtn.classList.remove('hidden');
                const canStart = currentRoom.players.length >= 2;
                startBtn.disabled = !canStart;
                if (!canStart) {
                    warning.innerText = 'Se requieren al menos 2 jugadores para comenzar la partida.';
                    warning.classList.remove('hidden');
                } else {
                    warning.classList.add('hidden');
                }
            }

            if (window.lucide && window.lucide.createIcons) {
                window.lucide.createIcons();
            }
        }

        async function toggleReady() {
            haptics.light();
            try {
                await fetch(`/api/rooms/${ROOM_CODE}/ready`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN }
                });
            } catch (err) {
                console.error(err);
            }
        }

        async function startGame() {
            haptics.heavy();
            const btn = document.getElementById('btn-start-game');
            btn.disabled = true;
            try {
                const res = await fetch(`/api/rooms/${ROOM_CODE}/start`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN }
                });
                const data = await res.json();
                if (!data.success) {
                    alert(data.error || 'No se pudo iniciar');
                    btn.disabled = false;
                } else {
                    handleIncomingEvent({ type: 'game_started', data: { state: data.state, server_time: data.server_time, rules: data.state.rules, duration: data.state.duration_seconds } });
                }
            } catch (err) {
                console.error(err);
                btn.disabled = false;
            }
        }

        function runCountdown(callback, countdownSecs = 3) {
            if (countdownInterval) clearInterval(countdownInterval);
            isCountingDown = countdownSecs > 0;
            const overlay = document.getElementById('countdown-overlay');
            const num = document.getElementById('countdown-number');
            if (countdownSecs <= 0) {
                if (overlay) overlay.classList.add('hidden');
                if (callback) callback();
                return;
            }
            overlay.classList.remove('hidden');
            let count = countdownSecs;
            num.innerText = count;
            if (sfx) sfx.playCountdownTick();

            countdownInterval = setInterval(() => {
                count--;
                if (count > 0) {
                    num.innerText = count;
                    haptics.light();
                    if (sfx) sfx.playCountdownTick();
                } else if (count === 0) {
                    num.innerText = '¡FUEGO!';
                    haptics.heavy();
                    if (sfx) sfx.playCountdownTick();
                } else {
                    clearInterval(countdownInterval);
                    countdownInterval = null;
                    isCountingDown = false;
                    overlay.classList.add('hidden');
                    if (callback) callback();
                }
            }, 800);
        }

        function startTimer() {
            if (timerInterval) clearInterval(timerInterval);
            const timerEl = document.getElementById('game-timer');
            
            function updateDisplay() {
                if (!timerEl) return;
                // If gameState has authoritative started_at and duration_seconds, compute precisely
                if (gameState && gameState.started_at && gameState.duration_seconds) {
                    const elapsed = getCurrentServerTime() - gameState.started_at;
                    remainingSeconds = Math.max(0, Math.ceil(gameState.duration_seconds - elapsed));
                }
                const mins = String(Math.floor(remainingSeconds / 60)).padStart(2, '0');
                const secs = String(remainingSeconds % 60).padStart(2, '0');
                timerEl.innerText = `${mins}:${secs}`;
            }

            updateDisplay();
            timerInterval = setInterval(() => {
                if (remainingSeconds > 0) {
                    updateDisplay();
                } else {
                    clearInterval(timerInterval);
                }
            }, 1000);
        }

        function updateHud() {
            const container = document.getElementById('hud-players');
            if (!container || !gameState || !gameState.players) return;
            container.innerHTML = '';

            Object.values(gameState.players).forEach(p => {
                const chip = document.createElement('div');
                chip.className = `flex items-center gap-1.5 px-2.5 py-1 rounded-xl text-[11px] border whitespace-nowrap ${p.alive ? 'bg-slate-900 border-slate-700 text-white' : 'bg-red-950/60 border-red-500/40 text-slate-500 opacity-60'}`;
                
                const miniAvatar = window.BomberEngine ? window.BomberEngine.getCharacterSVG(p.color, 18) : '';
                chip.innerHTML = `
                    <span class="inline-flex shrink-0">${miniAvatar}</span>
                    <span class="font-bold">${escapeHtml(p.nickname)}</span>
                    <span class="text-cyan-400 font-mono">(${p.kills}k)</span>
                `;
                container.appendChild(chip);
            });
        }

        // Keyboard Controls
        let lastMoveTime = 0;
        const heldDirections = new Map();
        let walkingDirection = null;
        let walkRefreshInterval = null;
        let visualPrediction = null;

        function previewStep(direction) {
            const me = gameState?.players?.[CURRENT_PLAYER_ID];
            const delta = { up: [0, -1], down: [0, 1], left: [-1, 0], right: [1, 0] }[direction];
            if (!me?.alive || !delta) return;
            const x = me.x + delta[0], y = me.y + delta[1];
            if (canMoveTo(x, y)) visualPrediction = { x, y, sequence: inputSequence + 1, expiresAt: performance.now() + 1000, acknowledgedTick: null };
        }

        function reconcileVisualPrediction() {
            if (!visualPrediction) return;
            const me = gameState?.players?.[CURRENT_PLAYER_ID];
            if (!me?.alive || gameState.is_game_over || performance.now() > visualPrediction.expiresAt || (me.x === visualPrediction.x && me.y === visualPrediction.y)) {
                visualPrediction = null;
                return;
            }
            if ((gameState.input_sequences?.[CURRENT_PLAYER_ID] || 0) >= visualPrediction.sequence) {
                visualPrediction.acknowledgedTick ??= gameState.tick || 0;
                if ((gameState.tick || 0) > visualPrediction.acknowledgedTick) visualPrediction = null;
            }
            if (visualPrediction && !canMoveTo(visualPrediction.x, visualPrediction.y)) visualPrediction = null;
        }

        function beginWalk(inputDirection) {
            if (!gameState?.players?.[CURRENT_PLAYER_ID]?.alive || currentRoom.status !== 'playing' || isCountingDown) return;
            const direction = gameRenderer ? gameRenderer.screenToLogicalDir(inputDirection) : inputDirection;
            if (walkingDirection === direction) return;
            walkingDirection = direction;
            previewStep(direction);
            enqueueAction({ type: 'move', direction });
            if (walkRefreshInterval) clearInterval(walkRefreshInterval);
            const repeatAfter = performance.now() + 300;
            walkRefreshInterval = setInterval(() => {
                if (walkingDirection && performance.now() >= repeatAfter && !isFlushingActions && pendingActionQueue.length === 0) {
                    previewStep(walkingDirection);
                    enqueueAction({ type: 'move', direction: walkingDirection, _repeat: true });
                }
            }, movementIntervalMs());
        }

        function endWalk() {
            if (walkRefreshInterval) clearInterval(walkRefreshInterval);
            walkRefreshInterval = null;
            pendingActionQueue = pendingActionQueue.filter(action => !action._repeat);
            walkingDirection = null;
        }

        function initKeyboard() {
            window.addEventListener('keydown', (e) => {
                if (!gameState || currentRoom.status !== 'playing') return;
                const now = performance.now();

                let dir = null;
                if (e.key === 'ArrowUp' || e.key === 'w' || e.key === 'W') dir = 'up';
                if (e.key === 'ArrowDown' || e.key === 's' || e.key === 'S') dir = 'down';
                if (e.key === 'ArrowLeft' || e.key === 'a' || e.key === 'A') dir = 'left';
                if (e.key === 'ArrowRight' || e.key === 'd' || e.key === 'D') dir = 'right';

                if (dir) {
                    e.preventDefault();
                    if (heldDirections.has(e.key)) return;
                    heldDirections.set(e.key, dir);
                    beginWalk(dir);
                } else if (e.code === 'Space') {
                    e.preventDefault();
                    sendBomb();
                }
            });
            window.addEventListener('keyup', e => {
                if (!heldDirections.delete(e.key)) return;
                const direction = [...heldDirections.values()].at(-1);
                if (direction) beginWalk(direction);
                else endWalk();
            });
            window.addEventListener('blur', () => { heldDirections.clear(); endWalk(); });
        }

        // Check if a tile can be stepped on locally
        function canMoveTo(x, y) {
            if (!gameState || !gameState.grid) return false;
            if (x < 0 || x >= 13 || y < 0 || y >= 11) return false;
            const tile = gameState.grid[y][x];
            if (tile === 1 || tile === 2) return false; // wall or block
            if (gameState.bombs) {
                for (const b of Object.values(gameState.bombs)) {
                    if (b.x === x && b.y === y) return false; // solid bomb
                }
            }
            // Living player collisions: players cannot occupy the same tile.
            // Dead/eliminated players do NOT block movement.
            if (gameState.players) {
                for (const [pid, p] of Object.entries(gameState.players)) {
                    if (pid !== CURRENT_PLAYER_ID && p.alive && p.x === x && p.y === y) {
                        return false;
                    }
                }
            }
            return true;
        }

        function movementIntervalMs() {
            const speed = Math.max(1, Math.min(3, gameState?.players?.[CURRENT_PLAYER_ID]?.speed || 1));
            return 125 - speed * 25;
        }

        // Bounded command pipeline; snapshots contain confirmed positions.
        let pendingActionQueue = [];
        let isFlushingActions = false;
        let actionRequestController = null;

        function enqueueAction(action) {
            if (action._repeat && (isFlushingActions || pendingActionQueue.length > 0)) return;
            if (pendingActionQueue.length >= 4) return;
            pendingActionQueue.push(action);
            flushActions();
        }

        async function flushActions() {
            if (isFlushingActions || pendingActionQueue.length === 0) return;
            isFlushingActions = true;

            const batch = [];
            while (pendingActionQueue.length > 0 && batch.length < 40) {
                if (pendingActionQueue[0].type === 'move' && batch.some(action => action.type === 'move')) break;
                batch.push(pendingActionQueue.shift());
            }
            const generation = roundGeneration;
            const roundId = gameState.round_id;
            const sequence = ++inputSequence;
            const requestStarted = performance.now();
            actionRequestController = new AbortController();

            try {
                const res = await fetch(`/api/rooms/${ROOM_CODE}/actions`, {
                    method: 'POST',
                    signal: AbortSignal.any([actionRequestController.signal, AbortSignal.timeout(5000)]),
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
                    body: JSON.stringify({ actions: batch.map(({ _repeat, ...action }) => action), round_id: roundId, sequence })
                });
                const data = await res.json();
                if (generation !== roundGeneration) return;
                diagnostics.rtt = performance.now() - requestStarted;
                batch.forEach(action => {
                    if (action.temp_id && gameState && gameState.bombs) delete gameState.bombs[action.temp_id];
                });
                if (data.server_time) syncServerTime(data.server_time);
                (data.events || []).forEach(handleIncomingEvent);
                applyStateSnapshot(data.state);
                if (data && data.success) {
                    if (data.bombs && data.bombs.length > 0 && gameState && gameState.bombs) {
                        data.bombs.forEach(b => {
                            if (b.temp_id && gameState.bombs[b.temp_id]) {
                                delete gameState.bombs[b.temp_id];
                            }
                            b.local_planted_at = performance.now();
                            if ((!data.version || data.version >= lastProcessedVersion) && !gameState.is_game_over && !detonatedBombIds.has(b.id)) {
                                gameState.bombs[b.id] = b;
                            }
                        });
                    }
                    // Compatibility with responses from servers without full snapshots.
                    if (!data.state && (!data.version || data.version >= lastProcessedVersion) && data.pos && pendingActionQueue.length === 0 && gameState && gameState.players) {
                        const me = gameState.players[CURRENT_PLAYER_ID];
                        if (me && me.alive) {
                            me.x = data.pos.x;
                            me.y = data.pos.y;
                        }
                    }
                } else if (data && !data.state && (!data.version || data.version >= lastProcessedVersion) && !data.success && data.pos && pendingActionQueue.length === 0 && gameState && gameState.players) {
                    const me = gameState.players[CURRENT_PLAYER_ID];
                    if (me && me.alive) {
                        me.x = data.pos.x;
                        me.y = data.pos.y;
                    }
                }
            } catch (err) {
                if (generation === roundGeneration && gameState && gameState.bombs) {
                    batch.forEach(action => { if (action.temp_id) delete gameState.bombs[action.temp_id]; });
                }
                console.warn('Network action dispatch:', err);
                if (generation === roundGeneration) fetchRoomState();
            } finally {
                if (generation === roundGeneration) {
                    isFlushingActions = false;
                    if (pendingActionQueue.length > 0) {
                        flushActions();
                    }
                }
            }
        }

        // Submit input without changing confirmed coordinates.
        function predictAndMove(inputDir) {
            if (!gameState || !gameState.players || currentRoom.status !== 'playing' || isCountingDown) return;
            const me = gameState.players[CURRENT_PLAYER_ID];
            if (!me || !me.alive) return;

            // Map visual screen direction (e.g. from vertical touch D-Pad) to logical grid direction
            const direction = gameRenderer ? gameRenderer.screenToLogicalDir(inputDir) : inputDir;

            let dx = 0, dy = 0;
            if (direction === 'up') dy = -1;
            else if (direction === 'down') dy = 1;
            else if (direction === 'left') dx = -1;
            else if (direction === 'right') dx = 1;

            const nextX = me.x + dx;
            const nextY = me.y + dy;

            // Turn player face towards direction
            me.direction = direction;
            me._movedAt = performance.now();

            if (canMoveTo(nextX, nextY)) {
                // Confirmed coordinates remain unchanged until the server resolves the tick.
                enqueueAction({ type: 'move', direction: direction });
            }
        }

        // Ergonomic Mobile Touch Controls with Haptics and Hold-to-walk
        function initTouchControls() {
            const directions = ['up', 'down', 'left', 'right'];

            directions.forEach(dir => {
                const btn = document.getElementById(`dpad-${dir}`);
                if (!btn) return;

                const startPress = (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    btn.classList.add('is-active');
                    haptics.light();
                    beginWalk(dir);
                };

                const endPress = (e) => {
                    e.preventDefault();
                    btn.classList.remove('is-active');
                    endWalk();
                };

                btn.addEventListener('touchstart', startPress, { passive: false });
                btn.addEventListener('touchend', endPress, { passive: false });
                btn.addEventListener('touchcancel', endPress, { passive: false });
                btn.addEventListener('mousedown', startPress);
                btn.addEventListener('mouseup', endPress);
                btn.addEventListener('mouseleave', endPress);
            });

            // Bomb Button Press
            const bombBtn = document.getElementById('dpad-bomb');
            if (bombBtn) {
                const triggerBomb = (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    bombBtn.classList.add('is-active');
                    sendBomb();
                };
                const releaseBomb = (e) => {
                    e.preventDefault();
                    bombBtn.classList.remove('is-active');
                };

                bombBtn.addEventListener('touchstart', triggerBomb, { passive: false });
                bombBtn.addEventListener('touchend', releaseBomb, { passive: false });
                bombBtn.addEventListener('touchcancel', releaseBomb, { passive: false });
                bombBtn.addEventListener('mousedown', triggerBomb);
                bombBtn.addEventListener('mouseup', releaseBomb);
                bombBtn.addEventListener('mouseleave', releaseBomb);
            }
        }

        function sendBomb() {
            if (!gameState || !gameState.players || currentRoom.status !== 'playing' || isCountingDown) return;
            const me = gameState.players[CURRENT_PLAYER_ID];
            if (!me || !me.alive) return;

            // Check active bomb count limit locally
            let activeBombs = 0;
            if (gameState.bombs) {
                for (const b of Object.values(gameState.bombs)) {
                    if (b.owner_id === CURRENT_PLAYER_ID) activeBombs++;
                }
            }
            const maxAllowed = me.max_bombs || 1;
            if (activeBombs >= maxAllowed) {
                haptics.light();
                return;
            }

            // Check if there is already a bomb on player's current tile
            if (gameState.bombs) {
                for (const b of Object.values(gameState.bombs)) {
                    if (b.x === me.x && b.y === me.y) {
                        return;
                    }
                }
            }

            // Squash recoil visual effect
            me.isPlacingBomb = true;
            setTimeout(() => { me.isPlacingBomb = false; }, 180);

            // Correlate the command with its confirmation.
            const tempId = 'opt_' + Date.now() + '_' + Math.floor(Math.random() * 1000);

            if (sfx) sfx.playBombDrop();
            haptics.heavy();

            // Enqueue bomb action in batch pipeline
            enqueueAction({ type: 'bomb', temp_id: tempId });
        }

        async function requestRematch() {
            if (rematchInFlight || !canRequestRematch()) return;
            rematchInFlight = true;
            haptics.heavy();
            try {
                const res = await fetch(`/api/rooms/${ROOM_CODE}/rematch`, {
                    method: 'POST',
                    signal: AbortSignal.timeout(10000),
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
                    body: JSON.stringify({ round_id: gameState?.round_id })
                });
                const data = await res.json();
                if (!res.ok || !data.success) {
                    alert(data.error || 'No se pudo iniciar la revancha');
                    return;
                }
                handleIncomingEvent({ type: 'game_started', data: { state: data.state, server_time: data.server_time, rules: data.state.rules, duration: data.state.duration_seconds } });
            } catch (err) {
                console.error(err);
                alert('No se pudo conectar para iniciar la revancha. Intenta nuevamente.');
            } finally {
                rematchInFlight = false;
            }
        }

        function canRequestRematch() {
            return gameState?.is_game_over && !!gameState.winner_id
                && gameState.winner_id !== CURRENT_PLAYER_ID
                && !!gameState.players?.[CURRENT_PLAYER_ID]
                && gameState.rules?.allow_rematch !== false;
        }

        function finishRoundPresentation(data) {
            const generation = roundGeneration;
            const waitForExplosion = ['LAST_PLAYER_ALIVE', 'ALL_PLAYERS_ELIMINATED'].includes(data.finish_reason);
            const revealAfter = performance.now() + (waitForExplosion && gameRenderer ? 550 : 0);
            const present = () => {
                if (generation !== roundGeneration) return;
                if (gameRenderer) {
                    gameRenderer.render(gameState, CURRENT_PLAYER_ID, performance.now() / 1000, getCurrentServerTime());
                }
                const explosionsActive = gameRenderer?.activeExplosions?.some(effect => !effect.isFinished());
                if (performance.now() < revealAfter || explosionsActive) {
                    animFrameId = requestAnimationFrame(present);
                    return;
                }
                animFrameId = null;
                showGameOverModal(data);
                if (data.winner_id === CURRENT_PLAYER_ID) {
                    if (sfx) sfx.playVictory();
                    haptics.victory();
                }
            };
            present();
        }

        function showGameOverModal(data) {
            const modal = document.getElementById('gameover-modal');
            const title = document.getElementById('gameover-title');
            const subtitle = document.getElementById('gameover-subtitle');
            const stats = document.getElementById('gameover-stats');
            const podium = document.getElementById('gameover-avatar-podium');

            modal.classList.remove('hidden');
            document.getElementById('rematch-button').classList.toggle('hidden', !canRequestRematch());

            const reason = data.finish_reason || 'UNKNOWN';

            if (data.winner_id && gameState && gameState.players[data.winner_id]) {
                const winner = gameState.players[data.winner_id];
                const isWinner = data.winner_id === CURRENT_PLAYER_ID;
                title.innerText = isWinner ? '¡VICTORIA!' : 'DERROTA';
                title.className = `arcade-font text-3xl sm:text-5xl font-black mb-1 ${isWinner ? 'text-amber-400' : 'text-rose-400'}`;
                subtitle.innerText = isWinner ? '¡Ganaste la ronda!' : `${winner.nickname} ganó la ronda. Podés solicitar revancha.`;
                if (reason === 'TIME_EXPIRED') {
                    const criterion = gameState.rules?.timeout_result === 'most_kills' ? 'bajas' : 'bombas colocadas';
                    subtitle.innerText = isWinner
                        ? `¡Ganaste por mayor cantidad de ${criterion}!`
                        : `${winner.nickname} ganó por mayor cantidad de ${criterion}.`;
                }
                if (podium && window.BomberEngine) {
                    podium.innerHTML = window.BomberEngine.getCharacterSVG(winner.color, 84);
                }
            } else if (reason === 'TIME_EXPIRED') {
                title.innerText = '¡TIEMPO AGOTADO!';
                title.className = 'arcade-font text-3xl sm:text-5xl font-black text-amber-400 mb-1';
                subtitle.innerText = gameState.rules?.timeout_result === 'most_kills'
                    ? 'Tiempo agotado: empate en la mayor cantidad de bajas.'
                    : 'Tiempo agotado: empate en la mayor cantidad de bombas colocadas.';
                if (podium) podium.innerHTML = '';
            } else if (reason === 'ADMIN_CANCELLED') {
                title.innerText = 'PARTIDA CANCELADA';
                title.className = 'arcade-font text-3xl sm:text-5xl font-black text-rose-400 mb-1';
                subtitle.innerText = 'La partida fue cancelada desde la consola de administración';
                if (podium) podium.innerHTML = '';
            } else {
                title.innerText = '¡EMPATE!';
                title.className = 'arcade-font text-3xl sm:text-5xl font-black text-slate-300 mb-1';
                subtitle.innerText = 'Ningún combatiente sobrevivió a la detonación';
                if (podium) podium.innerHTML = '';
            }

            stats.innerHTML = '<div class="font-bold text-[10px] uppercase text-slate-400 mb-2 border-b border-slate-700 pb-1 flex justify-between"><span>Combatiente</span><span>Bajas / Bombas</span></div>';
            if (data.players) {
                Object.values(data.players).forEach(p => {
                    const row = document.createElement('div');
                    row.className = 'flex justify-between items-center py-1.5 border-b border-slate-800 text-slate-300';
                    const avatarMini = window.BomberEngine ? window.BomberEngine.getCharacterSVG(p.color, 24) : '';
                    row.innerHTML = `
                        <div class="flex items-center gap-2">
                            <span>${avatarMini}</span>
                            <span class="font-bold">${p.id === data.winner_id ? '👑' : (p.alive ? '' : '💀')} ${escapeHtml(p.nickname)}</span>
                        </div>
                        <span class="font-mono text-cyan-400 font-semibold">${p.kills} bajas &bull; ${p.bombs_placed} bombas</span>
                    `;
                    stats.appendChild(row);
                });
            }

            if (window.lucide && window.lucide.createIcons) {
                window.lucide.createIcons();
            }
        }

        // Canvas Rendering Loop via Modular GameRenderer
        let animFrameId = null;
        let lastServerTickTime = 0;
        let serverTickInFlight = false;
        let serverTickInterval = null;
        let tickRequestController = null;

        function stopRound() {
            visualPrediction = null;
            if (gameRenderer) gameRenderer.localPreview = null;
            if (walkRefreshInterval) clearInterval(walkRefreshInterval);
            walkRefreshInterval = null;
            walkingDirection = null;
            heldDirections.clear();
            if (animFrameId) cancelAnimationFrame(animFrameId);
            animFrameId = null;
            if (timerInterval) clearInterval(timerInterval);
            if (countdownInterval) clearInterval(countdownInterval);
            if (serverTickInterval) clearInterval(serverTickInterval);
            timerInterval = null;
            countdownInterval = null;
            serverTickInterval = null;
            isCountingDown = false;
            pendingActionQueue = [];
            document.getElementById('countdown-overlay').classList.add('hidden');
        }

        function startGameLoop() {
            startTimer();
            updateHud();
            if (animFrameId) cancelAnimationFrame(animFrameId);
            if (serverTickInterval) clearInterval(serverTickInterval);
            serverTickInterval = setInterval(() => {
                if (!gameState || gameState.is_game_over || currentRoom.status !== 'playing') return;
                const now = performance.now();
                const tickInterval = 1000;
                if (now - lastServerTickTime >= tickInterval) {
                    lastServerTickTime = now;
                    sendServerTick();
                }
            }, 100);

            function loop() {
                if (!gameState || gameState.is_game_over || currentRoom.status !== 'playing') return;
                const curServerTime = getCurrentServerTime();

                if (gameRenderer) {
                    reconcileVisualPrediction();
                    gameRenderer.localPreview = visualPrediction ? { id: CURRENT_PLAYER_ID, x: visualPrediction.x, y: visualPrediction.y } : null;
                    gameRenderer.diagnostics = diagnosticsEnabled ? { ...diagnostics, room: ROOM_CODE } : null;
                    gameRenderer.render(gameState, CURRENT_PLAYER_ID, performance.now() / 1000, curServerTime);
                }
                animFrameId = requestAnimationFrame(loop);
            }
            loop();
        }

        async function sendServerTick() {
            if (serverTickInFlight || !gameState || gameState.is_game_over) return;
            serverTickInFlight = true;
            const generation = roundGeneration;
            const requestStarted = performance.now();
            tickRequestController = new AbortController();
            try {
                const res = await fetch(`/api/rooms/${ROOM_CODE}/tick`, {
                    method: 'POST',
                    signal: AbortSignal.any([tickRequestController.signal, AbortSignal.timeout(5000)]),
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
                    body: JSON.stringify({ round_id: gameState.round_id })
                });
                const data = await res.json();
                if (generation !== roundGeneration) return;
                diagnostics.rtt = performance.now() - requestStarted;
                if (data.server_time) syncServerTime(data.server_time);
                (data.events || []).forEach(handleIncomingEvent);
                applyStateSnapshot(data.state);
            } catch (err) {}
            finally {
                if (generation === roundGeneration) serverTickInFlight = false;
            }
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.innerText = text;
            return div.innerHTML;
        }
    </script>
</body>
</html>
