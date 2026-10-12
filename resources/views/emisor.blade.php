<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Emisor - WebSockets Reverb</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        :root {
            --bg-dark: #0f172a;
            --card-bg: rgba(30, 41, 59, 0.85);
            --border-color: rgba(255, 255, 255, 0.12);
            --accent: #6366f1;
            --accent-hover: #4f46e5;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
        }
        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            padding: 32px 16px;
            background: radial-gradient(circle at top, #1e1b4b 0%, #0f172a 100%);
            min-height: 100vh;
            color: var(--text-main);
            box-sizing: border-box;
        }
        .wrap {
            max-width: 760px;
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
            gap: 10px;
        }
        .badge {
            font-size: 12px;
            padding: 4px 10px;
            border-radius: 9999px;
            background: rgba(99, 102, 241, 0.2);
            border: 1px solid rgba(99, 102, 241, 0.4);
            color: #a5b4fc;
        }
        .nav-links a {
            color: #38bdf8;
            text-decoration: none;
            font-size: 14px;
            padding: 6px 12px;
            border-radius: 6px;
            background: rgba(56, 189, 248, 0.1);
            border: 1px solid rgba(56, 189, 248, 0.25);
            transition: all 0.2s;
        }
        .nav-links a:hover {
            background: rgba(56, 189, 248, 0.2);
        }
        .box {
            background: var(--card-bg);
            backdrop-filter: blur(12px);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.35);
        }
        label {
            display: block;
            font-weight: 600;
            font-size: 14px;
            color: #cbd5e1;
            margin-bottom: 6px;
        }
        input, textarea {
            width: 100%;
            padding: 12px 14px;
            border-radius: 8px;
            background: #090d16;
            border: 1px solid rgba(255, 255, 255, 0.15);
            color: #fff;
            font-family: inherit;
            font-size: 15px;
            box-sizing: border-box;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        textarea {
            font-family: 'Fira Code', 'Consolas', monospace;
            font-size: 13px;
            resize: vertical;
        }
        input:focus, textarea:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.25);
        }
        .presets {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 8px;
            margin-bottom: 4px;
        }
        .preset-btn {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.15);
            color: #e2e8f0;
            border-radius: 6px;
            padding: 4px 10px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .preset-btn:hover {
            background: rgba(255, 255, 255, 0.18);
            border-color: #a5b4fc;
        }
        button[type="submit"] {
            width: 100%;
            padding: 14px;
            border-radius: 8px;
            background: linear-gradient(135deg, #6366f1, #4f46e5);
            border: none;
            color: #fff;
            font-weight: 600;
            font-size: 16px;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(79, 70, 229, 0.4);
            transition: transform 0.15s, box-shadow 0.15s;
        }
        button[type="submit"]:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(79, 70, 229, 0.6);
        }
        button[type="submit"]:active {
            transform: translateY(1px);
        }
        .row {
            display: grid;
            gap: 18px;
        }
        .alert-success {
            background: rgba(16, 185, 129, 0.15);
            border: 1px solid rgba(16, 185, 129, 0.4);
            color: #6ee7b7;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .alert-error {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.4);
            color: #fca5a5;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 14px;
        }
        .instructions {
            margin-top: 24px;
            padding: 16px;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            font-size: 14px;
            color: var(--text-muted);
            line-height: 1.6;
        }
        .instructions code {
            background: rgba(99, 102, 241, 0.2);
            color: #c7d2fe;
            padding: 2px 6px;
            border-radius: 4px;
            font-family: monospace;
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="header">
        <h1>
            <span>🚀 Emisor</span>
            <span class="badge">Laravel 12 + Reverb</span>
        </h1>
        <div class="nav-links">
            <a href="{{ route('receptor') }}" target="_blank">Abrir Receptor ↗</a>
        </div>
    </div>

    @if (session('ok'))
        <div class="alert-success">
            <span>✓</span> {{ session('ok') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="alert-error">
            ⚠ {{ $errors->first() }}
        </div>
    @endif

    <form class="box" method="POST" action="{{ route('emitir') }}">
        @csrf
        <div class="row">
            <div>
                <label for="newText">Texto a mostrar</label>
                <input type="text" id="newText" name="newText" placeholder="Ej: Ahora atendiendo: A-037" value="¡Prueba en Vivo Reverb WebSocket!" required>
            </div>

            <div>
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <label for="newStyle">CSS inline (opcional)</label>
                    <div class="presets">
                        <button type="button" class="preset-btn" onclick="applyPreset('red')">Rojo Alerta</button>
                        <button type="button" class="preset-btn" onclick="applyPreset('gamer')">Gamer Neón</button>
                        <button type="button" class="preset-btn" onclick="applyPreset('clean')">Modern Clean</button>
                    </div>
                </div>
                <textarea id="newStyle" name="newStyle" rows="7" placeholder="Ej: background:#111;color:#fff;padding:24px;border-radius:16px;font-size:28px;text-align:center;">width: 80%;
max-width: 600px;
margin: 40px auto;
padding: 20px;
background-color: #e02424;
border: 2px solid #b91c1c;
border-radius: 12px;
box-shadow: 0 4px 15px rgba(220, 38, 38, 0.5);
color: #fff;
font-family: "Segoe UI", sans-serif;
font-size: 22px;
text-align: center;
transition: transform 0.2s ease, box-shadow 0.2s ease;</textarea>
            </div>

            <button type="submit" id="btn-emitir">📡 Emitir Evento WebSocket</button>
        </div>
    </form>

    <div class="instructions">
        💡 <strong>Instrucciones:</strong> Abrí <code><a href="{{ route('receptor') }}" target="_blank" style="color:#38bdf8;">/receptor</a></code> en otra pestaña o ventana del navegador para ver las actualizaciones en tiempo real a través del canal <code>style-channel</code>.
    </div>
</div>

<script>
    const presets = {
        red: `width: 80%;
max-width: 600px;
margin: 40px auto;
padding: 20px;
background-color: #e02424;
border: 2px solid #b91c1c;
border-radius: 12px;
box-shadow: 0 4px 15px rgba(220, 38, 38, 0.5);
color: #fff;
font-family: "Segoe UI", sans-serif;
font-size: 22px;
text-align: center;
transition: transform 0.2s ease, box-shadow 0.2s ease;`,
        gamer: `width: 85%;
max-width: 650px;
margin: 40px auto;
padding: 28px;
background: linear-gradient(135deg, #09090b, #18181b);
border: 2px solid #a855f7;
border-radius: 18px;
box-shadow: 0 0 25px rgba(168, 85, 247, 0.6), inset 0 0 15px rgba(168, 85, 247, 0.2);
color: #38bdf8;
font-family: monospace;
font-size: 24px;
font-weight: bold;
text-shadow: 0 0 10px rgba(56, 189, 248, 0.8);
text-align: center;`,
        clean: `width: 75%;
max-width: 550px;
margin: 40px auto;
padding: 24px;
background: #ffffff;
border: 1px solid #e2e8f0;
border-radius: 16px;
box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
color: #0f172a;
font-family: -apple-system, BlinkMacSystemFont, sans-serif;
font-size: 20px;
font-weight: 600;
text-align: center;`
    };

    function applyPreset(name) {
        if (presets[name]) {
            document.getElementById('newStyle').value = presets[name];
        }
    }
</script>
</body>
</html>
