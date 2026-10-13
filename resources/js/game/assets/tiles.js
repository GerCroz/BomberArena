/**
 * Bomber Arena - Cyberpunk Grid & Tile Renderer
 * Features:
 * - Tech floor grid with subtle circuit seams
 * - Reinforced alloy indestructible walls with cyan conduits and corner bevels
 * - Quantum storage destructible blocks with hazard warning stripes and amber energy diodes
 */
import { THEME } from '../theme.js';

export function drawTile(ctx, col, row, cellType, tileSize = 50) {
    const x = col * tileSize;
    const y = row * tileSize;

    if (cellType === 0) {
        // --- 1. FREE TECH FLOOR TILE ---
        ctx.fillStyle = THEME.colors.bgMain;
        ctx.fillRect(x, y, tileSize, tileSize);

        // Subtle tech grid borders
        ctx.strokeStyle = 'rgba(35, 48, 82, 0.4)';
        ctx.lineWidth = 1;
        ctx.strokeRect(x + 0.5, y + 0.5, tileSize - 1, tileSize - 1);

        // Center micro-circuit dot
        ctx.fillStyle = 'rgba(56, 189, 248, 0.12)';
        ctx.fillRect(x + 24, y + 24, 2, 2);

    } else if (cellType === 1) {
        // --- 2. INDESTRUCTIBLE REINFORCED METAL WALL ---
        // Base alloy
        const wallGrad = ctx.createLinearGradient(x, y, x + tileSize, y + tileSize);
        wallGrad.addColorStop(0, '#1E294B');
        wallGrad.addColorStop(1, '#0F172A');
        ctx.fillStyle = wallGrad;
        ctx.fillRect(x, y, tileSize, tileSize);

        // Inner beveled plate
        ctx.fillStyle = '#12192E';
        ctx.fillRect(x + 4, y + 4, tileSize - 8, tileSize - 8);

        // Outer reinforced border
        ctx.strokeStyle = '#2A3B66';
        ctx.lineWidth = 1.5;
        ctx.strokeRect(x + 1, y + 1, tileSize - 2, tileSize - 2);

        // Cyan neon energy conduit cross in center
        ctx.strokeStyle = THEME.colors.cyan;
        ctx.lineWidth = 1.5;
        ctx.beginPath();
        // Corner brackets
        ctx.moveTo(x + 8, y + 14); ctx.lineTo(x + 8, y + 8); ctx.lineTo(x + 14, y + 8);
        ctx.moveTo(x + tileSize - 8, y + 14); ctx.lineTo(x + tileSize - 8, y + 8); ctx.lineTo(x + tileSize - 14, y + 8);
        ctx.moveTo(x + 8, y + tileSize - 14); ctx.lineTo(x + 8, y + tileSize - 8); ctx.lineTo(x + 14, y + tileSize - 8);
        ctx.moveTo(x + tileSize - 8, y + tileSize - 14); ctx.lineTo(x + tileSize - 8, y + tileSize - 8); ctx.lineTo(x + tileSize - 14, y + tileSize - 8);
        ctx.stroke();

        // Central metal rivet
        ctx.fillStyle = '#38BDF8';
        ctx.beginPath();
        ctx.arc(x + 25, y + 25, 2.5, 0, Math.PI * 2);
        ctx.fill();

    } else if (cellType === 2) {
        // --- 3. DESTRUCTIBLE QUANTUM STORAGE CRATE ---
        // Drop shadow for 2.5D depth
        ctx.fillStyle = 'rgba(5, 8, 17, 0.5)';
        ctx.fillRect(x + 2, y + 4, tileSize - 4, tileSize - 4);

        // Main Crate Body
        const crateGrad = ctx.createLinearGradient(x, y, x, y + tileSize);
        crateGrad.addColorStop(0, '#28334E');
        crateGrad.addColorStop(1, '#151D31');
        ctx.fillStyle = crateGrad;
        ctx.fillRect(x + 2, y + 2, tileSize - 4, tileSize - 4);

        // Metal framing
        ctx.strokeStyle = '#3E4F7A';
        ctx.lineWidth = 1.5;
        ctx.strokeRect(x + 3, y + 3, tileSize - 6, tileSize - 6);

        // Hazard amber diagonal stripes / corners
        ctx.fillStyle = THEME.colors.blockAccent;
        // Top-left hazard notch
        ctx.beginPath();
        ctx.moveTo(x + 6, y + 6); ctx.lineTo(x + 14, y + 6); ctx.lineTo(x + 6, y + 14);
        ctx.closePath();
        ctx.fill();
        // Bottom-right hazard notch
        ctx.beginPath();
        ctx.moveTo(x + tileSize - 6, y + tileSize - 6);
        ctx.lineTo(x + tileSize - 14, y + tileSize - 6);
        ctx.lineTo(x + tileSize - 6, y + tileSize - 14);
        ctx.closePath();
        ctx.fill();

        // Amber energy core vent
        ctx.fillStyle = '#0B1020';
        ctx.fillRect(x + 14, y + 14, tileSize - 28, tileSize - 28);

        // Glowing vent slits
        ctx.strokeStyle = THEME.colors.blockAccent;
        ctx.lineWidth = 1.5;
        ctx.beginPath();
        ctx.moveTo(x + 17, y + 21); ctx.lineTo(x + tileSize - 17, y + 21);
        ctx.moveTo(x + 17, y + 25); ctx.lineTo(x + tileSize - 17, y + 25);
        ctx.moveTo(x + 17, y + 29); ctx.lineTo(x + tileSize - 17, y + 29);
        ctx.stroke();
    }
}
