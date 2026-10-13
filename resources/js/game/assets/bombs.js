/**
 * Bomber Arena - Original Futuristic Bomb Renderer
 * Features:
 * - Spherical dark metallic chassis with tech seam lines
 * - Pulsing energetic plasma core
 * - Live radial countdown indicator arc (remaining fuse time)
 * - Dynamic states: Fresh -> Active (pulsing) -> Critical Warning (fast strobe) -> Expansion Flash
 */
import { THEME } from '../theme.js';

export function drawBomb(ctx, bx, by, size, bomb, animTime = 0) {
    const elapsed = bomb.local_planted_at ? (performance.now() - bomb.local_planted_at) / 1000 : 0;
    const totalFuse = 2.0; // 2 seconds fuse
    const remaining = Math.max(0, totalFuse - elapsed);
    const progress = Math.min(1, elapsed / totalFuse); // 0.0 (new) to 1.0 (detonating)

    const isCritical = remaining <= 0.55;
    const isDetonating = remaining <= 0.08;

    // Pulse frequency increases as fuse burns down
    const pulseFreq = isCritical ? 24 : (8 + progress * 8);
    const pulse = Math.sin(animTime * pulseFreq);
    const pulseFactor = 0.5 + 0.5 * pulse;

    // Visual jitter if critical
    let jitterX = 0;
    let jitterY = 0;
    if (isCritical) {
        jitterX = (Math.random() - 0.5) * 1.5;
        jitterY = (Math.random() - 0.5) * 1.5;
    }

    const x = bx + jitterX;
    const y = by + jitterY;
    const radius = isDetonating ? 18.5 : (15 + pulseFactor * 1.5);

    ctx.save();

    // 1. Drop shadow with soft blur
    ctx.fillStyle = 'rgba(5, 8, 17, 0.75)';
    ctx.beginPath();
    ctx.ellipse(x, by + 16, 15, 5, 0, 0, Math.PI * 2);
    ctx.fill();

    // 2. Outer Warning Aura / Ground Radiation Ring
    const auraColor = isCritical ? 'rgba(251, 113, 133, 0.4)' : 'rgba(56, 189, 248, 0.25)';
    ctx.strokeStyle = auraColor;
    ctx.lineWidth = 1.5;
    ctx.beginPath();
    ctx.ellipse(x, by + 16, 17 + pulseFactor * 3, 5.5 + pulseFactor * 1.2, 0, 0, Math.PI * 2);
    ctx.stroke();

    // 3. Spherical Metallic Chassis Gradient
    const chassisGrad = ctx.createRadialGradient(x - 4, y - 5, 2, x, y, radius);
    if (isCritical) {
        chassisGrad.addColorStop(0, '#3F1522');
        chassisGrad.addColorStop(0.7, '#1A0B12');
        chassisGrad.addColorStop(1, '#080305');
    } else {
        chassisGrad.addColorStop(0, '#2A3656');
        chassisGrad.addColorStop(0.7, '#13192B');
        chassisGrad.addColorStop(1, '#090D18');
    }

    ctx.fillStyle = chassisGrad;
    ctx.strokeStyle = isCritical ? THEME.colors.coral : THEME.colors.cyan;
    ctx.lineWidth = 1.5;
    ctx.shadowColor = isCritical ? THEME.colors.coral : THEME.colors.cyan;
    ctx.shadowBlur = isCritical ? 16 : 8;

    ctx.beginPath();
    ctx.arc(x, y, radius, 0, Math.PI * 2);
    ctx.fill();
    ctx.stroke();
    ctx.shadowBlur = 0; // reset blur

    // 4. Tech Seam Latitudes & Longitudes
    ctx.strokeStyle = isCritical ? 'rgba(251, 113, 133, 0.4)' : 'rgba(56, 189, 248, 0.35)';
    ctx.lineWidth = 1;
    // Horizontal seam
    ctx.beginPath();
    ctx.ellipse(x, y, radius - 1, (radius - 1) * 0.4, 0, 0, Math.PI * 2);
    ctx.stroke();

    // 5. Energetic Core (Reactor Eye)
    const coreColor = isCritical ? THEME.colors.coral : (progress > 0.4 ? THEME.colors.yellow : THEME.colors.cyan);
    ctx.save();
    ctx.shadowColor = coreColor;
    ctx.shadowBlur = 12 + pulseFactor * 6;
    ctx.fillStyle = coreColor;
    ctx.beginPath();
    ctx.arc(x, y, 4.5 + pulseFactor * 1.8, 0, Math.PI * 2);
    ctx.fill();

    // White hot core pupil
    ctx.fillStyle = '#FFFFFF';
    ctx.beginPath();
    ctx.arc(x, y, 2, 0, Math.PI * 2);
    ctx.fill();
    ctx.restore();

    // 6. Live Countdown Radial Ring (Arc around top edge)
    const startAngle = -Math.PI / 2;
    const endAngle = startAngle + (Math.PI * 2 * (1 - progress));
    ctx.strokeStyle = isCritical ? '#FB7185' : '#FBBF24';
    ctx.lineWidth = 2.5;
    ctx.lineCap = 'round';
    ctx.beginPath();
    ctx.arc(x, y, radius + 3, startAngle, endAngle);
    ctx.stroke();

    // 7. Tech Top Valve / Fuse Spark
    ctx.fillStyle = '#0F172A';
    ctx.fillRect(x - 3, y - radius - 3, 6, 4);

    // Spark emitter
    const sparkColor = isCritical ? '#FDA4AF' : '#FDE68A';
    ctx.fillStyle = sparkColor;
    ctx.shadowColor = sparkColor;
    ctx.shadowBlur = 8;
    ctx.beginPath();
    ctx.arc(x, y - radius - 4, 2 + Math.random() * 1.5, 0, Math.PI * 2);
    ctx.fill();

    ctx.restore();
}
