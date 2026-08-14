import { ToolButton, Typography } from '@maxhub/max-ui';
import { useEffect, useState } from 'react';
import { backButton, getInitData, isInsideMax } from './lib/maxBridge';
import { AppointmentsScreen } from './screens/AppointmentsScreen';
import { HistoryScreen } from './screens/HistoryScreen';
import { ProfileScreen } from './screens/ProfileScreen';

type Screen = 'appointments' | 'history' | 'profile';

/** Экран-заглушка: приложение открыто вне MAX */
function OutsideMax() {
    return (
        <div className="outside-max">
            Откройте это приложение внутри MAX
        </div>
    );
}

const TABS: { key: Screen; label: string }[] = [
    { key: 'appointments', label: 'Записи' },
    { key: 'history', label: 'История' },
    { key: 'profile', label: 'Профиль' },
];

const TITLES: Record<Screen, string> = {
    appointments: 'Мои записи',
    history: 'История',
    profile: 'Профиль',
};

/** Каркас приложения с таб-навигацией */
function AppShell() {
    const [screen, setScreen] = useState<Screen>('appointments');

    // BackButton: скрыт на основных табах (нет куда "назад")
    useEffect(() => {
        backButton.hide();
    }, [screen]);

    return (
        <div className="app-shell">
            <header className="app-header">
                <Typography.Title>{TITLES[screen]}</Typography.Title>
            </header>

            <main className="app-body">
                {screen === 'appointments' && <AppointmentsScreen />}
                {screen === 'history' && <HistoryScreen />}
                {screen === 'profile' && <ProfileScreen />}
            </main>

            <nav className="tab-bar">
                {TABS.map((tab) => (
                    <ToolButton
                        key={tab.key}
                        onClick={() => setScreen(tab.key)}
                        className={`tab-item${screen === tab.key ? ' tab-item--active' : ''}`}
                    >
                        {tab.label}
                    </ToolButton>
                ))}
            </nav>
        </div>
    );
}

export function App() {
    if (!isInsideMax() || getInitData() === null) {
        return <OutsideMax />;
    }

    return <AppShell />;
}
