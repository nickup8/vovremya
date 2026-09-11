import { getInitData, isInsideMax } from './lib/maxBridge';
import { AppShell } from '../shared/AppShell';

function OutsideMax() {
    return (
        <div className="outside-max">
            Откройте это приложение внутри MAX
        </div>
    );
}

export function App() {
    if (!isInsideMax() || getInitData() === null) {
        return <OutsideMax />;
    }

    return <AppShell canCreateEarlierRequest />;
}
