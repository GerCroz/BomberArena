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

    render(gameState, currentUserId, animTime = 0) {
        const ctx = this.ctx;
        const ts = this.tileSize;
        const half = ts / 2;
        const now = performance.now();
        const dt = Math.min(0.1, (now - this.lastFrame) / 1000);
        this.lastFrame = now;

        if (!gameState || !gameState.grid) {
            ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
            return;
        }

        // --- LAYER 1: STATIC BOARD (cached) ---
        let blocks = 0;
        const grid = gameState.grid;
        for (let y = 0; y < grid.length; y++) {
            const row = grid[y];
            for (let x = 0; x < row.length; x++) if (row[x] === 2) blocks++;
        }
        const signature = `${gameState.seed}|${this.portrait}|${blocks}`;
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
                drawBomb(ctx, s.col * ts + half, s.row * ts + half, ts, bomb, motionTime);
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
                (a.player_id === currentUserId) - (b.player_id === currentUserId));

            const lerp = this.reducedMotion ? 1 : Math.min(1, dt * 20);

            for (const player of list) {
                const s = this.toScreen(player.x, player.y);
                const tx = s.col * ts + half;
                const ty = s.row * ts + half;

                let dp = this.displayPos.get(player.player_id);
                if (!dp || Math.abs(dp.x - tx) > ts * 2.5 || Math.abs(dp.y - ty) > ts * 2.5) {
                    dp = { x: tx, y: ty };
                    this.displayPos.set(player.player_id, dp);
                } else {
                    dp.x += (tx - dp.x) * lerp;
                    dp.y += (ty - dp.y) * lerp;
                }

                const travelling = Math.abs(tx - dp.x) + Math.abs(ty - dp.y) > 1;
                const recentlyMoved = player._movedAt && now - player._movedAt < 180;

                drawCharacter(ctx, dp.x, dp.y, ts, {
                    ...player,
                    direction: this.logicalToScreenDir(player.direction || 'down'),
                    isMoving: travelling || recentlyMoved,
                }, motionTime, player.player_id === currentUserId);
            }
        }
    }
}
