import { AppShell } from '../shared/AppShell';
import { getVkLaunchParams } from './lib/vkBridge';

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

    return <AppShell canCreateEarlierRequest={false} />;
}
