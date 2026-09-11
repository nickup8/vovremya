import { createRoot } from 'react-dom/client';
import { PlatformProvider } from '../shared/platform/PlatformContext';
import { ApiProvider } from '../shared/api/ApiContext';
import { vkPlatform } from './lib/vkPlatform';
import { api } from './lib/api';
import { initVkApp } from './lib/vkBridge';
import { App } from './App';
import '../max-app/styles.css';

initVkApp();

createRoot(document.getElementById('vk-root')!).render(
    <ApiProvider api={api}>
        <PlatformProvider platform={vkPlatform}>
            <App />
        </PlatformProvider>
    </ApiProvider>,
);
