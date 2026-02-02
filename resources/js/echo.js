import Echo from 'laravel-echo';

import Pusher from 'pusher-js';
window.Pusher = Pusher;

// Determine if we're in production based on the current host
const isProduction = window.location.hostname !== 'localhost' && window.location.hostname !== '127.0.0.1';

// Auto-detect the correct scheme based on current page protocol
const currentScheme = window.location.protocol === 'https:' ? 'https' : 'http';

// Use environment variables but fallback to sensible defaults
const reverbHost = import.meta.env.VITE_REVERB_HOST || (isProduction ? window.location.hostname : 'localhost');
const reverbScheme = import.meta.env.VITE_REVERB_SCHEME || currentScheme;
const reverbPort = import.meta.env.VITE_REVERB_PORT || (reverbScheme === 'https' ? 443 : 8080);

console.log('[Echo] Initializing with config:', {
    host: reverbHost,
    scheme: reverbScheme,
    port: reverbPort,
    forceTLS: reverbScheme === 'https',
    isProduction
});

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: reverbHost,
    wsPort: reverbScheme === 'http' ? reverbPort : 80,
    wssPort: reverbScheme === 'https' ? reverbPort : 443,
    forceTLS: reverbScheme === 'https',
    enabledTransports: ['ws', 'wss'],
    // Add auth endpoint for private channels
    authEndpoint: `${window.location.origin}/broadcasting/auth`,
    auth: {
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        }
    }
});

// Debug connection events in development
if (import.meta.env.DEV) {
    window.Echo.connector.pusher.connection.bind('connected', () => {
        console.log('[Echo] WebSocket connected successfully');
    });

    window.Echo.connector.pusher.connection.bind('error', (err) => {
        console.error('[Echo] WebSocket connection error:', err);
    });

    window.Echo.connector.pusher.connection.bind('disconnected', () => {
        console.warn('[Echo] WebSocket disconnected');
    });
}
