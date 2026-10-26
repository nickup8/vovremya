export const DEFAULT_APP_NAME = 'ИРСИ';

/**
 * Глобальный форматтер заголовков вкладок: страница задаёт название без бренда,
 * бренд добавляется здесь ровно один раз.
 */
export function formatPageTitle(title: string, appName: string): string {
    return title ? `${title} — ${appName}` : appName;
}
