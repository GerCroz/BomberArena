/**
 * Bomber Arena - Futuristic Energy Explosions & Block Dissolution
 * Features:
 * - Bright plasma energy core
 * - Dynamic crossfire beams with distinct center nexus, corridor segments, and rounded terminal discharge caps
 * - Expanding shockwave rings & floating ion particles
 * - Destructible block digital dissolution animation
 */
import { THEME } from '../theme.js';

export class ExplosionEffect {
    constructor(tiles, destroyedBlocks = [], tileSize = 50) {
        this.tiles = tiles; // [{ x, y }]
        this.destroyedBlocks = destroyedBlocks; // [{ x, y }]
        this.tileSize = tileSize;
        this.startTime = performance.now();
        this.duration = 450; // ms
        this.particles = [];
        this.initParticles();
    }

    initParticles() {
        // Find center tile (or average center)
        let sumX = 0, sumY = 0;
        this.tiles.forEach(t => {
            sumX += t.x; sumY += t.y;
            // Spawn 3-5 spark particles per tile
            for (let i = 0; i < 4; i++) {
                const angle = Math.random() * Math.PI * 2;
                const speed = Math.random() * 3 + 1.5;
                this.particles.push({
                    x: t.x * this.tileSize + 25,
                    y: t.y * this.tileSize + 25,
                    vx: Math.cos(angle) * speed,
                    vy: Math.sin(angle) * speed,
                    size: Math.random() * 3 + 1.5,
                    color: Math.random() > 0.4 ? THEME.colors.yellow : (Math.random() > 0.5 ? THEME.colors.coral : '#FFFFFF'),
                    alpha: 1.0,
                });
            }
        });

        // Additional debris for destroyed blocks
        this.destroyedBlocks.forEach(b => {
            for (let i = 0; i < 8; i++) {
                const angle = Math.random() * Math.PI * 2;
                const speed = Math.random() * 4 + 2;
                this.particles.push({
                    x: b.x * this.tileSize + 25,
                    y: b.y * this.tileSize + 25,
                    vx: Math.cos(angle) * speed,
                    vy: Math.sin(angle) * speed,
                    size: Math.random() * 4 + 2,
                    color: THEME.colors.blockAccent,
                    alpha: 1.0,
                });
            }
        });
    }

    isFinished() {
        return (performance.now() - this.startTime) >= this.duration;
    }

    draw(ctx) {
        const now = performance.now();
        const elapsed = now - this.startTime;
        const progress = Math.min(1, elapsed / this.duration); // 0.0 to 1.0
        const fade = 1 - progress;

        ctx.save();

        // 1. Draw energy corridor on each explosion tile
        const tileSize = this.tileSize;
        const halfTile = tileSize / 2;

        this.tiles.forEach(tile => {
            const cx = tile.x * tileSize + halfTile;
            const cy = tile.y * tileSize + halfTile;

            // Danger zone backdrop
            ctx.fillStyle = `rgba(251, 113, 133, ${0.35 * fade})`;
            ctx.fillRect(tile.x * tileSize, tile.y * tileSize, tileSize, tileSize);

            // Plasma energy beam core
            const beamRadius = (tileSize * 0.42) * Math.sin(progress * Math.PI);

            // Outer glow
            ctx.shadowColor = THEME.colors.yellow;
            ctx.shadowBlur = 16 * fade;
            ctx.fillStyle = `rgba(251, 191, 36, ${0.85 * fade})`;
            ctx.beginPath();
            ctx.arc(cx, cy, beamRadius, 0, Math.PI * 2);
            ctx.fill();

            // Inner white-hot laser core
            ctx.shadowColor = '#FFFFFF';
            ctx.shadowBlur = 8 * fade;
            ctx.fillStyle = `rgba(255, 255, 255, ${fade})`;
            ctx.beginPath();
            ctx.arc(cx, cy, beamRadius * 0.55, 0, Math.PI * 2);
            ctx.fill();
        });

        // 2. Expanding shockwave ring at center
        if (this.tiles.length > 0) {
            const root = this.tiles[0]; // first tile is typically center
            const rx = root.x * tileSize + halfTile;
            const ry = root.y * tileSize + halfTile;
            const shockRadius = (progress * tileSize * 2);

            ctx.shadowBlur = 0;
            ctx.strokeStyle = `rgba(254, 240, 138, ${0.7 * fade})`;
            ctx.lineWidth = 3 * fade;
            ctx.beginPath();
            ctx.arc(rx, ry, shockRadius, 0, Math.PI * 2);
            ctx.stroke();
        }

        // 3. Block disintegration particles
        this.particles.forEach(p => {
            p.x += p.vx;
            p.y += p.vy;
            p.alpha = fade;

            ctx.fillStyle = p.color;
            ctx.shadowColor = p.color;
            ctx.shadowBlur = 6;
            ctx.globalAlpha = p.alpha;
            ctx.beginPath();
            ctx.arc(p.x, p.y, p.size * fade, 0, Math.PI * 2);
            ctx.fill();
        });

        ctx.restore();
    }
}
