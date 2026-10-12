<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
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
        * { box-sizing: border-box; touch-action: manipulation; }
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
        }
        .arcade-font {
            font-family: 'Orbitron', monospace;
            letter-spacing: 1px;
        }
        .canvas-container {
            position: relative;
            box-shadow: 0 0 40px rgba(0, 240, 255, 0.2);
            border: 2px solid rgba(0, 240, 255, 0.4);
            border-radius: 12px;
            overflow: hidden;
            background: #02040a;
            max-width: 100%;
        }
        #game-canvas {
            display: block;
            margin: 0 auto;
        }
        .touch-pad-btn {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: #fff;
            user-select: none;
            active-background: rgba(0, 240, 255, 0.4);
        }
        .touch-pad-btn:active {
            background: rgba(0, 240, 255, 0.3);
            border-color: #00f0ff;
        }
        .bomb-touch-btn {
            background: linear-gradient(135deg, #ff007f, #b30059);
            border: 2px solid #ff007f;
            box-shadow: 0 0 20px rgba(255, 0, 127, 0.5);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            color: #fff;
        }
        .bomb-touch-btn:active {
            transform: scale(0.92);
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
<body class="p-2 md:p-4">

    <!-- Top Bar Navigation & Info -->
    <header class="w-full max-w-5xl mx-auto flex items-center justify-between py-2 px-4 rounded-xl glass-panel mb-3">
        <div class="flex items-center gap-3">
            <a href="{{ route('lobby') }}" class="text-xs uppercase font-bold text-slate-400 hover:text-cyan-400 transition-colors">
                ← Lobby
            </a>
            <div class="h-4 w-px bg-slate-700"></div>
            <div class="arcade-font text-sm font-bold text-cyan-400 flex items-center gap-2">
                <span>SALA:</span>
                <span class="bg-cyan-950 px-2.5 py-0.5 rounded border border-cyan-500/40 text-white tracking-widest" id="header-room-code">{{ $room->code }}</span>
                <button type="button" onclick="copyCode()" class="text-xs text-slate-400 hover:text-white" title="Copiar código">📋</button>
            </div>
        </div>

        <div class="flex items-center gap-3">
            <div class="flex items-center gap-2 text-xs font-semibold px-3 py-1 rounded-full bg-slate-900 border border-slate-700">
                <span class="w-2 h-2 rounded-full bg-emerald-400" id="arena-ws-dot"></span>
                <span id="arena-ws-status">Reverb Conectado</span>
            </div>
            <!-- Audio Mute Button -->
            <button type="button" id="btn-toggle-audio" onclick="toggleAudio()" class="px-2.5 py-1 rounded bg-slate-800 text-xs text-slate-300 hover:text-white border border-slate-700" title="Silenciar / Activar sonido">
                🔊
            </button>
        </div>
    </header>

    <!-- MAIN GAME AREA WRAPPER -->
    <main class="w-full max-w-5xl mx-auto flex-1 flex flex-col items-center justify-center relative">

        <!-- LOBBY WAITING SCREEN (Shown before game start) -->
        <div id="screen-lobby" class="w-full max-w-2xl glass-panel p-6 md:p-8 rounded-2xl border border-cyan-500/30 text-center {{ $room->status === 'playing' ? 'hidden' : '' }}">
            <h2 class="arcade-font text-3xl font-black text-white mb-2 tracking-wide">
                SALA DE ESPERA <span class="text-cyan-400">[{{ $room->code }}]</span>
            </h2>
            <p class="text-slate-400 text-sm mb-6">Comparte el código con tus contrincantes (2 a 4 jugadores).</p>

            <!-- Slots Grid -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8" id="lobby-slots-grid">
                <!-- Dynamically rendered slots -->
            </div>

            <!-- Action buttons -->
            <div class="flex flex-col sm:flex-row gap-4 justify-center">
                <button type="button" id="btn-ready-toggle" onclick="toggleReady()"
                    class="arcade-font px-6 py-3.5 rounded-xl font-bold text-sm tracking-wider uppercase bg-slate-800 hover:bg-slate-700 border border-slate-600 text-slate-200 transition-all">
                    LISTO: <span id="ready-status-text" class="text-red-400">NO</span>
                </button>

                <button type="button" id="btn-start-game" onclick="startGame()"
                    class="arcade-font px-8 py-3.5 rounded-xl font-black text-sm tracking-wider uppercase bg-gradient-to-r from-cyan-400 to-blue-600 text-slate-950 shadow-lg shadow-cyan-500/30 hover:shadow-cyan-500/60 transition-all disabled:opacity-40 disabled:pointer-events-none">
                    🚀 INICIAR PARTIDA
                </button>
            </div>
            <div id="lobby-warning" class="text-xs text-amber-400 mt-4 font-semibold hidden"></div>
        </div>

        <!-- PLAYING SCREEN -->
        <div id="screen-game" class="w-full flex flex-col items-center {{ $room->status === 'playing' ? '' : 'hidden' }}">
            
            <!-- In-game HUD -->
            <div class="w-full max-w-[650px] flex items-center justify-between mb-2 px-2 text-xs font-bold uppercase tracking-wider">
                <div class="flex items-center gap-4" id="hud-players">
                    <!-- Dynamic player chips -->
                </div>
                <div class="flex items-center gap-2 bg-slate-900/90 px-3 py-1 rounded-lg border border-cyan-500/30">
                    <span class="text-slate-400">⏱️</span>
                    <span class="arcade-font text-cyan-400 text-base" id="game-timer">03:00</span>
                </div>
            </div>

            <!-- Canvas Container -->
            <div class="canvas-container">
                <canvas id="game-canvas" width="650" height="550"></canvas>

                <!-- Countdown Overlay -->
                <div id="countdown-overlay" class="absolute inset-0 bg-black/70 backdrop-blur-sm flex items-center justify-center hidden z-20">
                    <div class="arcade-font text-7xl md:text-9xl font-black text-cyan-400 pulse-text" id="countdown-number">3</div>
                </div>

                <!-- Match Over Modal -->
                <div id="gameover-modal" class="absolute inset-0 bg-black/85 backdrop-blur-md flex flex-col items-center justify-center p-6 text-center hidden z-30">
                    <h3 class="arcade-font text-4xl md:text-6xl font-black text-white mb-2" id="gameover-title">¡VICTORIA!</h3>
                    <p class="text-lg text-cyan-400 font-semibold mb-6" id="gameover-subtitle">Piloto 1 es el último sobreviviente</p>

                    <!-- Scoreboard -->
                    <div class="w-full max-w-sm bg-slate-900/80 border border-slate-700 rounded-xl p-4 mb-6 text-sm" id="gameover-stats">
                        <!-- Stats rows -->
                    </div>

                    <div class="flex gap-4">
                        <button type="button" onclick="requestRematch()" class="arcade-font px-6 py-3 rounded-xl bg-cyan-400 text-slate-950 font-black text-sm uppercase tracking-wider shadow-lg shadow-cyan-400/40 hover:bg-cyan-300">
                            🔄 REVANCHA
                        </button>
                        <a href="{{ route('lobby') }}" class="arcade-font px-6 py-3 rounded-xl bg-slate-800 text-slate-200 font-bold text-sm uppercase tracking-wider hover:bg-slate-700">
                            SALIR AL LOBBY
                        </a>
                    </div>
                </div>
            </div>

            <!-- Virtual Mobile Controls (Visible on touch screens / small viewports) -->
            <div class="w-full max-w-[650px] mt-4 flex items-center justify-between px-4 md:hidden" id="mobile-controls">
                <!-- D-Pad -->
                <div class="grid grid-cols-3 gap-2 w-36 h-36">
                    <div></div>
                    <button type="button" class="touch-pad-btn" ontouchstart="sendMove('up'); event.preventDefault();">▲</button>
                    <div></div>
                    <button type="button" class="touch-pad-btn" ontouchstart="sendMove('left'); event.preventDefault();">◀</button>
                    <div></div>
                    <button type="button" class="touch-pad-btn" ontouchstart="sendMove('right'); event.preventDefault();">▶</button>
                    <div></div>
                    <button type="button" class="touch-pad-btn" ontouchstart="sendMove('down'); event.preventDefault();">▼</button>
                    <div></div>
                </div>

                <!-- Action Button -->
                <div class="flex items-center justify-center">
                    <button type="button" class="bomb-touch-btn w-20 h-20" ontouchstart="sendBomb(); event.preventDefault();">
                        💣
                    </button>
                </div>
            </div>

        </div>

    </main>

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
            if (currentRoom.status === 'playing' && gameState) {
                startGameLoop();
            }
        });

        function copyCode() {
            navigator.clipboard.writeText(ROOM_CODE);
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
                    runCountdown(() => {
                        startGameLoop();
                    });
                    break;
                case 'player_moved':
                    if (gameState && gameState.players[data.player_id]) {
                        gameState.players[data.player_id].x = data.x;
                        gameState.players[data.player_id].y = data.y;
                        gameState.players[data.player_id].direction = data.direction;
                        if (data.collected_powerup) {
                            sfx.playPowerup();
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
                        });

                        updateHud();
                    }
                    break;
                case 'game_over':
                    showGameOverModal(data);
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
                card.className = 'p-4 rounded-xl border flex flex-col items-center justify-between min-h-[140px] transition-all ';

                if (p) {
                    const c = colorMap[p.color] || colorMap['neon-blue'];
                    card.className += `${c.border} ${c.bg}`;
                    card.innerHTML = `
                        <div class="text-xs uppercase font-bold tracking-widest ${c.text}">JUGADOR ${i + 1}</div>
                        <div class="my-2">
                            <div class="w-12 h-12 rounded-full border-2 ${c.border} flex items-center justify-center text-xl font-black bg-slate-900">
                                🤖
                            </div>
                        </div>
                        <div class="text-sm font-bold text-white truncate max-w-[120px]">${escapeHtml(p.nickname)}</div>
                        <div class="mt-2 text-xs font-semibold ${p.is_ready ? 'text-emerald-400' : 'text-slate-400'}">
                            ${p.is_host ? '👑 ANFITRIÓN' : (p.is_ready ? '✓ PREPARADO' : '⏳ ESPERANDO')}
                        </div>
                    `;
                } else {
                    card.className += 'border-slate-800 bg-slate-900/40 text-slate-600 border-dashed';
                    card.innerHTML = `
                        <div class="text-xs uppercase font-bold tracking-widest text-slate-600">SLOT ${i + 1}</div>
                        <div class="w-12 h-12 rounded-full border border-slate-800 flex items-center justify-center text-lg my-2 text-slate-700">
                            +
                        </div>
                        <div class="text-xs font-semibold text-slate-600">DISPONIBLE</div>
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
                } else if (count === 0) {
                    num.innerText = '¡FUEGO!';
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
                    // Match expired
                }
            }, 1000);
        }

        function updateHud() {
            const container = document.getElementById('hud-players');
            container.innerHTML = '';
            if (!gameState || !gameState.players) return;

            Object.values(gameState.players).forEach(p => {
                const chip = document.createElement('div');
                chip.className = `flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs border ${p.alive ? 'bg-slate-900 border-slate-700 text-white' : 'bg-red-950/60 border-red-500/40 text-slate-500 opacity-60'}`;
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
                if (now - lastMoveTime < 100) return; // limit input rate

                let dir = null;
                if (e.key === 'ArrowUp' || e.key === 'w' || e.key === 'W') dir = 'up';
                if (e.key === 'ArrowDown' || e.key === 's' || e.key === 'S') dir = 'down';
                if (e.key === 'ArrowLeft' || e.key === 'a' || e.key === 'A') dir = 'left';
                if (e.key === 'ArrowRight' || e.key === 'd' || e.key === 'D') dir = 'right';

                if (dir) {
                    e.preventDefault();
                    lastMoveTime = now;
                    sendMove(dir);
                } else if (e.code === 'Space') {
                    e.preventDefault();
                    sendBomb();
                }
            });
        }

        async function sendMove(direction) {
            try {
                await fetch(`/api/rooms/${ROOM_CODE}/move`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
                    body: JSON.stringify({ direction })
                });
            } catch (err) {
                console.error(err);
            }
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
                title.className = 'arcade-font text-5xl font-black text-yellow-400 mb-2';
                subtitle.innerText = `${winner.nickname} es el Campeón de la Arena`;
            } else {
                title.innerText = '¡EMPATE!';
                title.className = 'arcade-font text-5xl font-black text-slate-300 mb-2';
                subtitle.innerText = 'Ningún jugador sobrevivió a la detonación';
            }

            stats.innerHTML = '<div class="font-bold text-xs uppercase text-slate-400 mb-3 border-b border-slate-700 pb-1 flex justify-between"><span>Jugador</span><span>Bajas / Bombas</span></div>';
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
