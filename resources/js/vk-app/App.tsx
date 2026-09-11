import { useCallback, useState } from 'react';
import { AppShell } from '../shared/AppShell';
import { getVkLaunchParams, getVkLinkToken } from './lib/vkBridge';
import { LinkOnboarding } from './LinkOnboarding';

function OutsideVk() {
    return (
        <div className="outside-max">
            Откройте приложение внутри VK
        </div>
    );
}

export function App() {
    if (!getVkLaunchParams()) {
        return <OutsideVk />;
    }

    return <AppRouter />;
}

function AppRouter() {
    const [linked, setLinked] = useState(() => !getVkLinkToken());

    const handleLinked = useCallback(() => {
        const url = new URL(window.location.href);
        url.hash = '';
        window.history.replaceState(null, '', url.toString());
        setLinked(true);
    }, []);

    if (!linked) {
        return <LinkOnboarding onLinked={handleLinked} />;
    }

    return <AppShell canCreateEarlierRequest={false} />;
}
