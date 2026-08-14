import { MaxUI } from '@maxhub/max-ui';
import '@maxhub/max-ui/dist/styles.css';
import { createRoot } from 'react-dom/client';
import { App } from './App';
import './styles.css';

createRoot(document.getElementById('max-root')!).render(
    <MaxUI>
        <App />
    </MaxUI>,
);
