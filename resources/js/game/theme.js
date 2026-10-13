/**
 * Bomber Arena - Theme & Color Configuration
 * Aesthetic: Gaming Neon / Cyberpunk Arcade
 */
export const THEME = {
    colors: {
        bgMain: '#0B1020',
        panel: '#151D31',
        panelBorder: '#233052',
        cyan: '#38BDF8',
        violet: '#A78BFA',
        coral: '#FB7185',
        green: '#34D399',
        yellow: '#FBBF24',
        wallMetal: '#12192E',
        wallBorder: '#1E294B',
        blockBody: '#1B233A',
        blockAccent: '#F59E0B',
        textLight: '#F1F5F9',
        textMuted: '#94A3B8',
    },
    characters: {
        'neon-blue': {
            id: 'neon-blue',
            name: 'Cyber Blue',
            color: '#38BDF8',
            accent: '#0284C7',
            visor: '#7DD3FC',
            glow: 'rgba(56, 189, 248, 0.45)',
            role: 'Ágil y Tecnológico',
        },
        'neon-pink': {
            id: 'neon-pink',
            name: 'Neon Red',
            color: '#FB7185',
            accent: '#E11D48',
            visor: '#FDA4AF',
            glow: 'rgba(251, 113, 133, 0.45)',
            role: 'Agresivo y Competitivo',
        },
        'neon-green': {
            id: 'neon-green',
            name: 'Toxic Green',
            color: '#34D399',
            accent: '#059669',
            visor: '#6EE7B7',
            glow: 'rgba(52, 211, 153, 0.45)',
            role: 'Bio-Energético',
        },
        'neon-yellow': {
            id: 'neon-yellow',
            name: 'Plasma Yellow',
            color: '#FBBF24',
            accent: '#D97706',
            visor: '#FDE68A',
            glow: 'rgba(251, 191, 36, 0.45)',
            role: 'Dinámico y Eléctrico',
        },
    },
    powerups: {
        speed: {
            name: 'Velocidad Extra',
            color: '#38BDF8',
            glow: 'rgba(56, 189, 248, 0.6)',
            symbol: '⚡',
        },
        bomb: {
            name: 'Bomba Adicional',
            color: '#A78BFA',
            glow: 'rgba(167, 139, 250, 0.6)',
            symbol: '💣',
        },
        fire: {
            name: 'Mayor Alcance',
            color: '#FBBF24',
            glow: 'rgba(251, 191, 36, 0.6)',
            symbol: '🔥',
        },
    },
};
