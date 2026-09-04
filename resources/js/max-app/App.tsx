import { useEffect, useState } from 'react';
import { backButton, getInitData, isInsideMax } from './lib/maxBridge';
import { AppointmentsScreen } from './screens/AppointmentsScreen';
import { HistoryScreen } from './screens/HistoryScreen';
import { ProfileScreen } from './screens/ProfileScreen';

type Screen = 'appointments' | 'history' | 'profile';

function OutsideMax() {
    return (
        <div className="outside-max">
            Откройте это приложение внутри MAX
        </div>
    );
}

const TABS: { key: Screen; label: string; icon: React.ReactNode }[] = [
    {
        key: 'appointments',
        label: 'Записи',
        icon: (
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                <path d="M8 2v4M16 2v4M3 10h18" />
                <rect x="3" y="4" width="18" height="18" rx="2" />
            </svg>
        ),
    },
    {
        key: 'history',
        label: 'История',
        icon: (
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                <path d="M12 8v5l3 2" />
                <circle cx="12" cy="12" r="9" />
            </svg>
        ),
    },
    {
        key: 'profile',
        label: 'Профиль',
        icon: (
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                <path d="M20 21a8 8 0 0 0-16 0" />
                <circle cx="12" cy="8" r="4" />
            </svg>
        ),
    },
];

const TITLES: Record<Screen, string> = {
    appointments: 'Мои записи',
    history: 'История',
    profile: 'Профиль',
};

function AppShell() {
    const [screen, setScreen] = useState<Screen>('appointments');

    useEffect(() => {
        backButton.hide();
    }, [screen]);

    return (
        <div className="app-shell">
            <header className="app-header">
                <h1>{TITLES[screen]}</h1>
            </header>

            <main className="app-body">
                {screen === 'appointments' && <AppointmentsScreen />}
                {screen === 'history' && <HistoryScreen />}
                {screen === 'profile' && <ProfileScreen />}
            </main>

            <nav className="tab-bar">
                {TABS.map((tab) => (
                    <button
                        key={tab.key}
                        type="button"
                        onClick={() => setScreen(tab.key)}
                        className={`tab-item${screen === tab.key ? ' tab-item--active' : ''}`}
                    >
                        {tab.icon}
                        <span>{tab.label}</span>
                    </button>
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
