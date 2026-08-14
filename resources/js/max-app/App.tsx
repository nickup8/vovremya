import { getInitData, isInsideMax } from './lib/maxBridge';

/** Экран-заглушка: приложение открыто вне MAX */
function OutsideMax() {
    return (
        <div className="outside-max">
            Откройте это приложение внутри MAX
        </div>
    );
}

/** Каркас приложения (заголовок + плейсхолдер) */
function AppShell() {
    return (
        <div className="app-shell">
            <header className="app-header">Мои записи</header>
            <main className="app-body">Загрузка…</main>
        </div>
    );
}

export function App() {
    if (!isInsideMax() || getInitData() === null) {
        return <OutsideMax />;
    }

    return <AppShell />;
}
