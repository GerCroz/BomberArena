import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

const isHttps = window.location.protocol === 'https:';
const isRemoteHost = window.location.hostname !== 'localhost' && window.location.hostname !== '127.0.0.1';

const host = isRemoteHost ? window.location.hostname : (import.meta.env.VITE_REVERB_HOST || '127.0.0.1');
const port = isRemoteHost ? (isHttps ? 443 : 80) : (import.meta.env.VITE_REVERB_PORT || 8080);
const forceTLS = isHttps || (import.meta.env.VITE_REVERB_SCHEME === 'https');

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: host,
    wsPort: port,
    wssPort: isRemoteHost ? 443 : (import.meta.env.VITE_REVERB_PORT || 8080),
    forceTLS: forceTLS,
    enabledTransports: ['ws', 'wss'],
});
