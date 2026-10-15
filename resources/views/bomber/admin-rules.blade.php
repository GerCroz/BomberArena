<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuración de Bomber Arena — Reglas de Juego & Partidas</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@600;700;800;900&family=Rajdhani:wght@500;600;700&family=JetBrains+Mono:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-main: #0B1020;
            --panel-bg: #151D31;
            --panel-border: #233052;
            --neon-cyan: #38BDF8;
            --neon-coral: #FB7185;
            --neon-emerald: #34D399;
            --neon-amber: #FBBF24;
        }
        * { box-sizing: border-box; }
        body {
            background-color: var(--bg-main);
            background-image: 
                radial-gradient(circle at 50% 10%, #182342 0%, #0B1020 80%),
                linear-gradient(rgba(35, 48, 82, 0.12) 1px, transparent 1px),
                linear-gradient(90deg, rgba(35, 48, 82, 0.12) 1px, transparent 1px);
            background-size: 100% 100%, 36px 36px, 36px 36px;
            margin: 0;
            font-family: 'Rajdhani', sans-serif;
            color: #F1F5F9;
            min-height: 100vh;
        }
        .arcade-font {
            font-family: 'Orbitron', monospace;
            letter-spacing: 1px;
        }
        .code-font {
            font-family: 'JetBrains Mono', monospace;
        }
        .cyber-panel {
            background: rgba(21, 29, 49, 0.92);
            backdrop-filter: blur(14px);
            border: 1px solid var(--panel-border);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.45);
        }
        .nav-tab.active {
            background: rgba(56, 189, 248, 0.15);
            border-color: var(--neon-cyan);
            color: #FFFFFF;
        }
        .pulse-live {
            animation: pulseLive 2s infinite;
        }
        @keyframes pulseLive {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.4; transform: scale(0.92); }
        }
    </style>
</head>
<body class="p-3 sm:p-6">

    <!-- Top Navigation Header -->
    <header class="w-full max-w-7xl mx-auto flex flex-col md:flex-row items-start md:items-center justify-between py-3 px-4 sm:px-6 rounded-2xl cyber-panel mb-6 gap-4">
        <div class="flex items-center gap-3">
            <div class="p-2.5 rounded-xl bg-cyan-950/80 border border-cyan-500/40 text-cyan-400">
                <i data-lucide="sliders" class="w-6 h-6"></i>
            </div>
            <div>
                <h1 class="arcade-font text-lg sm:text-2xl font-black text-white flex items-center gap-2">
                    CONFIGURACIÓN DE BOMBER ARENA
                    <span class="text-xs px-2.5 py-0.5 rounded-md bg-cyan-500/20 text-cyan-400 border border-cyan-500/30">v{{ $activeRecord->version }}</span>
                </h1>
                <p class="text-xs text-slate-400">Reglas autoritativas del motor de juego, auditoría y monitoreo activo</p>
            </div>
        </div>

        <div class="flex items-center gap-2 sm:gap-3 flex-wrap">
            <a href="{{ route('docente.monitoreo') }}" target="_blank"
                class="px-3 py-1.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-xs font-bold text-indigo-300 border border-indigo-500/30 flex items-center gap-1.5 transition-colors">
                <i data-lucide="activity" class="w-3.5 h-3.5"></i>
                <span>Telemetría Docente</span>
            </a>
            <a href="{{ route('lobby') }}" target="_blank"
                class="px-3 py-1.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-xs font-bold text-cyan-300 border border-slate-700 flex items-center gap-1.5 transition-colors">
                <i data-lucide="gamepad-2" class="w-3.5 h-3.5"></i>
                <span>Ir al Juego</span>
            </a>
            <form action="{{ route('admin.logout') }}" method="POST" class="inline">
                @csrf
                <button type="submit" class="px-3 py-1.5 rounded-xl bg-rose-950/50 hover:bg-rose-900/70 text-xs font-bold text-rose-300 border border-rose-500/30 flex items-center gap-1.5 transition-colors cursor-pointer">
                    <i data-lucide="log-out" class="w-3.5 h-3.5"></i>
                    <span>Salir</span>
                </button>
            </form>
        </div>
    </header>

    <main class="w-full max-w-7xl mx-auto">
        
        <!-- Flash Status Message -->
        @if (session('status'))
            <div class="mb-6 p-4 rounded-xl bg-emerald-950/70 border border-emerald-500/40 text-emerald-300 text-sm font-semibold flex items-center gap-2">
                <i data-lucide="check-circle" class="w-5 h-5 text-emerald-400"></i>
                <span>{{ session('status') }}</span>
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-6 p-4 rounded-xl bg-rose-950/70 border border-rose-500/40 text-rose-300 text-sm">
                <p class="font-bold mb-1">Se encontraron los siguientes errores:</p>
                <ul class="list-disc list-inside text-xs space-y-0.5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <!-- Tab Selector -->
        <div class="flex items-center gap-2 border-b border-slate-800 pb-3 mb-6 overflow-x-auto">
            <button type="button" onclick="switchTab('rules')" id="tab-btn-rules"
                class="nav-tab active px-4 py-2 rounded-xl text-xs sm:text-sm font-bold uppercase tracking-wider border border-slate-700 text-slate-400 hover:text-white transition-all flex items-center gap-2 cursor-pointer">
                <i data-lucide="settings-2" class="w-4 h-4"></i>
                <span>Reglas de Juego</span>
            </button>
            <button type="button" onclick="switchTab('matches')" id="tab-btn-matches"
                class="nav-tab px-4 py-2 rounded-xl text-xs sm:text-sm font-bold uppercase tracking-wider border border-slate-700 text-slate-400 hover:text-white transition-all flex items-center gap-2 cursor-pointer">
                <i data-lucide="monitor" class="w-4 h-4"></i>
                <span>Partidas Activas (<span id="count-active-matches">{{ $activeRooms->count() }}</span>)</span>
            </button>
            <button type="button" onclick="switchTab('audits')" id="tab-btn-audits"
                class="nav-tab px-4 py-2 rounded-xl text-xs sm:text-sm font-bold uppercase tracking-wider border border-slate-700 text-slate-400 hover:text-white transition-all flex items-center gap-2 cursor-pointer">
                <i data-lucide="clipboard-list" class="w-4 h-4"></i>
                <span>Historial & Auditoría</span>
            </button>
        </div>

        <!-- TAB 1: RULES CONFIGURATION -->
        <div id="tab-rules" class="space-y-6">
            <form action="{{ route('admin.rules.update') }}" method="POST">
                @csrf

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

                    <!-- SECCIÓN A: CONFIGURACIÓN DE PARTIDAS -->
                    <div class="cyber-panel p-5 sm:p-6 rounded-2xl">
                        <div class="flex items-center gap-2.5 pb-3 border-b border-slate-800 mb-4 text-cyan-400">
                            <i data-lucide="clock" class="w-5 h-5"></i>
                            <h2 class="arcade-font text-sm sm:text-base font-black text-white">A. CONFIGURACIÓN DE PARTIDAS</h2>
                        </div>

                        <div class="space-y-4 text-xs sm:text-sm">
                            <div>
                                <label class="block font-bold text-slate-300 mb-1">Duración Máxima de la Partida (segundos)</label>
                                <input type="number" name="round_duration_seconds" min="30" max="1200" step="5" required
                                    value="{{ old('round_duration_seconds', $activeRules['round_duration_seconds'] ?? 180) }}"
                                    class="w-full bg-slate-900 border border-slate-700 focus:border-cyan-400 rounded-xl px-3 py-2 text-white font-mono">
                                <p class="text-[11px] text-slate-400 mt-0.5">Estándar: 180 segundos (3 minutos). Rango permitido: 30 a 1200s.</p>
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block font-bold text-slate-300 mb-1">Jugadores Mínimos</label>
                                    <input type="number" name="min_players" min="2" max="4" required
                                        value="{{ old('min_players', $activeRules['min_players'] ?? 2) }}"
                                        class="w-full bg-slate-900 border border-slate-700 focus:border-cyan-400 rounded-xl px-3 py-2 text-white font-mono">
                                </div>
                                <div>
                                    <label class="block font-bold text-slate-300 mb-1">Jugadores Máximos</label>
                                    <input type="number" name="max_players" min="2" max="4" required
                                        value="{{ old('max_players', $activeRules['max_players'] ?? 4) }}"
                                        class="w-full bg-slate-900 border border-slate-700 focus:border-cyan-400 rounded-xl px-3 py-2 text-white font-mono">
                                </div>
                            </div>

                            <div>
                                <label class="block font-bold text-slate-300 mb-1">Cuenta Regresiva de Inicio (segundos)</label>
                                <input type="number" name="countdown_seconds" min="0" max="10" required
                                    value="{{ old('countdown_seconds', $activeRules['countdown_seconds'] ?? 3) }}"
                                    class="w-full bg-slate-900 border border-slate-700 focus:border-cyan-400 rounded-xl px-3 py-2 text-white font-mono">
                            </div>

                            <div>
                                <label class="block font-bold text-slate-300 mb-1">Resultado al Agotar el Tiempo</label>
                                <select name="timeout_result" class="w-full bg-slate-900 border border-slate-700 focus:border-cyan-400 rounded-xl px-3 py-2 text-white">
                                    <option value="draw" {{ ($activeRules['timeout_result'] ?? 'draw') === 'draw' ? 'selected' : '' }}>Empate al agotarse el tiempo (clásico)</option>
                                    <option value="most_bombs" {{ ($activeRules['timeout_result'] ?? '') === 'most_bombs' ? 'selected' : '' }}>Mayor cantidad de bombas (si igualan: Empate)</option>
                                    <option value="most_kills" {{ ($activeRules['timeout_result'] ?? '') === 'most_kills' ? 'selected' : '' }}>Mayor cantidad de bajas (si hay empate en bajas: Empate)</option>
                                </select>
                            </div>

                            <div class="pt-2 space-y-2 border-t border-slate-800">
                                <label class="flex items-center gap-2.5 cursor-pointer">
                                    <input type="checkbox" name="end_on_last_survivor" value="1" {{ !empty($activeRules['end_on_last_survivor']) ? 'checked' : '' }}
                                        class="rounded bg-slate-900 border-slate-700 text-cyan-500 focus:ring-0">
                                    <span class="text-slate-300 font-semibold">Finalizar con un único superviviente (Victoria)</span>
                                </label>
                                <label class="flex items-center gap-2.5 cursor-pointer">
                                    <input type="checkbox" name="draw_on_simultaneous_elimination" value="1" {{ !empty($activeRules['draw_on_simultaneous_elimination']) ? 'checked' : '' }}
                                        class="rounded bg-slate-900 border-slate-700 text-cyan-500 focus:ring-0">
                                    <span class="text-slate-300 font-semibold">Empate por eliminación simultánea de todos los jugadores</span>
                                </label>
                                <label class="flex items-center gap-2.5 cursor-pointer">
                                    <input type="checkbox" name="allow_rematch" value="1" {{ !empty($activeRules['allow_rematch']) ? 'checked' : '' }}
                                        class="rounded bg-slate-900 border-slate-700 text-cyan-500 focus:ring-0">
                                    <span class="text-slate-300 font-semibold">Permitir revancha inmediata entre participantes</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- SECCIÓN B: BOMBAS Y EXPLOSIONES -->
                    <div class="cyber-panel p-5 sm:p-6 rounded-2xl">
                        <div class="flex items-center gap-2.5 pb-3 border-b border-slate-800 mb-4 text-rose-400">
                            <i data-lucide="bomb" class="w-5 h-5"></i>
                            <h2 class="arcade-font text-sm sm:text-base font-black text-white">B. BOMBAS Y EXPLOSIONES</h2>
                        </div>

                        <div class="space-y-4 text-xs sm:text-sm">
                            <div>
                                <label class="block font-bold text-slate-300 mb-1">Tiempo hasta detonación de la mecha (milisegundos)</label>
                                <input type="number" name="bomb_fuse_ms" min="500" max="10000" step="100" required
                                    value="{{ old('bomb_fuse_ms', $activeRules['bomb_fuse_ms'] ?? 2000) }}"
                                    class="w-full bg-slate-900 border border-slate-700 focus:border-rose-400 rounded-xl px-3 py-2 text-white font-mono">
                                <p class="text-[11px] text-slate-400 mt-0.5">Estándar: 2000 ms (2.0 segundos).</p>
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block font-bold text-slate-300 mb-1">Alcance Inicial (casillas)</label>
                                    <input type="number" name="bomb_initial_range" min="1" max="10" required
                                        value="{{ old('bomb_initial_range', $activeRules['bomb_initial_range'] ?? 2) }}"
                                        class="w-full bg-slate-900 border border-slate-700 focus:border-rose-400 rounded-xl px-3 py-2 text-white font-mono">
                                </div>
                                <div>
                                    <label class="block font-bold text-slate-300 mb-1">Bombas Simultáneas Iniciales</label>
                                    <input type="number" name="bomb_initial_simultaneous" min="1" max="5" required
                                        value="{{ old('bomb_initial_simultaneous', $activeRules['bomb_initial_simultaneous'] ?? 1) }}"
                                        class="w-full bg-slate-900 border border-slate-700 focus:border-rose-400 rounded-xl px-3 py-2 text-white font-mono">
                                </div>
                            </div>

                            <div>
                                <label class="block font-bold text-slate-300 mb-1">Duración Visual de la Explosión en Pantalla (ms)</label>
                                <input type="number" name="explosion_visual_duration_ms" min="200" max="2000" step="50" required
                                    value="{{ old('explosion_visual_duration_ms', $activeRules['explosion_visual_duration_ms'] ?? 500) }}"
                                    class="w-full bg-slate-900 border border-slate-700 focus:border-rose-400 rounded-xl px-3 py-2 text-white font-mono">
                            </div>

                            <div class="pt-2 space-y-2 border-t border-slate-800">
                                <label class="flex items-center gap-2.5 cursor-pointer">
                                    <input type="checkbox" name="allow_chain_reaction" value="1" {{ !empty($activeRules['allow_chain_reaction']) ? 'checked' : '' }}
                                        class="rounded bg-slate-900 border-slate-700 text-rose-500 focus:ring-0">
                                    <span class="text-slate-300 font-semibold">Reacción en Cadena (una bomba detona bombas adyacentes)</span>
                                </label>
                                <label class="flex items-center gap-2.5 cursor-pointer">
                                    <input type="checkbox" name="self_damage" value="1" {{ !empty($activeRules['self_damage']) ? 'checked' : '' }}
                                        class="rounded bg-slate-900 border-slate-700 text-rose-500 focus:ring-0">
                                    <span class="text-slate-300 font-semibold">Daño Propio (el jugador puede ser eliminado por su propia bomba)</span>
                                </label>
                                <label class="flex items-center gap-2.5 cursor-pointer">
                                    <input type="checkbox" name="friendly_fire" value="1" {{ !empty($activeRules['friendly_fire']) ? 'checked' : '' }}
                                        class="rounded bg-slate-900 border-slate-700 text-rose-500 focus:ring-0">
                                    <span class="text-slate-300 font-semibold">Fuego Cruzado / Daño entre Jugadores</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- SECCIÓN C: JUGADORES Y CONEXIÓN -->
                    <div class="cyber-panel p-5 sm:p-6 rounded-2xl">
                        <div class="flex items-center gap-2.5 pb-3 border-b border-slate-800 mb-4 text-emerald-400">
                            <i data-lucide="users" class="w-5 h-5"></i>
                            <h2 class="arcade-font text-sm sm:text-base font-black text-white">C. JUGADORES Y CONEXIÓN</h2>
                        </div>

                        <div class="space-y-4 text-xs sm:text-sm">
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block font-bold text-slate-300 mb-1">Velocidad Inicial</label>
                                    <input type="number" name="player_initial_speed" min="1" max="3" required
                                        value="{{ old('player_initial_speed', $activeRules['player_initial_speed'] ?? 1) }}"
                                        class="w-full bg-slate-900 border border-slate-700 focus:border-emerald-400 rounded-xl px-3 py-2 text-white font-mono">
                                </div>
                                <div>
                                    <label class="block font-bold text-slate-300 mb-1">Gracia de Reconexión (seg)</label>
                                    <input type="number" name="reconnect_grace_seconds" min="0" max="120" required
                                        value="{{ old('reconnect_grace_seconds', $activeRules['reconnect_grace_seconds'] ?? 15) }}"
                                        class="w-full bg-slate-900 border border-slate-700 focus:border-emerald-400 rounded-xl px-3 py-2 text-white font-mono">
                                </div>
                            </div>

                            <div class="pt-2 space-y-2 border-t border-slate-800">
                                <label class="flex items-center gap-2.5 cursor-pointer">
                                    <input type="checkbox" name="player_collisions" value="1" {{ !empty($activeRules['player_collisions']) ? 'checked' : '' }}
                                        class="rounded bg-slate-900 border-slate-700 text-emerald-500 focus:ring-0">
                                    <span class="text-slate-300 font-semibold">Bloquear entre jugadores (desactivado en modo clásico)</span>
                                </label>
                                <label class="flex items-center gap-2.5 cursor-pointer">
                                    <input type="checkbox" name="reconnection_enabled" value="1" {{ !empty($activeRules['reconnection_enabled']) ? 'checked' : '' }}
                                        class="rounded bg-slate-900 border-slate-700 text-emerald-500 focus:ring-0">
                                    <span class="text-slate-300 font-semibold">Reconexión Permitida mediante Cookie/Token</span>
                                </label>
                                <label class="flex items-center gap-2.5 cursor-pointer">
                                    <input type="checkbox" name="eliminate_on_disconnect" value="1" {{ !empty($activeRules['eliminate_on_disconnect']) ? 'checked' : '' }}
                                        class="rounded bg-slate-900 border-slate-700 text-emerald-500 focus:ring-0">
                                    <span class="text-slate-300 font-semibold">Eliminar por abandono definitivo tras vencer gracia</span>
                                </label>
                                <label class="flex items-center gap-2.5 cursor-pointer">
                                    <input type="checkbox" disabled
                                        class="rounded bg-slate-900 border-slate-700 text-emerald-500 focus:ring-0">
                                    <span class="text-slate-300 font-semibold">Los eliminados no bloquean el paso</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- SECCIÓN D: TABLERO Y POWER-UPS -->
                    <details class="cyber-panel p-5 sm:p-6 rounded-2xl">
                        <summary class="font-bold text-slate-300 cursor-pointer">Parámetros técnicos avanzados · próximas partidas</summary>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-4">
                            @foreach (['movement_speed' => ['Velocidad (casillas/s)', 2, 6, 0.25], 'simulation_hz' => ['Simulación (20, 25 o 30 Hz)', 20, 30, 5], 'publish_hz' => ['Publicación de estados (Hz)', 5, 30, 1], 'corner_assist' => ['Asistencia de giro (casillas)', 0, 0.25, 0.05], 'visual_correction_limit' => ['Límite de corrección suave (casillas)', 0.25, 1, 0.05]] as $key => [$label, $min, $max, $step])
                                <label class="block text-slate-300">{{ $label }}
                                    <input name="{{ $key }}" type="number" min="{{ $min }}" max="{{ $max }}" step="{{ $step }}" value="{{ $activeRules[$key] }}" required class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white font-mono">
                                </label>
                            @endforeach
                            @foreach (['local_prediction' => 'Predicción local', 'remote_interpolation' => 'Interpolación remota'] as $key => $label)
                                <label class="flex items-center gap-2.5 text-slate-300">
                                    <input type="hidden" name="{{ $key }}" value="0">
                                    <input type="checkbox" name="{{ $key }}" value="1" {{ $activeRules[$key] ? 'checked' : '' }}>{{ $label }}
                                </label>
                            @endforeach
                        </div>
                    </details>
                    <div class="cyber-panel p-5 sm:p-6 rounded-2xl">
                        <div class="flex items-center gap-2.5 pb-3 border-b border-slate-800 mb-4 text-amber-400">
                            <i data-lucide="grid" class="w-5 h-5"></i>
                            <h2 class="arcade-font text-sm sm:text-base font-black text-white">D. TABLERO Y POWER-UPS</h2>
                        </div>

                        <div class="space-y-4 text-xs sm:text-sm">
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block font-bold text-slate-300 mb-1">Columnas x Filas</label>
                                    <input type="text" value="13 x 11 (Fijo arcade)" disabled
                                        class="w-full bg-slate-950/60 border border-slate-800 rounded-xl px-3 py-2 text-slate-500 font-mono">
                                </div>
                                <div>
                                    <label class="block font-bold text-slate-300 mb-1">Densidad Muros Destructibles (%)</label>
                                    <input type="number" name="block_density_percent" min="10" max="90" required
                                        value="{{ old('block_density_percent', $activeRules['block_density_percent'] ?? 65) }}"
                                        class="w-full bg-slate-900 border border-slate-700 focus:border-amber-400 rounded-xl px-3 py-2 text-white font-mono">
                                </div>
                            </div>

                            <div>
                                <label class="block font-bold text-slate-300 mb-1">Probabilidad de Aparición de Power-Up (%)</label>
                                <input type="number" name="powerup_drop_chance_percent" min="0" max="100" required
                                    value="{{ old('powerup_drop_chance_percent', $activeRules['powerup_drop_chance_percent'] ?? 25) }}"
                                    class="w-full bg-slate-900 border border-slate-700 focus:border-amber-400 rounded-xl px-3 py-2 text-white font-mono">
                            </div>

                            <div class="pt-2 space-y-2 border-t border-slate-800">
                                <label class="flex items-center gap-2.5 cursor-pointer">
                                    <input type="checkbox" name="powerups_enabled" value="1" {{ !empty($activeRules['powerups_enabled']) ? 'checked' : '' }}
                                        class="rounded bg-slate-900 border-slate-700 text-amber-500 focus:ring-0">
                                    <span class="text-slate-300 font-semibold">Habilitar Power-Ups (Fuego, Bombas, Velocidad)</span>
                                </label>
                                <label class="flex items-center gap-2.5 cursor-pointer">
                                    <input type="checkbox" name="random_map_generation" value="1" {{ !empty($activeRules['random_map_generation']) ? 'checked' : '' }}
                                        class="rounded bg-slate-900 border-slate-700 text-amber-500 focus:ring-0">
                                    <span class="text-slate-300 font-semibold">Generación Aleatoria con Semilla (Seed) por Sala</span>
                                </label>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- Resumen de Auditoría y Guardado -->
                <div class="cyber-panel p-5 sm:p-6 rounded-2xl mt-6">
                    <div class="flex flex-col sm:flex-row items-center justify-between gap-4">
                        <div class="w-full sm:w-2/3">
                            <label class="block text-xs font-bold uppercase tracking-wider text-slate-300 mb-1">
                                Motivo o Resumen del Cambio (Auditoría)
                            </label>
                            <input type="text" name="change_summary" placeholder="Ej: Ajuste de tiempo a 180s y mecha a 2000ms para torneo oficial"
                                class="w-full bg-slate-900 border border-slate-700 focus:border-cyan-400 rounded-xl px-3.5 py-2 text-xs sm:text-sm text-white">
                        </div>
                        <div class="w-full sm:w-1/3 flex justify-end">
                            <button type="submit"
                                class="w-full arcade-font py-3 px-6 rounded-xl font-black text-xs sm:text-sm tracking-wider uppercase bg-gradient-to-r from-cyan-400 to-blue-600 text-slate-950 shadow-lg shadow-cyan-500/30 hover:shadow-cyan-500/60 transition-all flex items-center justify-center gap-2 cursor-pointer">
                                <i data-lucide="save" class="w-4 h-4"></i>
                                <span>GUARDAR NUEVA VERSIÓN</span>
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <!-- TAB 2: ACTIVE MATCHES MONITOR -->
        <div id="tab-matches" class="hidden space-y-6">
            <div class="cyber-panel p-5 sm:p-6 rounded-2xl">
                <div class="flex items-center justify-between pb-3 border-b border-slate-800 mb-4">
                    <div class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-400 pulse-live"></span>
                        <h2 class="arcade-font text-sm sm:text-base font-black text-white">SALAS Y PARTIDAS EN TIEMPO REAL</h2>
                    </div>
                    <button type="button" onclick="refreshMatchesData()" class="px-3 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-xs font-bold text-cyan-300 border border-slate-700 flex items-center gap-1.5 cursor-pointer">
                        <i data-lucide="refresh-cw" class="w-3.5 h-3.5"></i>
                        <span>Actualizar</span>
                    </button>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="text-[11px] uppercase tracking-wider text-slate-400 bg-slate-900/60 border-b border-slate-800">
                            <tr>
                                <th class="p-3">Código</th>
                                <th class="p-3">Estado</th>
                                <th class="p-3">Jugadores (Vivos/Totales)</th>
                                <th class="p-3">Tiempo Restante</th>
                                <th class="p-3">Bombas Activas</th>
                                <th class="p-3">Versión Reglas</th>
                                <th class="p-3 text-right">Acción</th>
                            </tr>
                        </thead>
                        <tbody id="active-matches-tbody" class="divide-y divide-slate-800/60">
                            @forelse ($activeRooms as $room)
                                @php
                                    $st = \Illuminate\Support\Facades\Cache::get("arena_state_{$room->code}") ?? $room->game_state;
                                    $alive = is_array($st) && isset($st['players']) ? count(array_filter($st['players'], fn($p) => !empty($p['alive']))) : 0;
                                    $bombs = is_array($st) && isset($st['bombs']) ? count($st['bombs']) : 0;
                                    $elapsed = (is_array($st) && !empty($st['started_at'])) ? max(0, (int)(microtime(true) - $st['started_at'])) : 0;
                                    $remaining = max(0, $room->round_duration_seconds - $elapsed);
                                @endphp
                                <tr class="hover:bg-slate-900/40 transition-colors">
                                    <td class="p-3 font-mono font-bold text-cyan-400">
                                        <a href="{{ route('arena', ['code' => $room->code]) }}" target="_blank" class="hover:underline">
                                            {{ $room->code }} ↗
                                        </a>
                                    </td>
                                    <td class="p-3">
                                        <span class="px-2 py-0.5 rounded-full font-bold text-[10px] uppercase
                                            {{ $room->status === 'playing' ? 'bg-emerald-950 text-emerald-400 border border-emerald-500/40' : 'bg-amber-950 text-amber-400 border border-amber-500/40' }}">
                                            {{ $room->status }}
                                        </span>
                                    </td>
                                    <td class="p-3">
                                        <span class="font-bold text-white">{{ $alive }}</span> / {{ $room->players->count() }}
                                    </td>
                                    <td class="p-3 font-mono">
                                        {{ sprintf('%02d:%02d', floor($remaining / 60), $remaining % 60) }}
                                    </td>
                                    <td class="p-3 font-mono font-bold text-rose-400">{{ $bombs }}</td>
                                    <td class="p-3 font-mono">v{{ $room->rules_version ?? 1 }}</td>
                                    <td class="p-3 text-right">
                                        <form action="{{ route('admin.matches.cancel', ['code' => $room->code]) }}" method="POST" onsubmit="return confirm('¿Seguro que deseas cancelar esta partida?');">
                                            @csrf
                                            <button type="submit" class="px-2.5 py-1 rounded bg-rose-950/70 hover:bg-rose-900 text-rose-300 border border-rose-500/40 font-bold text-[11px] cursor-pointer">
                                                Cancelar
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="p-6 text-center text-slate-500">No hay salas activas en este momento.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- TAB 3: AUDITS AND FINISHED MATCHES -->
        <div id="tab-audits" class="hidden space-y-6">
            
            <!-- Historial de Partidas Finalizadas -->
            <div class="cyber-panel p-5 sm:p-6 rounded-2xl">
                <div class="flex items-center gap-2 pb-3 border-b border-slate-800 mb-4">
                    <i data-lucide="trophy" class="w-5 h-5 text-amber-400"></i>
                    <h2 class="arcade-font text-sm sm:text-base font-black text-white">HISTORIAL DE PARTIDAS FINALIZADAS</h2>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="text-[11px] uppercase tracking-wider text-slate-400 bg-slate-900/60 border-b border-slate-800">
                            <tr>
                                <th class="p-3">Código</th>
                                <th class="p-3">Resultado</th>
                                <th class="p-3">Motivo Estructurado</th>
                                <th class="p-3">Bombas</th>
                                <th class="p-3">Muros</th>
                                <th class="p-3">Bajas</th>
                                <th class="p-3">Versión</th>
                                <th class="p-3">Fecha y Hora</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/60">
                            @forelse ($finishedRooms as $room)
                                <tr class="hover:bg-slate-900/40">
                                    <td class="p-3 font-mono font-bold text-cyan-400">{{ $room->code }}</td>
                                    <td class="p-3 font-bold">
                                        @if ($room->winner_player_id)
                                            <span class="text-amber-400">Victoria</span>
                                        @elseif ($room->status === 'cancelled')
                                            <span class="text-rose-400">Cancelada</span>
                                        @else
                                            <span class="text-slate-300">Empate</span>
                                        @endif
                                    </td>
                                    <td class="p-3 font-mono text-[11px] text-cyan-300">
                                        {{ $room->finish_reason ?: 'N/A' }}
                                    </td>
                                    <td class="p-3 font-mono">{{ $room->total_bombs_detonated }}</td>
                                    <td class="p-3 font-mono">{{ $room->total_blocks_destroyed }}</td>
                                    <td class="p-3 font-mono">{{ $room->total_eliminations }}</td>
                                    <td class="p-3 font-mono">v{{ $room->rules_version ?? 1 }}</td>
                                    <td class="p-3 text-slate-400 font-mono text-[11px]">
                                        {{ $room->finished_at ? $room->finished_at->format('Y-m-d H:i:s') : '-' }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="p-6 text-center text-slate-500">Aún no hay partidas registradas en el historial.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Registro de Transiciones de Estado de Máquina -->
            <div class="cyber-panel p-5 sm:p-6 rounded-2xl">
                <div class="flex items-center gap-2 pb-3 border-b border-slate-800 mb-4">
                    <i data-lucide="git-commit" class="w-5 h-5 text-indigo-400"></i>
                    <h2 class="arcade-font text-sm sm:text-base font-black text-white">REGISTRO DE TRANSICIONES DE ESTADO (AUDIT LOG)</h2>
                </div>

                <div class="overflow-x-auto max-h-96">
                    <table class="w-full text-left text-xs">
                        <thead class="text-[11px] uppercase tracking-wider text-slate-400 bg-slate-900/60 border-b border-slate-800 sticky top-0">
                            <tr>
                                <th class="p-3">Sala</th>
                                <th class="p-3">Transición</th>
                                <th class="p-3">Motivo</th>
                                <th class="p-3">Vivos</th>
                                <th class="p-3">Tiempo Rest.</th>
                                <th class="p-3">Fecha</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/60 font-mono">
                            @forelse ($recentAudits as $audit)
                                <tr class="hover:bg-slate-900/40">
                                    <td class="p-3 text-cyan-400 font-bold">{{ $audit->room_code }}</td>
                                    <td class="p-3">
                                        <span class="text-slate-400">{{ $audit->from_state }}</span>
                                        <span class="text-indigo-400 mx-1">→</span>
                                        <span class="text-white font-bold">{{ $audit->to_state }}</span>
                                    </td>
                                    <td class="p-3 text-indigo-300">{{ $audit->reason }}</td>
                                    <td class="p-3">{{ $audit->alive_count }}</td>
                                    <td class="p-3">{{ $audit->remaining_seconds }}s</td>
                                    <td class="p-3 text-slate-400 text-[11px]">{{ $audit->occurred_at->format('H:i:s') }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="p-6 text-center text-slate-500">No hay transiciones registradas.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Historial de Versiones de Reglas -->
            <div class="cyber-panel p-5 sm:p-6 rounded-2xl">
                <div class="flex items-center gap-2 pb-3 border-b border-slate-800 mb-4">
                    <i data-lucide="history" class="w-5 h-5 text-cyan-400"></i>
                    <h2 class="arcade-font text-sm sm:text-base font-black text-white">HISTORIAL DE VERSIONES DE REGLAS</h2>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs font-mono">
                        <thead class="text-[11px] uppercase tracking-wider text-slate-400 bg-slate-900/60 border-b border-slate-800">
                            <tr>
                                <th class="p-3">Versión</th>
                                <th class="p-3">Estado</th>
                                <th class="p-3">Modificado por</th>
                                <th class="p-3">Resumen de Cambios</th>
                                <th class="p-3">Fecha</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/60">
                            @foreach ($history as $h)
                                <tr>
                                    <td class="p-3 font-bold text-cyan-400">v{{ $h->version }}</td>
                                    <td class="p-3">
                                        @if ($h->is_active)
                                            <span class="px-2 py-0.5 rounded bg-emerald-950 text-emerald-400 border border-emerald-500/40 font-bold text-[10px]">ACTIVA</span>
                                        @else
                                            <span class="text-slate-500">Histórica</span>
                                        @endif
                                    </td>
                                    <td class="p-3 text-slate-300">{{ $h->created_by_name ?: 'Sistema' }}</td>
                                    <td class="p-3 text-slate-400 font-sans text-xs">{{ $h->change_summary ?: '-' }}</td>
                                    <td class="p-3 text-slate-500 text-[11px]">{{ $h->created_at->format('Y-m-d H:i') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

    </main>

    <script>
        function switchTab(tab) {
            ['rules', 'matches', 'audits'].forEach(t => {
                const el = document.getElementById(`tab-${t}`);
                const btn = document.getElementById(`tab-btn-${t}`);
                if (t === tab) {
                    el.classList.remove('hidden');
                    btn.classList.add('active');
                } else {
                    el.classList.add('hidden');
                    btn.classList.remove('active');
                }
            });
        }

        async function refreshMatchesData() {
            try {
                const res = await fetch('{{ route('admin.matches.api') }}');
                const data = await res.json();
                const tbody = document.getElementById('active-matches-tbody');
                const countBadge = document.getElementById('count-active-matches');

                if (countBadge && data.rooms) {
                    const activeCount = data.rooms.filter(r => r.status === 'playing' || r.status === 'waiting' || r.status === 'ready').length;
                    countBadge.innerText = activeCount;
                }

                if (tbody && data.rooms) {
                    tbody.innerHTML = '';
                    const actives = data.rooms.filter(r => r.status === 'playing' || r.status === 'waiting' || r.status === 'ready');
                    if (actives.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="7" class="p-6 text-center text-slate-500">No hay salas activas en este momento.</td></tr>';
                        return;
                    }
                    actives.forEach(r => {
                        const mins = String(Math.floor(r.remaining_seconds / 60)).padStart(2, '0');
                        const secs = String(r.remaining_seconds % 60).padStart(2, '0');
                        const tr = document.createElement('tr');
                        tr.className = 'hover:bg-slate-900/40 transition-colors';
                        tr.innerHTML = `
                            <td class="p-3 font-mono font-bold text-cyan-400">
                                <a href="/arena/${r.code}" target="_blank" class="hover:underline">${r.code} ↗</a>
                            </td>
                            <td class="p-3">
                                <span class="px-2 py-0.5 rounded-full font-bold text-[10px] uppercase ${r.status === 'playing' ? 'bg-emerald-950 text-emerald-400 border border-emerald-500/40' : 'bg-amber-950 text-amber-400 border border-amber-500/40'}">
                                    ${r.status}
                                </span>
                            </td>
                            <td class="p-3"><span class="font-bold text-white">${r.alive_players}</span> / ${r.player_count}</td>
                            <td class="p-3 font-mono">${mins}:${secs}</td>
                            <td class="p-3 font-mono font-bold text-rose-400">${r.active_bombs}</td>
                            <td class="p-3 font-mono">v${r.rules_version || 1}</td>
                            <td class="p-3 text-right">
                                <button type="button" onclick="cancelRoom('${r.code}')" class="px-2.5 py-1 rounded bg-rose-950/70 hover:bg-rose-900 text-rose-300 border border-rose-500/40 font-bold text-[11px] cursor-pointer">
                                    Cancelar
                                </button>
                            </td>
                        `;
                        tbody.appendChild(tr);
                    });
                }
            } catch (e) {
                console.error(e);
            }
        }

        async function cancelRoom(code) {
            if (!confirm(`¿Seguro que deseas cancelar la sala ${code}?`)) return;
            try {
                const res = await fetch(`/admin/partidas/${code}/cancelar`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Accept': 'application/json'
                    }
                });
                refreshMatchesData();
            } catch (e) {
                console.error(e);
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            if (window.lucide && window.lucide.createIcons) {
                window.lucide.createIcons();
            }
            setInterval(refreshMatchesData, 5000);
        });
    </script>
</body>
</html>
