<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso Administrador — Bomber Arena</title>
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
            --neon-coral: #FB7185;
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
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .arcade-font {
            font-family: 'Orbitron', monospace;
            letter-spacing: 1px;
        }
        .cyber-panel {
            background: rgba(21, 29, 49, 0.95);
            backdrop-filter: blur(14px);
            border: 1px solid var(--panel-border);
            box-shadow: 0 0 40px rgba(56, 189, 248, 0.15), 0 10px 40px rgba(0, 0, 0, 0.6);
        }
    </style>
</head>
<body>

    <div class="w-full max-w-md cyber-panel p-6 sm:p-8 rounded-2xl">
        <div class="text-center mb-6">
            <div class="inline-flex p-3 rounded-2xl bg-cyan-950/60 border border-cyan-500/30 text-cyan-400 mb-3">
                <i data-lucide="shield-check" class="w-8 h-8"></i>
            </div>
            <h1 class="arcade-font text-xl sm:text-2xl font-black text-white">
                BOMBER ARENA <span class="text-cyan-400">ADMIN</span>
            </h1>
            <p class="text-slate-400 text-xs sm:text-sm mt-1">Configuración de Reglas y Monitoreo de Partidas</p>
        </div>

        @if ($errors->any())
            <div class="mb-5 p-3 rounded-xl bg-rose-950/70 border border-rose-500/40 text-rose-300 text-xs">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        @if (session('error'))
            <div class="mb-5 p-3 rounded-xl bg-rose-950/70 border border-rose-500/40 text-rose-300 text-xs">
                {{ session('error') }}
            </div>
        @endif

        <form action="{{ route('admin.login.submit') }}" method="POST" class="space-y-4">
            @csrf

            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-300 mb-1.5">
                    Correo Electrónico
                </label>
                <div class="relative">
                    <input type="email" name="email" value="{{ old('email', 'admin@bomberarena.com') }}" required autofocus
                        class="w-full bg-slate-900/90 border border-slate-700 focus:border-cyan-400 rounded-xl px-3.5 py-2.5 text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-cyan-400 transition-all font-mono">
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-300 mb-1.5">
                    Contraseña
                </label>
                <input type="password" name="password" required placeholder="••••••••"
                    class="w-full bg-slate-900/90 border border-slate-700 focus:border-cyan-400 rounded-xl px-3.5 py-2.5 text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-cyan-400 transition-all font-mono">
            </div>

            <div class="flex items-center justify-between text-xs text-slate-400 pt-1">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="remember" class="rounded bg-slate-900 border-slate-700 text-cyan-500 focus:ring-0">
                    <span>Recordar sesión</span>
                </label>
                <span class="text-[11px] text-slate-500 font-mono">Default: admin1234</span>
            </div>

            <button type="submit"
                class="w-full arcade-font mt-2 py-3 px-4 rounded-xl font-black text-xs sm:text-sm tracking-wider uppercase bg-gradient-to-r from-cyan-400 to-blue-600 text-slate-950 shadow-lg shadow-cyan-500/30 hover:shadow-cyan-500/60 transition-all flex items-center justify-center gap-2 cursor-pointer">
                <i data-lucide="key" class="w-4 h-4"></i>
                <span>INGRESAR AL PANEL</span>
            </button>
        </form>

        <div class="mt-6 pt-4 border-t border-slate-800 text-center">
            <a href="{{ route('lobby') }}" class="text-xs text-slate-400 hover:text-cyan-400 transition-colors inline-flex items-center gap-1 font-bold">
                <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i>
                <span>Volver al Lobby de Juego</span>
            </a>
        </div>
    </div>

</body>
</html>
