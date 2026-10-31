import { useCallback, useEffect, useRef, useState } from 'react';
import { AppShell } from '../shared/AppShell';
import { getVkLaunchParams, getVkLinkToken, subscribeToVkLinkNavigation } from './lib/vkBridge';
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

function clearLinkHashIfMatches(token: string): void {
    const current = window.location.hash.slice(1);

    if (current !== token) {
        return;
    }

    const url = new URL(window.location.href);
    url.hash = '';
    window.history.replaceState(null, '', url.toString());
}

function AppRouter() {
    // Явное состояние активного сценария: токен из hash или null (кабинет).
    // linkTokenRef всегда обновляется синхронно с setLinkToken — без окна
    // «событие принято, effect ещё не прогнал».
    const [linkToken, setLinkTokenState] = useState<string | null>(() => getVkLinkToken());
    const linkTokenRef = useRef(linkToken);

    // Завершённые в этой сессии токены — повтор события не возобновляет сценарий.
    const consumedTokensRef = useRef<Set<string>>(new Set());

    const setLinkToken = useCallback((next: string | null) => {
        linkTokenRef.current = next;
        setLinkTokenState(next);
    }, []);

    useEffect(() => {
        return subscribeToVkLinkNavigation((incoming) => {
            if (incoming && consumedTokensRef.current.has(incoming)) {
                clearLinkHashIfMatches(incoming);

                return;
            }

            if (linkTokenRef.current === incoming) {
                return;
            }

            // Bridge может сообщить новый токен, не обновив window.location.hash —
            // синхронизируем, чтобы завершение сценария чистило актуальный hash.
            if (incoming && getVkLinkToken() !== incoming) {
                const url = new URL(window.location.href);
                url.hash = incoming;
                window.history.replaceState(null, '', url.toString());
            }

            setLinkToken(incoming);
        });
    }, [setLinkToken]);

    const handleLinked = useCallback((finishedToken: string) => {
        // Запоздалое завершение сценария А не должно трогать экран Б.
        if (linkTokenRef.current !== finishedToken) {
            return;
        }

        consumedTokensRef.current.add(finishedToken);
        clearLinkHashIfMatches(finishedToken);
        setLinkToken(null);
    }, [setLinkToken]);

    if (linkToken !== null) {
        return (
            <LinkOnboarding
                key={linkToken}
                token={linkToken}
                onLinked={handleLinked}
            />
        );
    }

    return <AppShell canCreateEarlierRequest={true} />;
}
