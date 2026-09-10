import { createRoot } from 'react-dom/client';
import { PlatformProvider } from '../shared/platform/PlatformContext';
import { maxPlatform } from './lib/maxPlatform';
import { App } from './App';
import './styles.css';

createRoot(document.getElementById('max-root')!).render(
    <PlatformProvider platform={maxPlatform}>
        <App />
    </PlatformProvider>,
);
