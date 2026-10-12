# 🎮 Bomber Arena — Videojuego Multijugador en Tiempo Real

Videojuego web multijugador retro-arcade inspirado en Bomberman, desarrollado con **Laravel 13**, **Laravel Reverb (WebSockets)**, **Laravel Echo**, **HTML5 Canvas** y **Tailwind CSS**.

Incluye un **Panel de Monitoreo Educativo de WebSockets** en tiempo real diseñado para docentes, donde se visualiza el tráfico de eventos, marcas de tiempo, origen/destino lógico y estado de cada partida sin alterar el rendimiento del juego.

---

## 🚀 Requisitos del Sistema

- **PHP 8.2 o superior** (probado y optimizado en PHP 8.3 / 8.4) con extensiones `pdo_sqlite` (o `pdo_mysql`), `curl`, `mbstring`, `openssl`, `gd`.
- **Composer 2.x**.
- **Node.js 18+** y **npm**.
- **Laravel Reverb** (servidor WebSocket oficial de Laravel).

---

## 📦 Instalación Rápida

1. **Clonar e instalar dependencias backend:**
   ```bash
   composer install
   ```

2. **Copiar variables de entorno y generar clave:**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

3. **Ejecutar migraciones de base de datos:**
   ```bash
   php artisan migrate
   ```

4. **Instalar y compilar dependencias frontend (Vite & Tailwind CSS):**
   ```bash
   npm install
   npm run build
   ```

---

## ⚙️ Configuración de Variables de Entorno (`.env`)

Asegúrate de contar con los siguientes parámetros en tu archivo `.env`:

```env
APP_NAME="Bomber Arena"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000

# Base de datos
DB_CONNECTION=sqlite

# Cola inmediata para broadcasting sin delay
QUEUE_CONNECTION=sync

# Configuración de Laravel Reverb (WebSockets)
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=466440
REVERB_APP_KEY=lm7sk26ardtla9bwwuzd
REVERB_APP_SECRET=kep9l4bortajayuzvlcl
REVERB_HOST="127.0.0.1"
REVERB_PORT=8080
REVERB_SCHEME=http

# Parámetros expuestos al cliente Vite / Laravel Echo
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

---

## 🕹️ Cómo Iniciar el Proyecto Localmente

Para levantar el entorno completo se requieren dos servicios en terminales separadas:

### Terminal 1: Servidor Web Laravel
```bash
php artisan serve --host=127.0.0.1 --port=8000
```
Acceso web: **[http://127.0.0.1:8000](http://127.0.0.1:8000)**

### Terminal 2: Servidor WebSocket Laravel Reverb
```bash
php artisan reverb:start --debug
```
Escuchando en: **`ws://127.0.0.1:8080`**

*(Opcional: Si vas a modificar CSS o JS en vivo, podés ejecutar `npm run dev` en una tercera terminal).*

---

## 🌐 Multijugador por Internet (Túnel Cloudflare)

Para jugar con amigos o alumnos fuera de tu red local (a través de Internet en PCs, laptops o celulares con 4G):

1. Con los servidores de Laravel (`serve`) y Reverb (`reverb:start`) corriendo, ejecutá:
   ```bash
   .\iniciar-tunel.bat
   ```
   *(O manualmente: `node tunnel-gateway.cjs` y en otra terminal `.\cloudflared.exe tunnel --url http://127.0.0.1:8090`)*

2. Cloudflare te generará una URL pública segura HTTPS gratuita (ej: `https://xxx.trycloudflare.com`).
3. Compartí ese enlace: los jugadores podrán conectarse directamente desde sus navegadores y teléfonos móviles con WebSockets SSL (`wss://`) sin configuración adicional ni problemas de Mixed Content.

---

## 🎓 Accesos del Sistema

| Módulo | Ruta | Descripción |
| :--- | :--- | :--- |
| **Lobby de Juego** | `http://127.0.0.1:8000/lobby` | Ingreso de apodo, creación de salas y unión mediante código de 6 caracteres. |
| **Arena de Combate** | `http://127.0.0.1:8000/arena/{CODIGO}` | Tablero 13x11 interactivo en HTML5 Canvas con soporte para PC y móviles. |
| **Panel Docente** | `http://127.0.0.1:8000/docente/monitoreo` | Telemetría WebSocket en vivo: paquetes, latencia, origen/destino y auditoría JSON. |
| **Ejercicio Base** | `http://127.0.0.1:8000/receptor` y `/emisor` | Transmisión de texto y estilos CSS del ejercicio inicial del documento. |

---

## 🏗️ Arquitectura del Sistema

El proyecto implementa una arquitectura modular con **servidor autoritativo**, donde el cliente envía comandos de intención y el servidor valida y emite el estado oficial:

```
┌────────────────────────────────────────────────────────┐
│                        CLIENTE                         │
│  Lobby / HTML5 Canvas / Controles PC & Touch / Echo    │
└────────────┬─────────────────────────────▲─────────────┘
             │ Comandos HTTP               │ Eventos WebSocket
             │ (move, bomb, ready)         │ (arena.{code})
             ▼                             │
┌──────────────────────────────┐           │
│    BomberGameController      │           │
└────────────┬─────────────────┘           │
             ▼                             │
┌──────────────────────────────┐           │
│        RoomService           │           │
│  - Gestión de ciclo de sala  │           │
│  - Control de sesiones       │           │
└────────────┬─────────────────┘           │
             ▼                             │
┌──────────────────────────────┐           │
│      BomberGameEngine        │           │
│  - Matriz 13x11 y spawn safe │           │
│  - Colisiones y bloqueos     │           │
│  - Detonaciones en cruz      │           │
│  - Reacciones en cadena      │           │
│  - Power-ups y eliminaciones │           │
└────────────┬─────────────────┘           │
             ▼                             │
┌──────────────────────────────┐           │
│     Laravel Reverb Server    ├───────────┘
│  (Broadcaster en puerto 8080)│
└────────────┬─────────────────┘
             │ Espejo de telemetría (instructor-monitor)
             ▼
┌──────────────────────────────┐
│  Panel Docente de Telemetría │
│  - Eventos, timestamps, ms   │
│  - Inspector JSON de estado  │
└──────────────────────────────┘
```

---

## 📡 Protocolo y Eventos WebSocket

### 1. Canal de Sala: `arena.{CODIGO}`
- `player_joined`: Notifica cuando un nuevo jugador ingresa a la sala (hasta 4).
- `player_ready`: Estado de preparación de los jugadores.
- `game_started`: Inicia la partida con el snapshot inicial validado por el servidor.
- `player_moved`: Actualización incremental de coordenadas `(x, y)` y dirección.
- `bomb_placed`: Aparición de bomba con mecha de 2 segundos.
- `bomb_exploded`: Detonación en cruz, bloques rotos, power-ups generados y eliminaciones.
- `game_over`: Proclamación de ganador o empate con estadísticas de combate.

### 2. Canal del Docente: `instructor-monitor`
- `TelemetryEvent`: Registra cada interacción en tiempo real con marca de tiempo precisa a milisegundos, latencia estimada, origen (`Piloto X`, `Servidor`), destino (`arena.ABC123`) y payload detallado para fines pedagógicos.

---

## 💣 Reglas del Videojuego

1. **Tablero**: Cuadrícula de 13 columnas por 11 filas con bordes indestructibles, pilares fijos y bloques de energía rompibles.
2. **Spawns Protegidos**: Las 4 esquinas disponen de 3 casillas despejadas para garantizar la seguridad inicial de los participantes.
3. **Bombas y Detonación**:
   - Cada jugador inicia con 1 bomba activa de alcance 2 casillas.
   - Detona en cruz tras 2 segundos.
   - Se detiene ante paredes fijas y destruye bloques destructibles.
   - **Reacción en cadena**: Si el fuego alcanza otra bomba, ésta detona instantáneamente.
   - Quien toque el fuego queda eliminado.
4. **Power-ups**:
   - ⚡ **Velocidad**: Mayor rapidez de desplazamiento.
   - 💣 **Bomba adicional**: Incrementa la cantidad de bombas simultáneas.
   - 🔥 **Mayor alcance**: Amplía la longitud de la explosión.
5. **Victoria**: El último jugador en pie gana. Si expira el tiempo o mueren todos simultáneamente, se declara empate.

---

## 🧪 Pruebas Automatizadas

El proyecto incluye pruebas automatizadas para validar el motor y la lógica de salas:

```bash
php artisan test
```

Cobertura de pruebas:
- Generación de tablero y zonas seguras (`BomberGameEngineTest`).
- Movimiento y colisión contra paredes (`BomberGameEngineTest`).
- Límite de bombas por jugador (`BomberGameEngineTest`).
- Detonación, eliminación y condiciones de victoria (`BomberGameEngineTest`).
- Reacción en cadena de bombas adyacentes (`BomberGameEngineTest`).
- Creación de salas, código único y anfitrión (`RoomServiceTest`).
- Restricción de capacidad máxima de 4 jugadores (`RoomServiceTest`).
- Regla de mínimo 2 jugadores para iniciar partida (`RoomServiceTest`).
