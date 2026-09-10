import { createRoot } from 'react-dom/client';
import { PlatformProvider } from '../shared/platform/PlatformContext';
import { ApiProvider } from '../shared/api/ApiContext';
import { maxPlatform } from './lib/maxPlatform';
import { api } from './lib/api';
import { App } from './App';
import './styles.css';

createRoot(document.getElementById('max-root')!).render(
    <ApiProvider api={api}>
        <PlatformProvider platform={maxPlatform}>
            <App />
        </PlatformProvider>
    </ApiProvider>,
);
