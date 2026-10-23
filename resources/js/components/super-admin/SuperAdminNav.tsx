import { usePage } from '@inertiajs/react';

interface PlatformAdmin {
    isRoot: boolean;
    permissions: string[];
}

interface NavProps {
    current: string;
}

interface NavItem {
    key: string;
    label: string;
    href: string;
    permission?: string;
    rootOnly?: boolean;
}

const NAV_ITEMS: NavItem[] = [
    { key: 'dashboard', label: 'Обзор', href: '/admin-root', permission: 'dashboard.view' },
    { key: 'users', label: 'Пользователи', href: '/admin-root/users', permission: 'users.view' },
    { key: 'notifications', label: 'Уведомления', href: '/admin-root/notifications', permission: 'notifications.view' },
    { key: 'plans', label: 'Тарифы', href: '/admin-root/plans', permission: 'plans.view' },
    { key: 'receipts', label: 'Чеки', href: '/admin-root/receipts', permission: 'receipts.view' },
    { key: 'backups', label: 'Резервные копии', href: '/admin-root/backups', rootOnly: true },
    { key: 'audit', label: 'Журнал', href: '/admin-root/audit', permission: 'audit.view' },
    { key: 'admins', label: 'Администраторы', href: '/admin-root/admins', permission: 'platform_admins.manage' },
];

export default function SuperAdminNav({ current }: NavProps) {
    const { platformAdmin } = usePage().props as { platformAdmin: PlatformAdmin };
    const perms = platformAdmin.permissions;

    const visibleItems = NAV_ITEMS.filter((item) => {
        if (item.rootOnly) {
            return platformAdmin.isRoot;
        }

        return item.permission ? perms.includes(item.permission) : false;
    });

    return (
        <div className="mt-4 flex flex-wrap gap-2 text-sm">
            {visibleItems.map((item, i) => (
                <span key={item.key} className="flex items-center gap-2">
                    {i > 0 && <span className="text-[#8E8A85]">·</span>}
                    {item.key === current ? (
                        <span className="font-semibold text-[#181818]">{item.label}</span>
                    ) : (
                        <a href={item.href} className="text-[#8E8A85] hover:text-[#181818]">{item.label}</a>
                    )}
                </span>
            ))}
        </div>
    );
}
