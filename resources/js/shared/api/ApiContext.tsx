import { createContext, useContext, type ReactNode } from 'react';
import type { MiniappApi } from './client';

const ApiContext = createContext<MiniappApi | null>(null);

export function ApiProvider({ api, children }: { api: MiniappApi; children: ReactNode }) {
    return <ApiContext.Provider value={api}>{children}</ApiContext.Provider>;
}

export function useApi(): MiniappApi {
    const api = useContext(ApiContext);
    if (!api) throw new Error('useApi must be used within ApiProvider');
    return api;
}
