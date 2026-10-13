/**
 * Bomber Arena - Original Character Sprites & Animation Engine
 * 4 Original Cyberpunk Combatant Robots
 * P1: Cyber Blue, P2: Neon Red, P3: Toxic Green, P4: Plasma Yellow
 */
import { THEME } from '../theme.js';

/**
 * Returns an inline SVG string of the character avatar for UI cards, lobby, and HUD
 */
export function getCharacterSVG(colorId, size = 64) {
    const meta = THEME.characters[colorId] || THEME.characters['neon-blue'];
    const { color, accent, visor, glow } = meta;

    // Distinct helmet features per character
    let helmetDecor = '';
    if (colorId === 'neon-blue') {
        // Dual agile cyber antennae
        helmetDecor = `
            <path d="M18 18 L12 10" stroke="${color}" stroke-width="2.5" stroke-linecap="round"/>
            <path d="M46 18 L52 10" stroke="${color}" stroke-width="2.5" stroke-linecap="round"/>
            <circle cx="12" cy="10" r="2" fill="${visor}"/>
            <circle cx="52" cy="10" r="2" fill="${visor}"/>
        `;
    } else if (colorId === 'neon-pink') {
        // Angular aggressive horn vents
        helmetDecor = `
            <polygon points="14,19 19,15 16,24" fill="${accent}"/>
            <polygon points="50,19 45,15 48,24" fill="${accent}"/>
            <line x1="28" y1="12" x2="36" y2="12" stroke="${color}" stroke-width="3" stroke-linecap="round"/>
        `;
    } else if (colorId === 'neon-green') {
        // Bio-energy dome & round ear vents
        helmetDecor = `
            <circle cx="15" cy="24" r="3.5" fill="${accent}"/>
            <circle cx="49" cy="24" r="3.5" fill="${accent}"/>
            <path d="M26 12 Q32 8 38 12" stroke="${visor}" stroke-width="2.5" fill="none" stroke-linecap="round"/>
        `;
    } else {
        // Plasma yellow lightning crest
        helmetDecor = `
            <polygon points="32,7 35,13 32,15 36,19 29,19 31,14" fill="${visor}"/>
            <line x1="16" y1="22" x2="13" y2="22" stroke="${color}" stroke-width="3" stroke-linecap="round"/>
            <line x1="48" y1="22" x2="51" y2="22" stroke="${color}" stroke-width="3" stroke-linecap="round"/>
        `;
    }

    return `
    <svg width="${size}" height="${size}" viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg">
        <defs>
            <filter id="glow-${colorId}" x="-20%" y="-20%" width="140%" height="140%">
                <feDropShadow dx="0" dy="0" stdDeviation="2.5" flood-color="${color}" flood-opacity="0.8"/>
            </filter>
            <linearGradient id="bodyGrad-${colorId}" x1="16" y1="12" x2="48" y2="52" gradientUnits="userSpaceOnUse">
                <stop stop-color="${color}"/>
                <stop offset="1" stop-color="${accent}"/>
            </linearGradient>
            <linearGradient id="visorGrad-${colorId}" x1="20" y1="23" x2="44" y2="33" gradientUnits="userSpaceOnUse">
                <stop stop-color="${visor}"/>
                <stop offset="1" stop-color="#0B132B"/>
            </linearGradient>
        </defs>

        <!-- Drop Shadow -->
        <ellipse cx="32" cy="58" rx="18" ry="4.5" fill="#050811" fill-opacity="0.7"/>

        <!-- Legs/Thrusters -->
        <rect x="23" y="47" width="6" height="8" rx="3" fill="#1E293B"/>
        <rect x="35" y="47" width="6" height="8" rx="3" fill="#1E293B"/>
        <circle cx="26" cy="54" r="1.5" fill="${color}"/>
        <circle cx="38" cy="54" r="1.5" fill="${color}"/>

        <!-- Torso -->
        <rect x="22" y="38" width="20" height="12" rx="4" fill="#0F172A" stroke="${accent}" stroke-width="1.5"/>
        <circle cx="32" cy="44" r="2.5" fill="${visor}" filter="url(#glow-${colorId})"/>

        <!-- Mini Arms -->
        <rect x="17" y="40" width="4.5" height="7" rx="2" fill="#1E293B"/>
        <rect x="42.5" y="40" width="4.5" height="7" rx="2" fill="#1E293B"/>

        <!-- Head / Helmet Base -->
        <rect x="16" y="14" width="32" height="26" rx="10" fill="url(#bodyGrad-${colorId})" stroke="#F8FAFC" stroke-width="1.2"/>

        <!-- Visor -->
        <rect x="20" y="22" width="24" height="12" rx="6" fill="#060A17" stroke="${color}" stroke-width="1.5"/>
        <rect x="22" y="24" width="20" height="8" rx="4" fill="url(#visorGrad-${colorId})" filter="url(#glow-${colorId})"/>
        <!-- Visor Gloss Reflection -->
        <path d="M24 25.5 Q32 25 38 27" stroke="#FFFFFF" stroke-width="1.2" stroke-linecap="round" stroke-opacity="0.75"/>

        <!-- Helmet Decor Features -->
        ${helmetDecor}
    </svg>
    `;
}

/**
 * Procedural Canvas Character Renderer with Fluid Animations
 */
export function drawCharacter(ctx, cx, cy, size, player, animTime = 0, isLocal = false) {
    const meta = THEME.characters[player.color] || THEME.characters['neon-blue'];
    const { color, accent, visor, glow } = meta;

    ctx.save();

    // 1. Check eliminated state
    if (!player.alive) {
        // Disintegrating digital hologram effect
        ctx.globalAlpha = Math.max(0, 0.5 + Math.sin(animTime * 10) * 0.3);
        ctx.fillStyle = '#64748B';
        ctx.beginPath();
        ctx.ellipse(cx, cy + 12, 10, 4, 0, 0, Math.PI * 2);
        ctx.fill();

        // Tombstone / Broken Core
        ctx.fillStyle = '#1E293B';
        ctx.strokeStyle = '#475569';
        ctx.lineWidth = 1.5;
        ctx.beginPath();
        ctx.roundRect(cx - 9, cy - 8, 18, 18, [6, 6, 2, 2]);
        ctx.fill();
        ctx.stroke();

        // Glitch skull / cross
        ctx.strokeStyle = '#EF4444';
        ctx.lineWidth = 2;
        ctx.beginPath();
        ctx.moveTo(cx - 4, cy - 2); ctx.lineTo(cx + 4, cy + 6);
        ctx.moveTo(cx + 4, cy - 2); ctx.lineTo(cx - 4, cy + 6);
        ctx.stroke();

        ctx.restore();
        return;
    }

    // 2. Animation calculations
    const isMoving = player.isMoving || false;
    const isPlacingBomb = player.isPlacingBomb || false;
    const direction = player.direction || 'down';

    // Bobbing idle offset
    let bobY = Math.sin(animTime * 4) * 1.5;
    let walkCycle = 0;
    let legOffset = 0;

    if (isMoving) {
        walkCycle = Math.sin(animTime * 14);
        legOffset = walkCycle * 3;
        bobY = Math.abs(walkCycle) * 2;
    }

    // Bomb placing recoil squeeze
    let scaleX = 1;
    let scaleY = 1;
    if (isPlacingBomb) {
        scaleX = 1.15;
        scaleY = 0.88;
    }

    // 3. Drop Shadow
    ctx.fillStyle = 'rgba(5, 8, 17, 0.75)';
    ctx.beginPath();
    ctx.ellipse(cx, cy + 18, 14, 4.5, 0, 0, Math.PI * 2);
    ctx.fill();

    // 4. Local Player Halo / Indicator
    if (isLocal) {
        ctx.save();
        ctx.strokeStyle = color;
        ctx.lineWidth = 1.5;
        ctx.shadowColor = color;
        ctx.shadowBlur = 8;
        ctx.beginPath();
        ctx.ellipse(cx, cy + 18, 16 + Math.sin(animTime * 5) * 1.5, 5.5, 0, 0, Math.PI * 2);
        ctx.stroke();

        // Direction pointer arrow
        ctx.fillStyle = visor;
        ctx.beginPath();
        ctx.moveTo(cx, cy - 26);
        ctx.lineTo(cx - 4, cy - 31);
        ctx.lineTo(cx + 4, cy - 31);
        ctx.closePath();
        ctx.fill();
        ctx.restore();
    }

    // Apply squish/stretch centered on player
    ctx.translate(cx, cy + bobY);
    ctx.scale(scaleX, scaleY);

    // 5. Thruster Legs
    ctx.fillStyle = '#0F172A';
    ctx.strokeStyle = accent;
    ctx.lineWidth = 1;

    // Left leg
    ctx.beginPath();
    ctx.roundRect(-8, 9 - legOffset, 5, 8, 2);
    ctx.fill(); ctx.stroke();

    // Right leg
    ctx.beginPath();
    ctx.roundRect(3, 9 + legOffset, 5, 8, 2);
    ctx.fill(); ctx.stroke();

    // Thruster energy soles
    ctx.fillStyle = visor;
    ctx.fillRect(-7, 16 - legOffset, 3, 1.5);
    ctx.fillRect(4, 16 + legOffset, 3, 1.5);

    // 6. Torso
    ctx.fillStyle = '#151D31';
    ctx.strokeStyle = accent;
    ctx.lineWidth = 1.5;
    ctx.beginPath();
    ctx.roundRect(-10, 0, 20, 12, 3);
    ctx.fill(); ctx.stroke();

    // Chest reactor core
    ctx.fillStyle = visor;
    ctx.shadowColor = color;
    ctx.shadowBlur = 6;
    ctx.beginPath();
    ctx.arc(0, 6, 2.5, 0, Math.PI * 2);
    ctx.fill();
    ctx.shadowBlur = 0;

    // 7. Arms
    ctx.fillStyle = '#1E293B';
    const armSwing = isMoving ? walkCycle * 2.5 : 0;
    // Left arm
    ctx.beginPath();
    ctx.roundRect(-14, 2 + armSwing, 4, 7, 2);
    ctx.fill();
    // Right arm
    ctx.beginPath();
    ctx.roundRect(10, 2 - armSwing, 4, 7, 2);
    ctx.fill();

    // 8. Helmet / Head
    const headGrad = ctx.createLinearGradient(-12, -18, 12, 0);
    headGrad.addColorStop(0, color);
    headGrad.addColorStop(1, accent);

    ctx.fillStyle = headGrad;
    ctx.strokeStyle = '#FFFFFF';
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.roundRect(-14, -18, 28, 20, 8);
    ctx.fill(); ctx.stroke();

    // Side ear vents / antenna based on character
    ctx.fillStyle = accent;
    ctx.fillRect(-16, -14, 2.5, 8);
    ctx.fillRect(13.5, -14, 2.5, 8);

    if (player.color === 'neon-blue') {
        // Antenna rods
        ctx.strokeStyle = color;
        ctx.lineWidth = 2;
        ctx.beginPath();
        ctx.moveTo(-10, -18); ctx.lineTo(-14, -25);
        ctx.moveTo(10, -18); ctx.lineTo(14, -25);
        ctx.stroke();
        ctx.fillStyle = visor;
        ctx.beginPath();
        ctx.arc(-14, -25, 2, 0, Math.PI * 2);
        ctx.arc(14, -25, 2, 0, Math.PI * 2);
        ctx.fill();
    } else if (player.color === 'neon-pink') {
        // Aggressive crest
        ctx.fillStyle = accent;
        ctx.beginPath();
        ctx.moveTo(-4, -18); ctx.lineTo(0, -24); ctx.lineTo(4, -18);
        ctx.closePath();
        ctx.fill();
    } else if (player.color === 'neon-green') {
        // Bio dome
        ctx.strokeStyle = visor;
        ctx.lineWidth = 2;
        ctx.beginPath();
        ctx.arc(0, -18, 4, Math.PI, 0);
        ctx.stroke();
    } else if (player.color === 'neon-yellow') {
        // Lightning bolt crest
        ctx.fillStyle = visor;
        ctx.beginPath();
        ctx.moveTo(0, -25); ctx.lineTo(3, -20); ctx.lineTo(0, -19); ctx.lineTo(3, -15);
        ctx.lineTo(-2, -15); ctx.lineTo(0, -21);
        ctx.closePath();
        ctx.fill();
    }

    // 9. High-Tech Glowing Visor with directional gaze
    let visorOffsetX = 0;
    let visorOffsetY = 0;
    if (direction === 'left') visorOffsetX = -3;
    if (direction === 'right') visorOffsetX = 3;
    if (direction === 'up') visorOffsetY = -3;
    if (direction === 'down') visorOffsetY = 2;

    // Visor border slot
    ctx.fillStyle = '#060A17';
    ctx.strokeStyle = color;
    ctx.lineWidth = 1.2;
    ctx.beginPath();
    ctx.roundRect(-10 + visorOffsetX, -12 + visorOffsetY, 20, 9, 4);
    ctx.fill(); ctx.stroke();

    // Visor glowing glass
    ctx.save();
    ctx.shadowColor = visor;
    ctx.shadowBlur = 8;
    const visorGrad = ctx.createLinearGradient(-8, -10, 8, -5);
    visorGrad.addColorStop(0, visor);
    visorGrad.addColorStop(1, '#0284C7');
    ctx.fillStyle = visorGrad;
    ctx.beginPath();
    ctx.roundRect(-8 + visorOffsetX, -10 + visorOffsetY, 16, 5.5, 2.5);
    ctx.fill();

    // Visor reflection streak
    ctx.strokeStyle = '#FFFFFF';
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.moveTo(-6 + visorOffsetX, -9 + visorOffsetY);
    ctx.lineTo(2 + visorOffsetX, -9 + visorOffsetY);
    ctx.stroke();
    ctx.restore();

    ctx.restore();

    // 10. Floating Overhead Nickname
    ctx.save();
    ctx.font = '700 11px "Rajdhani", sans-serif';
    ctx.textAlign = 'center';

    // Nickname background pill
    const nameText = player.nickname || 'Piloto';
    const textWidth = ctx.measureText(nameText).width;
    ctx.fillStyle = 'rgba(11, 16, 32, 0.85)';
    ctx.strokeStyle = isLocal ? color : '#334155';
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.roundRect(cx - textWidth / 2 - 5, cy - 35, textWidth + 10, 14, 4);
    ctx.fill(); ctx.stroke();

    ctx.fillStyle = '#F8FAFC';
    ctx.fillText(nameText, cx, cy - 24);
    ctx.restore();
}
