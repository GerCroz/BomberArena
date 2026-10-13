/**
 * Bomber Arena - Original Power-up Sprites & Floating Animations
 * Types:
 * - Speed (⚡): Cyan/Green hyper-thruster lightning core, floating bob
 * - Bomb (💣): Violet dual-ordnance canister, pulsing radial beacon
 * - Fire (🔥): Yellow thermal nova star, radiating orbital rings
 */
import { THEME } from '../theme.js';

export function drawPowerup(ctx, col, row, type, animTime = 0, tileSize = 50) {
    const cx = col * tileSize + tileSize / 2;
    const cy = row * tileSize + tileSize / 2;

    // Gentle floating sine motion
    const floatY = Math.sin(animTime * 5 + col * 0.5) * 3;
    const y = cy + floatY;

    ctx.save();

    // 1. Soft contact shadow on ground
    ctx.fillStyle = 'rgba(5, 8, 17, 0.6)';
    ctx.beginPath();
    ctx.ellipse(cx, cy + 15, 10, 3.5, 0, 0, Math.PI * 2);
    ctx.fill();

    // 2. Translucent glowing energy containment badge
    let mainColor = THEME.colors.cyan;
    let badgeGlow = 'rgba(56, 189, 248, 0.4)';
    if (type === 'bomb') {
        mainColor = THEME.colors.violet;
        badgeGlow = 'rgba(167, 139, 250, 0.4)';
    } else if (type === 'fire') {
        mainColor = THEME.colors.yellow;
        badgeGlow = 'rgba(251, 191, 36, 0.4)';
    }

    // Hexagonal badge plate
    ctx.save();
    ctx.shadowColor = mainColor;
    ctx.shadowBlur = 10;
    ctx.fillStyle = '#111827';
    ctx.strokeStyle = mainColor;
    ctx.lineWidth = 1.5;

    ctx.beginPath();
    const r = 14;
    for (let i = 0; i < 6; i++) {
        const a = (i * Math.PI) / 3 - Math.PI / 6;
        const px = cx + r * Math.cos(a);
        const py = y + r * Math.sin(a);
        if (i === 0) ctx.moveTo(px, py);
        else ctx.lineTo(px, py);
    }
    ctx.closePath();
    ctx.fill();
    ctx.stroke();
    ctx.restore();

    // 3. Draw Unique Geometric Tech Glyphs
    if (type === 'speed') {
        // --- LIGHTNING BOLT ---
        ctx.fillStyle = THEME.colors.cyan;
        ctx.shadowColor = THEME.colors.cyan;
        ctx.shadowBlur = 8;
        ctx.beginPath();
        ctx.moveTo(cx + 1, y - 8);
        ctx.lineTo(cx - 5, y + 1);
        ctx.lineTo(cx, y + 1);
        ctx.lineTo(cx - 2, y + 8);
        ctx.lineTo(cx + 6, y - 1);
        ctx.lineTo(cx + 1, y - 1);
        ctx.closePath();
        ctx.fill();

    } else if (type === 'bomb') {
        // --- DUAL ORDNANCE CANISTER ---
        ctx.fillStyle = THEME.colors.violet;
        ctx.shadowColor = THEME.colors.violet;
        ctx.shadowBlur = 8;
        // Primary bomb sphere
        ctx.beginPath();
        ctx.arc(cx - 2, y + 1, 6.5, 0, Math.PI * 2);
        ctx.fill();
        // Valve
        ctx.fillRect(cx - 4, y - 8, 4, 3);
        // Fuse spark
        ctx.fillStyle = '#F8FAFC';
        ctx.beginPath();
        ctx.arc(cx - 2, y - 9, 1.5, 0, Math.PI * 2);
        ctx.fill();

        // Secondary '+1' mini indicator
        ctx.strokeStyle = '#FFFFFF';
        ctx.lineWidth = 1.8;
        ctx.beginPath();
        // Plus sign
        ctx.moveTo(cx + 5, y - 3); ctx.lineTo(cx + 5, y + 3);
        ctx.moveTo(cx + 2, y); ctx.lineTo(cx + 8, y);
        ctx.stroke();

    } else if (type === 'fire') {
        // --- EXPANDED NOVA BLAST STAR ---
        ctx.fillStyle = THEME.colors.yellow;
        ctx.shadowColor = THEME.colors.yellow;
        ctx.shadowBlur = 10;
        // 4-point star
        ctx.beginPath();
        ctx.moveTo(cx, y - 9);
        ctx.quadraticCurveTo(cx, y, cx + 9, y);
        ctx.quadraticCurveTo(cx, y, cx, y + 9);
        ctx.quadraticCurveTo(cx, y, cx - 9, y);
        ctx.quadraticCurveTo(cx, y, cx, y - 9);
        ctx.closePath();
        ctx.fill();

        // Radiant core circle
        ctx.fillStyle = '#FFFFFF';
        ctx.beginPath();
        ctx.arc(cx, y, 2.5, 0, Math.PI * 2);
        ctx.fill();
    }

    ctx.restore();
}
