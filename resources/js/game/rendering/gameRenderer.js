/**
 * Bomber Arena - Modular High-Performance Canvas Renderer
 * Orchestrates rendering passes:
 * Layer 1: Tech Floor Grid
 * Layer 2: Blocks (Walls & Crates)
 * Layer 3: Power-ups (Hovering)
 * Layer 4: Bombs (Metallic sphere, core pulse, countdown arc)
 * Layer 5: Explosions & Crumble Particles
 * Layer 6: Players (Directional robots, shadows, visors, halos)
 */
import { THEME } from '../theme.js';
import { drawTile } from '../assets/tiles.js';
import { drawPowerup } from '../assets/powerups.js';
import { drawBomb } from '../assets/bombs.js';
import { drawCharacter } from '../assets/characters.js';
import { ExplosionEffect } from '../assets/explosions.js';

export class GameRenderer {
    constructor(canvas) {
        this.canvas = canvas;
        this.ctx = canvas.getContext('2d');
        this.tileSize = 50;
        this.activeExplosions = [];
        this.prefersReducedMotion = false;

        if (typeof window !== 'undefined' && window.matchMedia) {
            this.prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        }
    }

    addExplosion(tiles, destroyedBlocks = []) {
        this.activeExplosions.push(new ExplosionEffect(tiles, destroyedBlocks, this.tileSize));
    }

    render(gameState, currentUserId, animTime = 0) {
        const ctx = this.ctx;
        const canvas = this.canvas;
        const tileSize = this.tileSize;

        // Clean Canvas
        ctx.clearRect(0, 0, canvas.width, canvas.height);

        if (!gameState || !gameState.grid) return;

        const grid = gameState.grid;
        const rows = grid.length;
        const cols = grid[0].length;

        // --- LAYER 1 & 2: GRID TILES (FLOOR & BLOCKS) ---
        for (let r = 0; r < rows; r++) {
            for (let c = 0; c < cols; c++) {
                drawTile(ctx, c, r, grid[r][c], tileSize);
            }
        }

        // --- LAYER 3: POWER-UPS ---
        if (gameState.powerups) {
            Object.entries(gameState.powerups).forEach(([key, type]) => {
                const [col, row] = key.split('_').map(Number);
                drawPowerup(ctx, col, row, type, animTime, tileSize);
            });
        }

        // --- LAYER 4: BOMBS ---
        if (gameState.bombs) {
            Object.values(gameState.bombs).forEach(bomb => {
                const bx = bomb.x * tileSize + tileSize / 2;
                const by = bomb.y * tileSize + tileSize / 2;
                drawBomb(ctx, bx, by, tileSize, bomb, animTime);
            });
        }

        // --- LAYER 5: EXPLOSIONS & DEBRIS PARTICLES ---
        for (let i = this.activeExplosions.length - 1; i >= 0; i--) {
            const exp = this.activeExplosions[i];
            exp.draw(ctx);
            if (exp.isFinished()) {
                this.activeExplosions.splice(i, 1);
            }
        }

        // --- LAYER 6: PLAYERS (ORIGINAL ARCADE ROBOTS) ---
        if (gameState.players) {
            // Render non-local players first, local player on top
            const playerList = Object.values(gameState.players);
            playerList.sort((a, b) => {
                if (a.player_id === currentUserId) return 1;
                if (b.player_id === currentUserId) return -1;
                return 0;
            });

            playerList.forEach(player => {
                const px = player.x * tileSize + tileSize / 2;
                const py = player.y * tileSize + tileSize / 2;
                const isLocal = player.player_id === currentUserId;
                drawCharacter(ctx, px, py, tileSize, player, animTime, isLocal);
            });
        }
    }
}
