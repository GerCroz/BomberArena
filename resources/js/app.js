import './echo';
import './lobbyRooms';
import './roomSocial';
import {
    createIcons,
    Swords,
    Play,
    Plus,
    Users,
    Copy,
    Check,
    Volume2,
    VolumeX,
    Smartphone,
    Wifi,
    WifiOff,
    Clock,
    Trophy,
    RotateCcw,
    LogOut,
    Zap,
    Bomb,
    Flame,
    Shield,
    Monitor,
    Gamepad2,
    Sparkles,
    Radio,
    CircleDot,
    Activity,
    Info,
    ChevronRight,
    ArrowUp,
    ArrowDown,
    ArrowLeft,
    ArrowRight
} from 'lucide';

import { THEME } from './game/theme.js';
import { getCharacterSVG } from './game/assets/characters.js';
import { GameRenderer } from './game/rendering/gameRenderer.js';
import { SoundEngine } from './game/audio/soundEngine.js';
import { ClassicController } from './game/classicMovement.js';

// Expose Lucide icons globally
window.lucide = {
    createIcons: () => {
        createIcons({
            icons: {
                Swords,
                Play,
                Plus,
                Users,
                Copy,
                Check,
                Volume2,
                VolumeX,
                Smartphone,
                Wifi,
                WifiOff,
                Clock,
                Trophy,
                RotateCcw,
                LogOut,
                Zap,
                Bomb,
                Flame,
                Shield,
                Monitor,
                Gamepad2,
                Sparkles,
                Radio,
                CircleDot,
                Activity,
                Info,
                ChevronRight,
                ArrowUp,
                ArrowDown,
                ArrowLeft,
                ArrowRight
            }
        });
    }
};

// Expose Bomber Game Engine & Assets globally for Blade view consumption
window.BomberEngine = {
    THEME,
    GameRenderer,
    SoundEngine,
    getCharacterSVG,
    ClassicController,
};

// Auto-initialize Lucide icons when DOM is ready
if (typeof document !== 'undefined') {
    document.addEventListener('DOMContentLoaded', () => {
        if (window.lucide && window.lucide.createIcons) {
            window.lucide.createIcons();
        }
    });
}
