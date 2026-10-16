/**
 * Bomber Arena - Modular High-Performance Canvas Renderer
 *
 * Performance strategy:
 *  - Static board (floor, walls, crates) is pre-rendered ONCE into an offscreen
 *    canvas and re-drawn only when a crate is destroyed or orientation changes.
 *  - Glow effects (shadowBlur) are disabled on touch / low-power devices, where
 *    they are by far the most expensive Canvas operation.
 *  - Player sprites are smoothly interpolated between tiles (purely visual —
 *    logical positions and collisions are never altered).
 *
 * Portrait mode:
 *  - On tall screens the 13x11 board is rotated 90° clockwise and displayed as
 *    11x13, so it fills the vertical length of a phone. Only the PROJECTION
 *    changes; game logic always works in logical (server) coordinates.
 *
 * Layers: board → power-ups → bombs → explosions → players
 */
import { drawTile } from '../assets/tiles.js';
import { drawPowerup } from '../assets/powerups.js';
import { drawBomb } from '../assets/bombs.js';
import { drawCharacter } from '../assets/characters.js';
import { ExplosionEffect } from '../assets/explosions.js';

export const LOGICAL_COLS = 13;
export const LOGICAL_ROWS = 11;

// Logical direction -> on-screen direction when board is rotated clockwise
const ROTATE_DIR = { up: 'right', right: 'down', down: 'left', left: 'up' };
// On-screen direction -> logical direction (inverse)
const UNROTATE_DIR = { right: 'up', down: 'right', left: 'down', up: 'left' };

export class GameRenderer {
    constructor(canvas, options = {}) {
        this.canvas = canvas;
        this.ctx = canvas.getContext('2d');
        this.tileSize = 50;
        this.activeExplosions = [];
        this.portrait = false;
        this.displayPos = new Map();
        this.lastFrame = performance.now();
        this.metrics = { frames: 0, fps: 0, visualCorrections: 0, maxVisualError: 0, maxInterpolationAgeMs: 0 };
        this.frameWindowStarted = this.lastFrame;

        // Offscreen cache for the static board
        this.boardLayer = document.createElement('canvas');
        this.boardCtx = this.boardLayer.getContext('2d');
        this.boardSignature = null;

        const mq = (q) => typeof window !== 'undefined' && window.matchMedia && window.matchMedia(q).matches;
        this.reducedMotion = mq('(prefers-reduced-motion: reduce)');
        this.lowFx = options.lowFx ?? (mq('(pointer: coarse)') || this.reducedMotion);

        if (this.lowFx) {
            // shadowBlur is extremely costly on mobile GPUs: neutralise it on the
            // live context only (the cached board can keep its effects).
            Object.defineProperty(this.ctx, 'shadowBlur', { get: () => 0, set: () => {}, configurable: true });
        }

        this.setPortrait(false);
    }

    /** Switch between landscape (13x11) and rotated portrait (11x13) projection. */
    setPortrait(portrait) {
        this.portrait = !!portrait;
        const cols = this.portrait ? LOGICAL_ROWS : LOGICAL_COLS;
        const rows = this.portrait ? LOGICAL_COLS : LOGICAL_ROWS;
        const w = cols * this.tileSize;
        const h = rows * this.tileSize;

        if (this.canvas.width !== w || this.canvas.height !== h) {
            this.canvas.width = w;
            this.canvas.height = h;
        }
        this.boardLayer.width = w;
        this.boardLayer.height = h;
        this.boardSignature = null;
        this.displayPos.clear();
    }

    /** Convert a logical tile to on-screen tile coordinates. */
    toScreen(x, y) {
        return this.portrait ? { col: LOGICAL_ROWS - 1 - y, row: x } : { col: x, row: y };
    }

    /** Map an on-screen direction (D-pad / arrows) to a logical direction. */
    screenToLogicalDir(dir) {
        return this.portrait ? (UNROTATE_DIR[dir] || dir) : dir;
    }

    logicalToScreenDir(dir) {
        return this.portrait ? (ROTATE_DIR[dir] || dir) : dir;
    }

    /** Force board cache rebuild (e.g. new match). */
    invalidateBoard() {
        this.boardSignature = null;
        this.displayPos.clear();
    }

    addExplosion(tiles, destroyedBlocks = []) {
        const map = (t) => {
            const s = this.toScreen(t.x, t.y);
            return { x: s.col, y: s.row };
        };
        this.activeExplosions.push(new ExplosionEffect(tiles.map(map), destroyedBlocks.map(map), this.tileSize));
    }

    rebuildBoard(grid) {
        const ctx = this.boardCtx;
        ctx.clearRect(0, 0, this.boardLayer.width, this.boardLayer.height);
        for (let y = 0; y < grid.length; y++) {
            for (let x = 0; x < grid[y].length; x++) {
                const s = this.toScreen(x, y);
                drawTile(ctx, s.col, s.row, grid[y][x], this.tileSize);
            }
        }
    }

    render(gameState, currentUserId, animTime = 0, currentServerTime = null) {
        const ctx = this.ctx;
        const ts = this.tileSize;
        const half = ts / 2;
        const now = performance.now();
        const dt = Math.min(0.1, (now - this.lastFrame) / 1000);
        this.lastFrame = now;
        this.metrics.frames++;
        if (now - this.frameWindowStarted >= 1000) {
            this.metrics.fps = this.metrics.frames * 1000 / (now - this.frameWindowStarted);
            this.metrics.frames = 0;
            this.frameWindowStarted = now;
        }

        if (!gameState || !gameState.grid) {
            ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
            return;
        }

        // --- LAYER 1: STATIC BOARD (cached) ---
        const grid = gameState.grid;
        const signature = `${gameState.seed}|${this.portrait}|${JSON.stringify(grid)}`;
        if (signature !== this.boardSignature) {
            this.rebuildBoard(grid);
            this.boardSignature = signature;
        }
        ctx.drawImage(this.boardLayer, 0, 0);

        const motionTime = this.reducedMotion ? 0 : animTime;

        // --- LAYER 2: POWER-UPS ---
        if (gameState.powerups) {
            for (const key in gameState.powerups) {
                const [x, y] = key.split('_').map(Number);
                const s = this.toScreen(x, y);
                drawPowerup(ctx, s.col, s.row, gameState.powerups[key], motionTime, ts);
            }
        }

        // --- LAYER 3: BOMBS ---
        if (gameState.bombs) {
            for (const id in gameState.bombs) {
                const bomb = gameState.bombs[id];
                const s = this.toScreen(bomb.x, bomb.y);
                drawBomb(ctx, s.col * ts + half, s.row * ts + half, ts, bomb, motionTime, currentServerTime);
            }
        }

        // --- LAYER 4: EXPLOSIONS ---
        for (let i = this.activeExplosions.length - 1; i >= 0; i--) {
            const exp = this.activeExplosions[i];
            exp.draw(ctx);
            if (exp.isFinished()) this.activeExplosions.splice(i, 1);
        }

        // --- LAYER 5: PLAYERS (interpolated, local player on top) ---
        if (gameState.players) {
            const list = Object.values(gameState.players).sort((a, b) =>
                (a.id === currentUserId) - (b.id === currentUserId));

            const lerp = this.reducedMotion ? 1 : Math.min(1, dt * 20);

            for (const id of this.displayPos.keys()) {
                if (!gameState.players[id]) this.displayPos.delete(id);
            }
            const candidates = new Map();
            for (const player of list) {
                const preview = player.alive && this.localPreview?.id === player.id ? this.localPreview : null;
                const s = this.toScreen(preview?.x ?? player.position_x ?? player.x, preview?.y ?? player.position_y ?? player.y);
                const target = { x: s.col * ts + half, y: s.row * ts + half };
                const old = this.displayPos.get(player.id);
                const distance = old ? Math.abs(old.x - target.x) + Math.abs(old.y - target.y) : 0;
                const diagonal = old && Math.abs(old.x - target.x) > 1 && Math.abs(old.y - target.y) > 1;
                const next = preview?.continuous || !old || !player.alive || diagonal || distance > ts + 1 || gameState.rules?.remote_interpolation === false
                    ? target : { x: old.x + (target.x - old.x) * lerp, y: old.y + (target.y - old.y) * lerp };
                next.targetX = target.x;
                next.targetY = target.y;
                next.changedAt = !old || old.targetX !== target.x || old.targetY !== target.y ? now : old.changedAt;
                if (old && player.alive && (diagonal || distance > ts + 1)) this.metrics.visualCorrections++;
                candidates.set(player.id, next);
            }
            const living = list.filter(player => player.alive);
            let conflict = false;
            for (let i = 0; i < living.length; i++) {
                for (let j = i + 1; j < living.length; j++) {
                    const a = candidates.get(living[i].id), b = candidates.get(living[j].id);
                    if (gameState.rules?.player_collisions !== false && Math.abs(a.x - b.x) < ts * 0.7 && Math.abs(a.y - b.y) < ts * 0.7) conflict = true;
                }
            }
            if (conflict) {
                this.metrics.visualCorrections++;
                for (const player of living) {
                    const s = this.toScreen(player.x, player.y);
                    candidates.set(player.id, { x: s.col * ts + half, y: s.row * ts + half });
                }
            }
            this.displayPos = candidates;

            for (const player of list) {
                const s = this.toScreen(player.position_x ?? player.x, player.position_y ?? player.y);
                const tx = s.col * ts + half;
                const ty = s.row * ts + half;

                const dp = this.displayPos.get(player.id);
                const error = Math.abs(tx - dp.x) + Math.abs(ty - dp.y);
                this.metrics.maxVisualError = Math.max(this.metrics.maxVisualError, error / ts);
                if (error > 1 && dp.changedAt !== undefined) this.metrics.maxInterpolationAgeMs = Math.max(this.metrics.maxInterpolationAgeMs, now - dp.changedAt);

                const travelling = Math.abs((dp.targetX ?? tx) - dp.x) + Math.abs((dp.targetY ?? ty) - dp.y) > 1;
                const recentlyMoved = player._movedAt && now - player._movedAt < 180;

                drawCharacter(ctx, dp.x, dp.y, ts, {
                    ...player,
                    direction: this.logicalToScreenDir((this.localPreview?.id === player.id ? this.localPreview.direction : player.direction) || 'down'),
                    isMoving: (this.localPreview?.id === player.id && this.localPreview.isMoving) || travelling || recentlyMoved,
                }, motionTime, player.id === currentUserId);
                if (this.diagnostics && player.alive) {
                    ctx.strokeStyle = '#00ffff';
                    ctx.strokeRect(tx - half, ty - half, ts, ts);
                    ctx.strokeStyle = '#ff00ff';
                    ctx.strokeRect(dp.x - ts * 0.35, dp.y - ts * 0.35, ts * 0.7, ts * 0.7);
                    ctx.fillStyle = '#ffffff';
                    ctx.font = '10px monospace';
                    ctx.fillText(`${player.slot ?? '?'} L:${player.x},${player.y} V:${((dp.x-half)/ts).toFixed(2)},${((dp.y-half)/ts).toFixed(2)}`, dp.x - half, dp.y - half);
                }
            }
        }
        if (this.diagnostics) {
            ctx.fillStyle = '#000000';
            ctx.fillRect(0, 0, this.canvas.width, 96);
            ctx.fillStyle = '#ffffff';
            ctx.font = '11px monospace';
            ctx.fillText(`Sala ${this.diagnostics.room} tick ${gameState.simulation_tick ?? gameState.tick ?? 0} v${gameState.version} seq ${this.diagnostics.sequence ?? gameState.input_sequences?.[currentUserId] ?? 0}`, 4, 13);
            ctx.fillText(`RTT WS ${Math.round(this.diagnostics.wsRtt ?? 0)}ms HTTP ${this.diagnostics.rtt.toFixed(0)}ms descartados ${this.diagnostics.staleStates} cambios ${this.diagnostics.corrections}`, 4, 26);
            ctx.fillText(`Ultimo ${Math.round(now-this.diagnostics.lastReceived)}ms eventos ${this.diagnostics.events} vivos ${Object.values(gameState.players).filter(p=>p.alive).length}`, 4, 39);
            ctx.fillText(`FPS ${this.metrics.fps.toFixed(0)} correcciones visuales ${this.metrics.visualCorrections} error max ${this.metrics.maxVisualError.toFixed(2)} casillas`, 4, 52);
            ctx.fillText(Object.values(gameState.players).map(p => `${p.slot ?? '?'}:${p.alive ? 'vivo' : 'eliminado'}`).join(' '), 4, 65);
            ctx.fillText(`Entrada ${this.diagnostics.pressed ?? 'quieto'} ACK ${this.diagnostics.acknowledged ?? 0} pendientes ${this.diagnostics.pending ?? 0} ${this.diagnostics.connection ?? ''}`,4,78);
            ctx.fillText(`Hz servidor ${(gameState.diagnostics?.actual_simulation_hz ?? 0).toFixed(1)} error ${(this.diagnostics.prediction_error ?? 0).toFixed(3)} bomba ${(this.diagnostics.bomb_confirmation_ms ?? 0).toFixed(0)}ms`,4,91);
        }
    }
}
