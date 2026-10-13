<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Sala {{ $room->code }} — Bomber Arena</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@600;800;900&family=Rajdhani:wght@600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #060814;
            --neon-cyan: #00f0ff;
            --neon-pink: #ff007f;
            --neon-yellow: #ffe600;
            --neon-green: #00ff66;
            --card-bg: rgba(13, 17, 38, 0.9);
            --border-glow: rgba(0, 240, 255, 0.3);
        }
        * {
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }
        body {
            background: #050711;
            margin: 0;
            font-family: 'Rajdhani', sans-serif;
            color: #f1f5f9;
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
            box-shadow: 0 0 30px rgba(0, 240, 255, 0.25);
            border: 2px solid rgba(0, 240, 255, 0.4);
            border-radius: 12px;
            overflow: hidden;
            background: #02040a;
            width: 100%;
            max-width: 650px;
            aspect-ratio: 13 / 11;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: center;
            touch-action: none;
        }
        #game-canvas {
            width: 100%;
            height: auto;
            display: block;
            aspect-ratio: 13 / 11;
            object-fit: contain;
        }

        /* Ergonomic Cyberpunk Mobile Controls */
        .gamepad-container {
            width: 100%;
            max-width: 650px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 16px;
            margin-top: 6px;
            touch-action: none;
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
            background: linear-gradient(145deg, rgba(30, 41, 59, 0.9), rgba(15, 23, 42, 0.95));
            border: 1.5px solid rgba(0, 240, 255, 0.35);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: #00f0ff;
            user-select: none;
            cursor: pointer;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.4), inset 0 1px 0 rgba(255, 255, 255, 0.1);
            transition: transform 0.08s ease, background-color 0.08s ease, box-shadow 0.08s ease;
        }
        .touch-pad-btn:active, .touch-pad-btn.is-active {
            transform: scale(0.90);
            background: rgba(0, 240, 255, 0.35);
            border-color: #00f0ff;
            box-shadow: 0 0 16px rgba(0, 240, 255, 0.7), inset 0 2px 6px rgba(0, 0, 0, 0.6);
            color: #ffffff;
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

        /* Giant Tactile Bomb Action Button */
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
            background: radial-gradient(circle at 35% 35%, #ff2a8d 0%, #b30059 70%, #660033 100%);
            border: 3px solid #ff007f;
            box-shadow: 0 0 22px rgba(255, 0, 127, 0.55), inset 0 2px 6px rgba(255, 255, 255, 0.4);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 34px;
            color: #ffffff;
            cursor: pointer;
            transition: transform 0.08s ease, box-shadow 0.08s ease;
            position: relative;
        }
        @media (min-width: 480px) {
            .bomb-touch-btn {
                width: 86px;
                height: 86px;
                font-size: 38px;
            }
        }
        .bomb-touch-btn:active, .bomb-touch-btn.is-active {
            transform: scale(0.88);
            box-shadow: 0 0 35px rgba(255, 0, 127, 0.9), inset 0 4px 12px rgba(0, 0, 0, 0.8);
            background: radial-gradient(circle at 50% 50%, #ff007f 0%, #800040 100%);
        }
        .bomb-label {
            font-family: 'Orbitron', monospace;
            font-size: 10px;
            font-weight: 700;
            color: #ff007f;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .glass-panel {
            background: rgba(15, 23, 42, 0.85);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.12);
        }
        .pulse-text {
            animation: pulseText 1.5s infinite;
        }
        @keyframes pulseText {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
    </style>
</head>
<body class="p-1 sm:p-3">

    <!-- Top Bar Navigation & Info (Compact on mobile) -->
    <header class="w-full max-w-5xl mx-auto flex items-center justify-between py-1.5 px-3 rounded-xl glass-panel mb-2">
        <div class="flex items-center gap-2">
            <a href="{{ route('lobby') }}" class="text-xs uppercase font-bold text-slate-400 hover:text-cyan-400 transition-colors">
                ← Lobby
            </a>
            <div class="h-3 w-px bg-slate-700"></div>
            <div class="arcade-font text-xs sm:text-sm font-bold text-cyan-400 flex items-center gap-1.5">
                <span>SALA:</span>
                <span class="bg-cyan-950 px-2 py-0.5 rounded border border-cyan-500/40 text-white tracking-widest" id="header-room-code">{{ $room->code }}</span>
                <button type="button" onclick="copyCode()" class="text-xs text-slate-400 hover:text-white" title="Copiar código">📋</button>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <div class="flex items-center gap-1.5 text-[11px] font-semibold px-2 py-0.5 rounded-full bg-slate-900 border border-slate-700">
                <span class="w-2 h-2 rounded-full bg-emerald-400" id="arena-ws-dot"></span>
                <span id="arena-ws-status" class="hidden sm:inline">Reverb Conectado</span>
            </div>
            <!-- Haptic Vibration Toggle -->
            <button type="button" id="btn-toggle-haptics" onclick="toggleHaptics()" class="px-2 py-0.5 rounded bg-slate-800 text-[11px] text-cyan-300 border border-slate-700" title="Vibración Háptica">
                📳 VIB ON
            </button>
            <!-- Audio Mute Button -->
            <button type="button" id="btn-toggle-audio" onclick="toggleAudio()" class="px-2 py-0.5 rounded bg-slate-800 text-[11px] text-slate-300 hover:text-white border border-slate-700" title="Silenciar / Activar sonido">
                🔊
            </button>
        </div>
    </header>

    <!-- MAIN GAME AREA WRAPPER -->
    <main class="w-full max-w-5xl mx-auto flex-1 flex flex-col items-center justify-center relative">

        <!-- LOBBY WAITING SCREEN (Shown before game start) -->
        <div id="screen-lobby" class="w-full max-w-2xl glass-panel p-4 sm:p-8 rounded-2xl border border-cyan-500/30 text-center {{ $room->status === 'playing' ? 'hidden' : '' }}">
            <h2 class="arcade-font text-2xl sm:text-3xl font-black text-white mb-1 tracking-wide">
                SALA DE ESPERA <span class="text-cyan-400">[{{ $room->code }}]</span>
            </h2>
            <p class="text-slate-400 text-xs sm:text-sm mb-5">Comparte el código con tus contrincantes (2 a 4 jugadores).</p>

            <!-- Slots Grid -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6" id="lobby-slots-grid">
                <!-- Dynamically rendered slots -->
            </div>

            <!-- Action buttons -->
            <div class="flex flex-col sm:flex-row gap-3 justify-center">
                <button type="button" id="btn-ready-toggle" onclick="toggleReady()"
                    class="arcade-font px-5 py-3 rounded-xl font-bold text-xs sm:text-sm tracking-wider uppercase bg-slate-800 hover:bg-slate-700 border border-slate-600 text-slate-200 transition-all">
                    LISTO: <span id="ready-status-text" class="text-red-400">NO</span>
                </button>

                <button type="button" id="btn-start-game" onclick="startGame()"
                    class="arcade-font px-6 py-3 rounded-xl font-black text-xs sm:text-sm tracking-wider uppercase bg-gradient-to-r from-cyan-400 to-blue-600 text-slate-950 shadow-lg shadow-cyan-500/30 hover:shadow-cyan-500/60 transition-all disabled:opacity-40 disabled:pointer-events-none">
                    🚀 INICIAR PARTIDA
                </button>
            </div>
            <div id="lobby-warning" class="text-xs text-amber-400 mt-3 font-semibold hidden"></div>
        </div>

        <!-- PLAYING SCREEN -->
        <div id="screen-game" class="w-full flex flex-col items-center {{ $room->status === 'playing' ? '' : 'hidden' }}">
            
            <!-- In-game HUD (Compact) -->
            <div class="w-full max-w-[650px] flex items-center justify-between mb-1.5 px-1 text-[11px] font-bold uppercase tracking-wider">
                <div class="flex items-center gap-1.5 overflow-x-auto" id="hud-players">
                    <!-- Dynamic player chips -->
                </div>
                <div class="flex items-center gap-1.5 bg-slate-900/90 px-2 py-0.5 rounded-lg border border-cyan-500/30 shrink-0">
                    <span class="text-slate-400">⏱️</span>
                    <span class="arcade-font text-cyan-400 text-sm" id="game-timer">03:00</span>
                </div>
            </div>

            <!-- Responsive Canvas Container -->
            <div class="canvas-container">
                <canvas id="game-canvas" width="650" height="550"></canvas>

                <!-- Countdown Overlay -->
                <div id="countdown-overlay" class="absolute inset-0 bg-black/70 backdrop-blur-sm flex items-center justify-center hidden z-20">
                    <div class="arcade-font text-6xl md:text-9xl font-black text-cyan-400 pulse-text" id="countdown-number">3</div>
                </div>

                <!-- Match Over Modal -->
                <div id="gameover-modal" class="absolute inset-0 bg-black/85 backdrop-blur-md flex flex-col items-center justify-center p-4 text-center hidden z-30">
                    <h3 class="arcade-font text-3xl sm:text-5xl font-black text-white mb-1" id="gameover-title">¡VICTORIA!</h3>
                    <p class="text-sm sm:text-base text-cyan-400 font-semibold mb-4" id="gameover-subtitle">Piloto 1 es el último sobreviviente</p>

                    <!-- Scoreboard -->
                    <div class="w-full max-w-sm bg-slate-900/80 border border-slate-700 rounded-xl p-3 mb-4 text-xs" id="gameover-stats">
                        <!-- Stats rows -->
                    </div>

                    <div class="flex gap-3">
                        <button type="button" onclick="requestRematch()" class="arcade-font px-5 py-2.5 rounded-xl bg-cyan-400 text-slate-950 font-black text-xs uppercase tracking-wider shadow-lg shadow-cyan-400/40 hover:bg-cyan-300">
                            🔄 REVANCHA
                        </button>
                        <a href="{{ route('lobby') }}" class="arcade-font px-5 py-2.5 rounded-xl bg-slate-800 text-slate-200 font-bold text-xs uppercase tracking-wider hover:bg-slate-700">
                            LOBBY
                        </a>
                    </div>
                </div>
            </div>

            <!-- Ergonomic Touch Controls for Mobile (D-Pad + Haptic Bomb Button) -->
            <div class="gamepad-container md:hidden" id="mobile-controls">
                
                <!-- Directional D-Pad -->
                <div class="dpad-cluster">
                    <div></div>
                    <button type="button" id="dpad-up" class="touch-pad-btn" aria-label="Arriba">▲</button>
                    <div></div>
                    <button type="button" id="dpad-left" class="touch-pad-btn" aria-label="Izquierda">◀</button>
                    <div class="dpad-center">●</div>
                    <button type="button" id="dpad-right" class="touch-pad-btn" aria-label="Derecha">▶</button>
                    <div></div>
                    <button type="button" id="dpad-down" class="touch-pad-btn" aria-label="Abajo">▼</button>
                    <div></div>
                </div>

                <!-- Giant Bomb Button with Haptics -->
                <div class="bomb-cluster">
                    <button type="button" id="dpad-bomb" class="bomb-touch-btn" aria-label="Colocar Bomba">
                        💣
                    </button>
                    <span class="bomb-label">BOMBA</span>
                </div>

            </div>

        </div>

    </main>

    <!-- Haptic Vibration Feedback Engine -->
    <script>
        class HapticEngine {
            constructor() {
                this.enabled = true;
                this.supported = 'vibrate' in navigator;
            }
            toggle() {
                this.enabled = !this.enabled;
                const btn = document.getElementById('btn-toggle-haptics');
                if (btn) {
                    btn.innerText = this.enabled ? '📳 VIB ON' : '📴 VIB OFF';
                    btn.className = this.enabled 
                        ? 'px-2 py-0.5 rounded bg-slate-800 text-[11px] text-cyan-300 border border-slate-700' 
                        : 'px-2 py-0.5 rounded bg-slate-800 text-[11px] text-slate-500 border border-slate-700';
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

    <!-- Retro Audio Synthesizer (Web Audio API) -->
    <script>
        class SoundFX {
            constructor() {
                this.ctx = null;
                this.enabled = true;
            }
            init() {
                if (!this.ctx) {
                    const AudioContext = window.AudioContext || window.webkitAudioContext;
                    if (AudioContext) this.ctx = new AudioContext();
                }
            }
            playBombDrop() {
                if (!this.enabled) return;
                this.init();
                if (!this.ctx) return;
                const osc = this.ctx.createOscillator();
                const gain = this.ctx.createGain();
                osc.type = 'sine';
                osc.frequency.setValueAtTime(440, this.ctx.currentTime);
                osc.frequency.exponentialRampToValueAtTime(120, this.ctx.currentTime + 0.15);
                gain.gain.setValueAtTime(0.3, this.ctx.currentTime);
                gain.gain.linearRampToValueAtTime(0.01, this.ctx.currentTime + 0.15);
                osc.connect(gain);
                gain.connect(this.ctx.destination);
                osc.start();
                osc.stop(this.ctx.currentTime + 0.15);
            }
            playExplosion() {
                if (!this.enabled) return;
                this.init();
                if (!this.ctx) return;
                const bufferSize = this.ctx.sampleRate * 0.4;
                const buffer = this.ctx.createBuffer(1, bufferSize, this.ctx.sampleRate);
                const data = buffer.getChannelData(0);
                for (let i = 0; i < bufferSize; i++) {
                    data[i] = Math.random() * 2 - 1;
                }
                const noise = this.ctx.createBufferSource();
                noise.buffer = buffer;
                const filter = this.ctx.createBiquadFilter();
                filter.type = 'lowpass';
                filter.frequency.setValueAtTime(800, this.ctx.currentTime);
                filter.frequency.linearRampToValueAtTime(80, this.ctx.currentTime + 0.4);
                const gain = this.ctx.createGain();
                gain.gain.setValueAtTime(0.5, this.ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.01, this.ctx.currentTime + 0.4);
                noise.connect(filter);
                filter.connect(gain);
                gain.connect(this.ctx.destination);
                noise.start();
            }
            playPowerup() {
                if (!this.enabled) return;
                this.init();
                if (!this.ctx) return;
                const osc = this.ctx.createOscillator();
                const gain = this.ctx.createGain();
                osc.type = 'triangle';
                osc.frequency.setValueAtTime(300, this.ctx.currentTime);
                osc.frequency.linearRampToValueAtTime(880, this.ctx.currentTime + 0.2);
                gain.gain.setValueAtTime(0.25, this.ctx.currentTime);
                gain.gain.linearRampToValueAtTime(0.01, this.ctx.currentTime + 0.2);
                osc.connect(gain);
                gain.connect(this.ctx.destination);
                osc.start();
                osc.stop(this.ctx.currentTime + 0.2);
            }
            playElimination() {
                if (!this.enabled) return;
                this.init();
                if (!this.ctx) return;
                const osc = this.ctx.createOscillator();
                const gain = this.ctx.createGain();
                osc.type = 'sawtooth';
                osc.frequency.setValueAtTime(250, this.ctx.currentTime);
                osc.frequency.linearRampToValueAtTime(70, this.ctx.currentTime + 0.3);
                gain.gain.setValueAtTime(0.3, this.ctx.currentTime);
                gain.gain.linearRampToValueAtTime(0.01, this.ctx.currentTime + 0.3);
                osc.connect(gain);
                gain.connect(this.ctx.destination);
                osc.start();
                osc.stop(this.ctx.currentTime + 0.3);
            }
        }
        const sfx = new SoundFX();
        function toggleAudio() {
            sfx.enabled = !sfx.enabled;
            document.getElementById('btn-toggle-audio').innerText = sfx.enabled ? '🔊' : '🔇';
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
        let particles = [];
        let timerInterval = null;
        let remainingSeconds = {{ $room->round_duration_seconds }};

        const canvas = document.getElementById('game-canvas');
        const ctx = canvas.getContext('2d');
        const TILE_SIZE = 50; // 13 cols * 50 = 650px, 11 rows * 50 = 550px

        document.addEventListener('DOMContentLoaded', () => {
            initEcho();
            updateLobbyView();
            initKeyboard();
            initTouchControls();
            if (currentRoom.status === 'playing' && gameState) {
                startGameLoop();
            }
        });

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
        }

        function handleIncomingEvent(e) {
            console.log('Evento de sala recibido:', e);
            const { type, data } = e;

            switch (type) {
                case 'player_joined':
                    fetchRoomState();
                    break;
                case 'player_ready':
                    fetchRoomState();
                    break;
                case 'game_started':
                    gameState = data.state;
                    currentRoom.status = 'playing';
                    remainingSeconds = data.duration || 180;
                    document.getElementById('screen-lobby').classList.add('hidden');
                    document.getElementById('screen-game').classList.remove('hidden');
                    document.getElementById('gameover-modal').classList.add('hidden');
                    haptics.heavy();
                    runCountdown(() => {
                        startGameLoop();
                    });
                    break;
                case 'player_moved':
                    if (gameState && gameState.players[data.player_id]) {
                        // If it is another player, update them immediately
                        if (data.player_id !== CURRENT_PLAYER_ID) {
                            gameState.players[data.player_id].x = data.x;
                            gameState.players[data.player_id].y = data.y;
                            gameState.players[data.player_id].direction = data.direction;
                        } else {
                            // Reconcile our own position if server corrected us
                            const me = gameState.players[CURRENT_PLAYER_ID];
                            if (me && (Math.abs(me.x - data.x) > 1 || Math.abs(me.y - data.y) > 1)) {
                                me.x = data.x;
                                me.y = data.y;
                            }
                        }

                        if (data.collected_powerup) {
                            sfx.playPowerup();
                            if (data.player_id === CURRENT_PLAYER_ID) {
                                haptics.heavy();
                            }
                            delete gameState.powerups[`${data.x}_${data.y}`];
                        }
                    }
                    break;
                case 'bomb_placed':
                    if (gameState) {
                        gameState.bombs[data.bomb.id] = data.bomb;
                        sfx.playBombDrop();
                        // Local fuse countdown to detonate
                        setTimeout(() => {
                            triggerDetonation(data.bomb.id);
                        }, 2000);
                    }
                    break;
                case 'bomb_exploded':
                    if (gameState) {
                        delete gameState.bombs[data.root_bomb_id];
                        sfx.playExplosion();
                        haptics.explosion();

                        // Destroy blocks
                        data.destroyed_blocks.forEach(b => {
                            gameState.grid[b.y][b.x] = 0;
                            spawnExplosionParticles(b.x * TILE_SIZE + 25, b.y * TILE_SIZE + 25, '#ff007f');
                        });

                        // Spawn power-ups
                        data.spawned_powerups.forEach(pu => {
                            gameState.powerups[`${pu.x}_${pu.y}`] = pu.type;
                        });

                        // Explosion tiles particles
                        data.explosion_tiles.forEach(t => {
                            spawnExplosionParticles(t.x * TILE_SIZE + 25, t.y * TILE_SIZE + 25, '#ffe600');
                        });

                        // Eliminations
                        data.eliminated_players.forEach(p => {
                            if (gameState.players[p.player_id]) {
                                gameState.players[p.player_id].alive = false;
                            }
                            sfx.playElimination();
                            if (p.player_id === CURRENT_PLAYER_ID) {
                                haptics.explosion();
                            }
                        });

                        updateHud();
                    }
                    break;
                case 'game_over':
                    showGameOverModal(data);
                    haptics.victory();
                    break;
            }
        }

        async function fetchRoomState() {
            try {
                const res = await fetch(`/api/rooms/${ROOM_CODE}/state`);
                const data = await res.json();
                currentRoom = data.room;
                currentRoom.players = data.players;
                gameState = data.game_state;
                updateLobbyView();
            } catch (err) {
                console.error(err);
            }
        }

        function updateLobbyView() {
            const grid = document.getElementById('lobby-slots-grid');
            grid.innerHTML = '';

            myPlayer = currentRoom.players.find(p => p.player_id === CURRENT_PLAYER_ID);
            isHost = myPlayer && myPlayer.is_host;

            const colorMap = {
                'neon-blue': { border: 'border-cyan-400', text: 'text-cyan-400', bg: 'bg-cyan-950/40' },
                'neon-pink': { border: 'border-pink-500', text: 'text-pink-500', bg: 'bg-pink-950/40' },
                'neon-yellow': { border: 'border-yellow-400', text: 'text-yellow-400', bg: 'bg-yellow-950/40' },
                'neon-green': { border: 'border-emerald-400', text: 'text-emerald-400', bg: 'bg-emerald-950/40' },
            };

            for (let i = 0; i < 4; i++) {
                const p = currentRoom.players[i];
                const card = document.createElement('div');
                card.className = 'p-3 rounded-xl border flex flex-col items-center justify-between min-h-[120px] transition-all ';

                if (p) {
                    const c = colorMap[p.color] || colorMap['neon-blue'];
                    card.className += `${c.border} ${c.bg}`;
                    card.innerHTML = `
                        <div class="text-[10px] uppercase font-bold tracking-widest ${c.text}">JUGADOR ${i + 1}</div>
                        <div class="my-1.5">
                            <div class="w-10 h-10 rounded-full border-2 ${c.border} flex items-center justify-center text-lg font-black bg-slate-900">
                                🤖
                            </div>
                        </div>
                        <div class="text-xs font-bold text-white truncate max-w-[100px]">${escapeHtml(p.nickname)}</div>
                        <div class="mt-1.5 text-[10px] font-semibold ${p.is_ready ? 'text-emerald-400' : 'text-slate-400'}">
                            ${p.is_host ? '👑 ANFITRIÓN' : (p.is_ready ? '✓ LISTO' : '⏳ ESPERA')}
                        </div>
                    `;
                } else {
                    card.className += 'border-slate-800 bg-slate-900/40 text-slate-600 border-dashed';
                    card.innerHTML = `
                        <div class="text-[10px] uppercase font-bold tracking-widest text-slate-600">SLOT ${i + 1}</div>
                        <div class="w-10 h-10 rounded-full border border-slate-800 flex items-center justify-center text-sm my-1.5 text-slate-700">
                            +
                        </div>
                        <div class="text-[10px] font-semibold text-slate-600">LIBRE</div>
                    `;
                }
                grid.appendChild(card);
            }

            const readyBtnText = document.getElementById('ready-status-text');
            if (myPlayer) {
                readyBtnText.innerText = myPlayer.is_ready ? 'SÍ' : 'NO';
                readyBtnText.className = myPlayer.is_ready ? 'text-emerald-400' : 'text-red-400';
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
                    warning.innerText = 'Se requieren al menos 2 jugadores para comenzar.';
                    warning.classList.remove('hidden');
                } else {
                    warning.classList.add('hidden');
                }
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
                }
            } catch (err) {
                console.error(err);
                btn.disabled = false;
            }
        }

        function runCountdown(callback) {
            const overlay = document.getElementById('countdown-overlay');
            const num = document.getElementById('countdown-number');
            overlay.classList.remove('hidden');
            let count = 3;
            num.innerText = count;

            const interval = setInterval(() => {
                count--;
                if (count > 0) {
                    num.innerText = count;
                    haptics.light();
                } else if (count === 0) {
                    num.innerText = '¡FUEGO!';
                    haptics.heavy();
                } else {
                    clearInterval(interval);
                    overlay.classList.add('hidden');
                    if (callback) callback();
                }
            }, 800);
        }

        function startTimer() {
            if (timerInterval) clearInterval(timerInterval);
            const timerEl = document.getElementById('game-timer');
            timerInterval = setInterval(() => {
                if (remainingSeconds > 0) {
                    remainingSeconds--;
                    const mins = String(Math.floor(remainingSeconds / 60)).padStart(2, '0');
                    const secs = String(remainingSeconds % 60).padStart(2, '0');
                    timerEl.innerText = `${mins}:${secs}`;
                } else {
                    clearInterval(timerInterval);
                }
            }, 1000);
        }

        function updateHud() {
            const container = document.getElementById('hud-players');
            container.innerHTML = '';
            if (!gameState || !gameState.players) return;

            Object.values(gameState.players).forEach(p => {
                const chip = document.createElement('div');
                chip.className = `flex items-center gap-1 px-1.5 py-0.5 rounded text-[11px] border whitespace-nowrap ${p.alive ? 'bg-slate-900 border-slate-700 text-white' : 'bg-red-950/60 border-red-500/40 text-slate-500 opacity-60'}`;
                chip.innerHTML = `
                    <span>${p.alive ? '🤖' : '💀'}</span>
                    <span class="font-bold">${escapeHtml(p.nickname)}</span>
                    <span class="text-cyan-400 font-mono">(${p.kills}k)</span>
                `;
                container.appendChild(chip);
            });
        }

        // Keyboard Controls
        let lastMoveTime = 0;
        function initKeyboard() {
            window.addEventListener('keydown', (e) => {
                if (!gameState || currentRoom.status !== 'playing') return;
                const now = performance.now();
                if (now - lastMoveTime < 100) return;

                let dir = null;
                if (e.key === 'ArrowUp' || e.key === 'w' || e.key === 'W') dir = 'up';
                if (e.key === 'ArrowDown' || e.key === 's' || e.key === 'S') dir = 'down';
                if (e.key === 'ArrowLeft' || e.key === 'a' || e.key === 'A') dir = 'left';
                if (e.key === 'ArrowRight' || e.key === 'd' || e.key === 'D') dir = 'right';

                if (dir) {
                    e.preventDefault();
                    lastMoveTime = now;
                    predictAndMove(dir);
                } else if (e.code === 'Space') {
                    e.preventDefault();
                    sendBomb();
                }
            });
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
            return true;
        }

        // Client-side prediction for instantaneous 0ms response
        function predictAndMove(direction) {
            if (!gameState || !gameState.players || currentRoom.status !== 'playing') return;
            const me = gameState.players[CURRENT_PLAYER_ID];
            if (!me || !me.alive) return;

            let dx = 0, dy = 0;
            if (direction === 'up') dy = -1;
            else if (direction === 'down') dy = 1;
            else if (direction === 'left') dx = -1;
            else if (direction === 'right') dx = 1;

            const nextX = me.x + dx;
            const nextY = me.y + dy;

            if (canMoveTo(nextX, nextY)) {
                // Instantly update on local screen (0ms latency!)
                me.x = nextX;
                me.y = nextY;
                me.direction = direction;

                // Collect power-up immediately
                const puKey = `${nextX}_${nextY}`;
                if (gameState.powerups && gameState.powerups[puKey]) {
                    delete gameState.powerups[puKey];
                    sfx.playPowerup();
                    haptics.heavy();
                }
            }

            // Send authoritative command to server in the background
            sendMove(direction);
        }

        // Ergonomic Mobile Touch Controls with Haptics and Hold-to-walk
        function initTouchControls() {
            const directions = ['up', 'down', 'left', 'right'];

            directions.forEach(dir => {
                const btn = document.getElementById(`dpad-${dir}`);
                if (!btn) return;

                let holdInterval = null;

                const startPress = (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    btn.classList.add('is-active');
                    haptics.light();
                    predictAndMove(dir);

                    if (holdInterval) clearInterval(holdInterval);
                    holdInterval = setInterval(() => {
                        predictAndMove(dir);
                    }, 130);
                };

                const endPress = (e) => {
                    e.preventDefault();
                    btn.classList.remove('is-active');
                    if (holdInterval) {
                        clearInterval(holdInterval);
                        holdInterval = null;
                    }
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
                    haptics.heavy();
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

        // Non-blocking fire-and-forget move dispatching with reconciliation
        let activeMoveRequest = false;
        function sendMove(direction) {
            try {
                fetch(`/api/rooms/${ROOM_CODE}/move`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
                    body: JSON.stringify({ direction })
                }).then(res => res.json()).then(data => {
                    if (data && data.success && data.pos) {
                        const me = gameState.players[CURRENT_PLAYER_ID];
                        if (me && (Math.abs(me.x - data.pos.x) > 1 || Math.abs(me.y - data.pos.y) > 1)) {
                            me.x = data.pos.x;
                            me.y = data.pos.y;
                        }
                    }
                }).catch(() => {});
            } catch (err) {}
        }

        async function sendBomb() {
            try {
                await fetch(`/api/rooms/${ROOM_CODE}/bomb`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN }
                });
            } catch (err) {
                console.error(err);
            }
        }

        async function triggerDetonation(bombId) {
            try {
                await fetch(`/api/rooms/${ROOM_CODE}/detonate`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
                    body: JSON.stringify({ bomb_id: bombId })
                });
            } catch (err) {
                console.error(err);
            }
        }

        async function requestRematch() {
            haptics.heavy();
            try {
                await fetch(`/api/rooms/${ROOM_CODE}/rematch`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN }
                });
            } catch (err) {
                console.error(err);
            }
        }

        function showGameOverModal(data) {
            const modal = document.getElementById('gameover-modal');
            const title = document.getElementById('gameover-title');
            const subtitle = document.getElementById('gameover-subtitle');
            const stats = document.getElementById('gameover-stats');

            modal.classList.remove('hidden');

            if (data.winner_id && gameState && gameState.players[data.winner_id]) {
                const winner = gameState.players[data.winner_id];
                title.innerText = '¡VICTORIA!';
                title.className = 'arcade-font text-3xl sm:text-5xl font-black text-yellow-400 mb-1';
                subtitle.innerText = `${winner.nickname} es el Campeón de la Arena`;
            } else {
                title.innerText = '¡EMPATE!';
                title.className = 'arcade-font text-3xl sm:text-5xl font-black text-slate-300 mb-1';
                subtitle.innerText = 'Ningún jugador sobrevivió a la detonación';
            }

            stats.innerHTML = '<div class="font-bold text-[10px] uppercase text-slate-400 mb-2 border-b border-slate-700 pb-1 flex justify-between"><span>Jugador</span><span>Bajas / Bombas</span></div>';
            if (data.players) {
                Object.values(data.players).forEach(p => {
                    const row = document.createElement('div');
                    row.className = 'flex justify-between py-1 border-b border-slate-800 text-slate-300';
                    row.innerHTML = `<span>${p.alive ? '👑' : '💀'} ${escapeHtml(p.nickname)}</span><span class="font-mono text-cyan-400">${p.kills} bajas &bull; ${p.bombs_placed} bombas</span>`;
                    stats.appendChild(row);
                });
            }
        }

        // Particle System
        function spawnExplosionParticles(x, y, color) {
            for (let i = 0; i < 16; i++) {
                const angle = Math.random() * Math.PI * 2;
                const speed = Math.random() * 4 + 2;
                particles.push({
                    x, y,
                    vx: Math.cos(angle) * speed,
                    vy: Math.sin(angle) * speed,
                    color,
                    life: 1.0,
                    size: Math.random() * 4 + 3,
                });
            }
        }

        // Canvas Rendering Engine
        let animFrameId = null;
        function startGameLoop() {
            startTimer();
            updateHud();
            if (animFrameId) cancelAnimationFrame(animFrameId);

            function loop() {
                render();
                animFrameId = requestAnimationFrame(loop);
            }
            loop();
        }

        function render() {
            ctx.clearRect(0, 0, canvas.width, canvas.height);

            if (!gameState || !gameState.grid) return;

            const grid = gameState.grid;
            const rows = grid.length;
            const cols = grid[0].length;

            // 1. Draw Grid Tiles
            for (let r = 0; r < rows; r++) {
                for (let c = 0; c < cols; c++) {
                    const x = c * TILE_SIZE;
                    const y = r * TILE_SIZE;
                    const cell = grid[r][c];

                    if (cell === 1) {
                        // Outer Wall or Pillar
                        ctx.fillStyle = '#0f172a';
                        ctx.fillRect(x, y, TILE_SIZE, TILE_SIZE);
                        ctx.strokeStyle = '#00f0ff';
                        ctx.lineWidth = 1;
                        ctx.strokeRect(x + 2, y + 2, TILE_SIZE - 4, TILE_SIZE - 4);
                        ctx.fillStyle = '#1e293b';
                        ctx.fillRect(x + 6, y + 6, TILE_SIZE - 12, TILE_SIZE - 12);
                    } else if (cell === 2) {
                        // Destructible Energy Block
                        ctx.fillStyle = '#18181b';
                        ctx.fillRect(x, y, TILE_SIZE, TILE_SIZE);
                        ctx.strokeStyle = '#ff007f';
                        ctx.lineWidth = 1.5;
                        ctx.strokeRect(x + 3, y + 3, TILE_SIZE - 6, TILE_SIZE - 6);
                        ctx.fillStyle = '#27272a';
                        ctx.fillRect(x + 7, y + 7, TILE_SIZE - 14, TILE_SIZE - 14);
                    } else {
                        // Floor
                        ctx.fillStyle = '#090d16';
                        ctx.fillRect(x, y, TILE_SIZE, TILE_SIZE);
                        ctx.strokeStyle = 'rgba(255, 255, 255, 0.03)';
                        ctx.strokeRect(x, y, TILE_SIZE, TILE_SIZE);
                    }
                }
            }

            // 2. Draw Power-ups
            if (gameState.powerups) {
                Object.entries(gameState.powerups).forEach(([key, type]) => {
                    const [px, py] = key.split('_').map(Number);
                    const cx = px * TILE_SIZE + 25;
                    const cy = py * TILE_SIZE + 25;

                    ctx.save();
                    ctx.shadowBlur = 10;
                    if (type === 'speed') {
                        ctx.shadowColor = '#00f0ff';
                        ctx.fillStyle = '#00f0ff';
                        ctx.font = '22px sans-serif';
                        ctx.fillText('⚡', cx - 10, cy + 8);
                    } else if (type === 'bomb') {
                        ctx.shadowColor = '#ff007f';
                        ctx.fillStyle = '#ff007f';
                        ctx.font = '22px sans-serif';
                        ctx.fillText('💣', cx - 11, cy + 8);
                    } else if (type === 'fire') {
                        ctx.shadowColor = '#ffe600';
                        ctx.fillStyle = '#ffe600';
                        ctx.font = '22px sans-serif';
                        ctx.fillText('🔥', cx - 10, cy + 8);
                    }
                    ctx.restore();
                });
            }

            // 3. Draw Bombs
            if (gameState.bombs) {
                const now = performance.now();
                const pulse = Math.sin(now / 150) * 3;

                Object.values(gameState.bombs).forEach(b => {
                    const bx = b.x * TILE_SIZE + 25;
                    const by = b.y * TILE_SIZE + 25;

                    ctx.save();
                    ctx.shadowColor = '#ff007f';
                    ctx.shadowBlur = 15;
                    ctx.beginPath();
                    ctx.arc(bx, by, 16 + pulse, 0, Math.PI * 2);
                    ctx.fillStyle = '#18181b';
                    ctx.fill();
                    ctx.strokeStyle = '#ff007f';
                    ctx.lineWidth = 2.5;
                    ctx.stroke();

                    // Fuse spark
                    ctx.beginPath();
                    ctx.arc(bx + 8, by - 12, 3, 0, Math.PI * 2);
                    ctx.fillStyle = '#ffe600';
                    ctx.fill();
                    ctx.restore();
                });
            }

            // 4. Draw Particles
            for (let i = particles.length - 1; i >= 0; i--) {
                const p = particles[i];
                p.x += p.vx;
                p.y += p.vy;
                p.life -= 0.035;

                if (p.life <= 0) {
                    particles.splice(i, 1);
                    continue;
                }

                ctx.save();
                ctx.globalAlpha = p.life;
                ctx.fillStyle = p.color;
                ctx.shadowColor = p.color;
                ctx.shadowBlur = 8;
                ctx.beginPath();
                ctx.arc(p.x, p.y, p.size * p.life, 0, Math.PI * 2);
                ctx.fill();
                ctx.restore();
            }

            // 5. Draw Players
            if (gameState.players) {
                const colorPalettes = {
                    'neon-blue': { fill: '#00f0ff', glow: 'rgba(0, 240, 255, 0.8)' },
                    'neon-pink': { fill: '#ff007f', glow: 'rgba(255, 0, 127, 0.8)' },
                    'neon-yellow': { fill: '#ffe600', glow: 'rgba(255, 230, 0, 0.8)' },
                    'neon-green': { fill: '#00ff66', glow: 'rgba(0, 255, 102, 0.8)' },
                };

                Object.values(gameState.players).forEach(p => {
                    if (!p.alive) return;

                    const px = p.x * TILE_SIZE + 25;
                    const py = p.y * TILE_SIZE + 25;
                    const col = colorPalettes[p.color] || colorPalettes['neon-blue'];

                    ctx.save();
                    // Player Aura
                    ctx.shadowColor = col.glow;
                    ctx.shadowBlur = 12;

                    // Body
                    ctx.beginPath();
                    ctx.arc(px, py, 17, 0, Math.PI * 2);
                    ctx.fillStyle = col.fill;
                    ctx.fill();
                    ctx.strokeStyle = '#ffffff';
                    ctx.lineWidth = 2;
                    ctx.stroke();

                    // Visor / Face direction
                    ctx.fillStyle = '#060814';
                    let eyeX = px, eyeY = py;
                    if (p.direction === 'up') eyeY -= 6;
                    if (p.direction === 'down') eyeY += 6;
                    if (p.direction === 'left') eyeX -= 6;
                    if (p.direction === 'right') eyeX += 6;

                    ctx.beginPath();
                    ctx.arc(eyeX, eyeY, 5, 0, Math.PI * 2);
                    ctx.fill();

                    // Overhead Nickname
                    ctx.font = 'bold 11px sans-serif';
                    ctx.fillStyle = '#ffffff';
                    ctx.textAlign = 'center';
                    ctx.fillText(p.nickname, px, py - 22);

                    ctx.restore();
                });
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
