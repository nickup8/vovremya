import { configureEcho, echo } from '@laravel/echo-react';
import axios from 'axios';

configureEcho({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: Number(import.meta.env.VITE_REVERB_PORT) || 8080,
    wssPort: Number(import.meta.env.VITE_REVERB_PORT) || 8080,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
    authorizer: (channel, _options) => ({
        authorize: (socketId: string, callback: any) => {
            axios.post('/broadcasting/auth', {
                socket_id: socketId,
                channel_name: channel.name,
            })
                .then((response) => {
                    callback(false, response.data);
                })
                .catch((error) => {
                    callback(error);
                });
        },
    }),
});

export { echo };
