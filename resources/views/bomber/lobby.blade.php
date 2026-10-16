<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Bomber Arena — Multijugador Cyberpunk en Tiempo Real</title>
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
                radial-gradient(circle at 50% 15%, #182342 0%, #0B1020 75%),
                linear-gradient(rgba(35, 48, 82, 0.15) 1px, transparent 1px),
                linear-gradient(90deg, rgba(35, 48, 82, 0.15) 1px, transparent 1px);
            background-size: 100% 100%, 36px 36px, 36px 36px;
            min-height: 100vh;
            margin: 0;
            font-family: 'Rajdhani', sans-serif;
            color: #F1F5F9;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px 14px;
            overflow-x: hidden;
        }
        .arcade-font {
            font-family: 'Orbitron', monospace;
            letter-spacing: 1px;
        }
        .glow-cyan {
            text-shadow: 0 0 16px rgba(56, 189, 248, 0.65), 0 0 32px rgba(56, 189, 248, 0.35);
        }
        .glow-coral {
            text-shadow: 0 0 16px rgba(251, 113, 133, 0.65), 0 0 32px rgba(251, 113, 133, 0.35);
        }
        .cyber-panel {
            background: rgba(21, 29, 49, 0.92);
            border: 1px solid var(--panel-border);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.45), inset 0 1px 0 rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(16px);
            border-radius: 20px;
            width: 100%;
        }
        .btn-arcade {
            font-family: 'Orbitron', monospace;
            font-weight: 700;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .btn-cyan {
            background: linear-gradient(135deg, #38BDF8, #0284C7);
            color: #0B1020;
            box-shadow: 0 0 20px rgba(56, 189, 248, 0.35);
        }
        .btn-cyan:hover, .btn-cyan:active {
            box-shadow: 0 0 30px rgba(56, 189, 248, 0.65);
            transform: translateY(-1.5px);
        }
        .btn-coral {
            background: linear-gradient(135deg, #FB7185, #E11D48);
            color: #FFFFFF;
            box-shadow: 0 0 20px rgba(251, 113, 133, 0.35);
        }
        .btn-coral:hover, .btn-coral:active {
            box-shadow: 0 0 30px rgba(251, 113, 133, 0.65);
            transform: translateY(-1.5px);
        }
        .input-cyber {
            background: rgba(11, 16, 32, 0.9);
            border: 1px solid var(--panel-border);
            color: #FFFFFF;
            font-family: 'Orbitron', monospace;
            transition: all 0.2s;
            width: 100%;
            min-width: 0;
        }
        .input-cyber:focus {
            outline: none;
            border-color: var(--neon-cyan);
            box-shadow: 0 0 14px rgba(56, 189, 248, 0.4);
        }
        .pulse-dot {
            animation: pulseGlow 2s infinite;
        }
        @keyframes pulseGlow {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.35; transform: scale(0.85); }
        }
        /* Lucide icon uniform sizing */
        i[data-lucide] {
            width: 20px;
            height: 20px;
            stroke-width: 2px;
            display: inline-block;
            vertical-align: middle;
        }
    </style>
</head>
<body>

    <!-- Header / Logo -->
    <div class="text-center mb-5 sm:mb-7 w-full max-w-4xl px-2">
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-slate-900/90 border border-slate-700/60 text-[11px] sm:text-xs font-semibold uppercase tracking-wider text-slate-300 mb-2.5">
            <span class="w-2 h-2 rounded-full bg-cyan-400 pulse-dot" id="ws-indicator-dot"></span>
            <i data-lucide="radio" class="text-cyan-400 w-3.5 h-3.5"></i>
            <span id="ws-indicator-text">Conectando a Reverb WebSocket...</span>
        </div>
        <h1 class="text-4xl sm:text-6xl md:text-7xl font-black arcade-font text-white tracking-wider glow-cyan uppercase leading-tight">
            BOMBER <span class="text-rose-400 glow-coral">ARENA</span>
        </h1>
        <p class="text-xs sm:text-sm md:text-base text-slate-400 mt-1 font-semibold tracking-wide">
            Videojuego Arcade Multijugador en Tiempo Real &bull; <span class="text-cyan-300 font-bold">Laravel 13 & Reverb</span>
        </p>
    </div>

    <!-- Main Container -->
    <div class="w-full max-w-4xl grid grid-cols-1 md:grid-cols-12 gap-5 sm:gap-6">
        
        <!-- Left Column: Lobby Actions -->
        <div class="md:col-span-7 cyber-panel p-5 sm:p-7 flex flex-col justify-between">
            <div>
                <h2 class="arcade-font text-lg sm:text-xl font-bold text-white mb-4 sm:mb-5 flex items-center gap-2.5">
                    <i data-lucide="gamepad-2" class="text-cyan-400"></i>
                    <span>ZONA DE DESPLIEGUE</span>
                </h2>

                <!-- Nickname Input -->
                <div class="mb-5">
                    <div class="flex items-center justify-between mb-1.5">
                        <label class="text-xs sm:text-sm font-bold uppercase tracking-wider text-slate-300 flex items-center gap-1.5">
                            <i data-lucide="users" class="w-3.5 h-3.5 text-cyan-400"></i>
                            <span>Nombre de Combate</span>
                        </label>
                        <span class="text-xs text-cyan-400/80 font-mono">ID: {{ substr($playerId, 0, 6) }}</span>
                    </div>
                    <input type="text" id="nickname" value="{{ $nickname }}" maxlength="18"
                        class="input-cyber px-4 py-2.5 sm:py-3 rounded-xl text-base sm:text-lg font-bold tracking-wide"
                        placeholder="Ej: CyberZero">
                    <div class="flex items-center justify-between gap-2 mt-2 text-xs text-slate-400">
                        <span>Personaje: <strong id="selected-character-name" class="text-cyan-300">Cyber Blue</strong></span>
                        <button type="button" onclick="document.getElementById('character-roster-preview').scrollIntoView({block:'center'})" class="text-cyan-300 underline cursor-pointer">Cambiar personaje</button>
                    </div>
                </div>

                <!-- Create Room Section -->
                <div class="p-4 sm:p-5 rounded-2xl bg-slate-900/70 border border-slate-800 mb-4 sm:mb-5">
                    <div class="flex items-center justify-between mb-2">
                        <span class="arcade-font font-bold text-white text-sm sm:text-base flex items-center gap-2">
                            <i data-lucide="plus" class="text-cyan-400"></i>
                            <span>Crear Sala de Batalla</span>
                        </span>
                        <span class="text-[10px] sm:text-xs px-2.5 py-0.5 rounded-full bg-cyan-950/70 text-cyan-300 border border-cyan-500/30 font-mono font-bold">2 a 4 Jug.</span>
                    </div>
                    <p class="text-xs sm:text-sm text-slate-400 mb-4 leading-relaxed">
                        Conviértete en anfitrión, genera una sala con temporizador real e invita a tus rivales.
                    </p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-4">
                        <label class="text-xs text-slate-300 sm:col-span-2">Nombre de sala<input id="room-name" maxlength="60" placeholder="Arena Alpha" class="input-cyber rounded-xl px-3 py-2 mt-1"></label>
                        <label class="text-xs text-slate-300">Visibilidad<select id="room-privacy" class="input-cyber rounded-xl px-3 py-2 mt-1">
                            @if($lobbyRules['public_rooms_enabled'])<option value="public">Pública</option>@endif
                            @if($lobbyRules['private_rooms_enabled'])<option value="private">Privada · código</option>@endif
                        </select></label>
                        <label class="text-xs text-slate-300">Capacidad<select id="room-capacity" class="input-cyber rounded-xl px-3 py-2 mt-1">
                            @for($capacity = $lobbyRules['min_players']; $capacity <= $lobbyRules['max_players']; $capacity++)<option value="{{ $capacity }}" {{ $capacity === $lobbyRules['max_players'] ? 'selected' : '' }}>{{ $capacity }} jugadores</option>@endfor
                        </select></label>
                    </div>
                    <button type="button" id="btn-create-room" onclick="createRoom()"
                        data-create-room
                        class="btn-arcade btn-cyan w-full py-3 sm:py-3.5 rounded-xl text-sm sm:text-base tracking-wider uppercase cursor-pointer">
                        <i data-lucide="play" class="w-4 h-4"></i>
                        <span>CREAR SALA AHORA</span>
                    </button>
                </div>

                <!-- Join Room Section -->
                <div class="p-4 sm:p-5 rounded-2xl bg-slate-900/70 border border-slate-800">
                    <span class="block arcade-font font-bold text-white text-sm sm:text-base mb-2.5 flex items-center gap-2">
                        <i data-lucide="chevron-right" class="text-rose-400"></i>
                        <span>Unirse a una Sala</span>
                    </span>
                    <div class="flex flex-col sm:flex-row gap-2.5">
                        <input type="text" id="join-code" maxlength="6"
                            class="input-cyber px-4 py-2.5 sm:py-3 rounded-xl text-center text-lg sm:text-xl font-black uppercase tracking-widest sm:flex-1"
                            placeholder="CÓDIGO">
                        <button type="button" id="btn-join-room" onclick="joinRoom()"
                            class="btn-arcade btn-coral w-full sm:w-auto px-6 py-2.5 sm:py-3 rounded-xl text-sm tracking-wider uppercase cursor-pointer shrink-0">
                            <span>UNIRSE</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Error message container -->
            <div id="error-msg" class="hidden mt-3 p-3 rounded-xl bg-red-950/80 border border-red-500/60 text-red-200 text-xs sm:text-sm font-semibold flex items-center gap-2">
                <i data-lucide="info" class="text-red-400 w-4 h-4 shrink-0"></i>
                <span id="error-text"></span>
            </div>
        </div>

        <!-- Right Column: Character Roster & Rules -->
        <div class="md:col-span-5 flex flex-col gap-5">
            
            <!-- Character Roster Showcase -->
            <div class="cyber-panel p-4 sm:p-5">
                <h3 class="arcade-font text-sm sm:text-base font-bold text-white mb-3 flex items-center gap-2">
                    <i data-lucide="sparkles" class="text-yellow-400"></i>
                    <span>COMBATIENTES ORIGINALES</span>
                </h3>

                <!-- 4 Robots Grid -->
                <div class="grid grid-cols-2 gap-2.5 mb-3" id="character-roster-preview">
                    <!-- Loaded dynamically via BomberEngine -->
                </div>
                <p class="text-[11px] text-slate-400 text-center">Elige tu apariencia antes de ingresar. Todos los personajes tienen las mismas capacidades.</p>
            </div>

            <!-- Controls & Power-ups Card -->
            <div class="cyber-panel p-4 sm:p-5">
                <h3 class="arcade-font text-sm sm:text-base font-bold text-white mb-3 flex items-center gap-2">
                    <i data-lucide="info" class="text-cyan-400"></i>
                    <span>MECÁNICAS Y MEJORAS</span>
                </h3>
                
                <div class="space-y-2 text-xs text-slate-300">
                    <div class="flex items-center justify-between pb-1.5 border-b border-slate-800">
                        <span class="font-semibold text-slate-300">Desplazamiento:</span>
                        <span class="font-mono bg-slate-900 px-2 py-0.5 rounded text-cyan-400 border border-cyan-500/20 text-[11px]">WASD / Flechas</span>
                    </div>
                    <div class="flex items-center justify-between pb-1.5 border-b border-slate-800">
                        <span class="font-semibold text-slate-300">Plantar Bomba:</span>
                        <span class="font-mono bg-slate-900 px-2 py-0.5 rounded text-rose-400 border border-rose-500/20 text-[11px]">Barra Espacio</span>
                    </div>
                    <div class="flex items-center justify-between pb-1.5 border-b border-slate-800">
                        <span class="font-semibold text-slate-300">Móvil / Celular:</span>
                        <span class="font-mono bg-slate-900 px-2 py-0.5 rounded text-yellow-400 border border-yellow-500/20 text-[11px]">D-Pad + Háptica</span>
                    </div>
                </div>

                <div class="mt-3.5 pt-2.5 border-t border-slate-800">
                    <div class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2">Power-ups de Energía:</div>
                    <div class="grid grid-cols-3 gap-2 text-center text-xs">
                        <div class="p-2 rounded-xl bg-cyan-950/40 border border-cyan-500/30 flex flex-col items-center gap-1">
                            <i data-lucide="zap" class="text-cyan-300 w-4 h-4"></i>
                            <span class="font-bold text-[10px] text-cyan-200">Velocidad</span>
                        </div>
                        <div class="p-2 rounded-xl bg-purple-950/40 border border-purple-500/30 flex flex-col items-center gap-1">
                            <i data-lucide="bomb" class="text-purple-300 w-4 h-4"></i>
                            <span class="font-bold text-[10px] text-purple-200">+1 Bomba</span>
                        </div>
                        <div class="p-2 rounded-xl bg-amber-950/40 border border-amber-500/30 flex flex-col items-center gap-1">
                            <i data-lucide="flame" class="text-amber-300 w-4 h-4"></i>
                            <span class="font-bold text-[10px] text-amber-200">+1 Alcance</span>
                        </div>
                    </div>
                </div>
            </div>

        </div>

    </div>

    <section class="cyber-panel w-full max-w-4xl p-5 sm:p-7 mt-6" aria-labelledby="public-rooms-heading">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4">
            <div><h2 id="public-rooms-heading" class="arcade-font font-bold text-white">Salas disponibles</h2><p id="rooms-update-status" class="text-xs text-slate-400 mt-1" aria-live="polite">Actualizando salas…</p></div>
            <div class="flex flex-col sm:flex-row gap-2">
                <input id="room-search" type="search" maxlength="60" placeholder="Nombre o código" aria-label="Buscar sala" class="input-cyber px-3 py-2 rounded-xl text-sm">
                <label class="flex items-center gap-2 text-xs text-slate-300 shrink-0"><input type="checkbox" id="available-only">Solo disponibles</label>
            </div>
        </div>
        <div id="public-rooms-list" class="grid grid-cols-1 sm:grid-cols-2 gap-3"></div>
    </section>

    <!-- Scripts -->
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // Render Character Roster Showcase using BomberEngine vector SVGs
            renderRosterPreview();
            window.BomberLobby?.mount({ join: code => { document.getElementById('join-code').value=code; joinRoom(); } });

            // Setup Lucide icons
            if (window.lucide && window.lucide.createIcons) {
                window.lucide.createIcons();
            }

            // WebSocket connection state
            const wsDot = document.getElementById('ws-indicator-dot');
            const wsText = document.getElementById('ws-indicator-text');

            if (window.Echo && window.Echo.connector && window.Echo.connector.pusher) {
                const pusher = window.Echo.connector.pusher;
                pusher.connection.bind('connected', () => {
                    wsDot.className = 'w-2 h-2 rounded-full bg-emerald-400 pulse-dot';
                    wsText.innerText = 'Reverb WebSocket Conectado';
                    wsText.className = 'text-emerald-400 font-semibold';
                });
                pusher.connection.bind('disconnected', () => {
                    wsDot.className = 'w-2 h-2 rounded-full bg-red-500';
                    wsText.innerText = 'WebSocket Desconectado';
                    wsText.className = 'text-red-400 font-semibold';
                });
                if (pusher.connection.state === 'connected') {
                    wsDot.className = 'w-2 h-2 rounded-full bg-emerald-400 pulse-dot';
                    wsText.innerText = 'Reverb WebSocket Conectado';
                    wsText.className = 'text-emerald-400 font-semibold';
                }
            }
        });

        function renderRosterPreview() {
            const container = document.getElementById('character-roster-preview');
            if (!container || !window.BomberEngine) return;

            const characters = @json($characters);
            const enabled = @json($lobbyRules['character_selection_enabled']);
            try { selectedCharacter = localStorage.getItem('bomber_character') || selectedCharacter; } catch {}
            if (!characters[selectedCharacter] || !enabled) selectedCharacter = 'neon-blue';
            container.innerHTML = Object.entries(characters).map(([id,c]) => `
                <button type="button" data-character="${id}" data-character-name="${c.name}" onclick="selectLobbyCharacter('${id}')" aria-pressed="${id===selectedCharacter}" ${!enabled?'disabled':''}
                    class="p-3 rounded-xl bg-slate-900/80 border flex flex-col items-center gap-2 text-center focus-visible:outline-2 focus-visible:outline-cyan-300 cursor-pointer">
                    <span class="character-preview motion-safe:animate-pulse" style="color:${c.color}">${window.BomberEngine.getCharacterSVG(id,54)}</span>
                    <span class="text-xs font-bold text-white">${c.name}</span>
                    <span class="text-[10px] text-slate-400">${c.label} · mismas capacidades</span>
                    <span data-selection-label class="text-[10px] font-bold text-cyan-300"></span>
                </button>`).join('');
            selectLobbyCharacter(selectedCharacter);
        }

        let selectedCharacter = 'neon-blue';
        function selectLobbyCharacter(id) {
            selectedCharacter = id;
            document.getElementById('selected-character-name').textContent=document.querySelector('[data-character="'+id+'"]')?.dataset.characterName || 'Cyber Blue';
            try { localStorage.setItem('bomber_character',id); } catch {}
            document.querySelectorAll('[data-character]').forEach(button=>{
                const active=button.dataset.character===id;
                button.setAttribute('aria-pressed',String(active));
                button.style.borderColor=active?'#38bdf8':'#233052';
                button.querySelector('[data-selection-label]').textContent=active?'✓ SELECCIONADO':'SELECCIONAR';
            });
        }

        function showError(msg) {
            const el = document.getElementById('error-msg');
            const txt = document.getElementById('error-text');
            txt.innerText = msg;
            el.classList.remove('hidden');
        }

        async function createRoom() {
            const nickname = document.getElementById('nickname').value.trim() || 'Comandante';
            const btn = document.getElementById('btn-create-room');
            btn.disabled = true;
            btn.innerHTML = '<span>CREANDO SALA...</span>';

            try {
                const response = await fetch('{{ route('api.rooms.create') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({ nickname, character_id:selectedCharacter, name:document.getElementById('room-name').value.trim(), is_private:document.getElementById('room-privacy').value==='private', max_players:Number(document.getElementById('room-capacity').value) })
                });

                const data = await response.json();
                if (data.success && data.redirect) {
                    window.location.href = data.redirect;
                } else {
                    showError(data.error || Object.values(data.errors || {}).flat()[0] || 'No se pudo crear la sala');
                    btn.disabled = false;
                    btn.innerHTML = '<i data-lucide="play" class="w-4 h-4"></i><span>CREAR SALA AHORA</span>';
                    if (window.lucide) window.lucide.createIcons();
                }
            } catch (err) {
                console.error(err);
                showError('Error de red al crear la sala');
                btn.disabled = false;
                btn.innerHTML = '<i data-lucide="play" class="w-4 h-4"></i><span>CREAR SALA AHORA</span>';
                if (window.lucide) window.lucide.createIcons();
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
                    body: JSON.stringify({ code, nickname, character_id:selectedCharacter })
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
