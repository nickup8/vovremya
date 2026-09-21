import { Head } from '@inertiajs/react';
import {
    RocketLaunchIcon,
    CalendarDaysIcon,
    UsersIcon,
    ClockIcon,
    SparklesIcon,
    ChatBubbleLeftEllipsisIcon,
    ChartBarIcon,
    DocumentTextIcon,
} from '@heroicons/react/24/outline';
import AdminLayout from '@/layouts/AdminLayout';

/* ═══════════════ Data ═══════════════ */

interface HelpSection {
    icon: React.ComponentType<{ className?: string }>;
    title: string;
    description: string;
    items: string[];
}

const SECTIONS: HelpSection[] = [
    {
        icon: RocketLaunchIcon,
        title: 'Быстрый старт',
        description: 'Основные шаги для запуска онлайн-записи.',
        items: [
            'Добавьте услуги, которые смогут выбирать клиенты.',
            'Настройте рабочие дни, время работы и перерывы.',
            'Используйте ссылку онлайн-записи, чтобы клиент мог выбрать свободное время самостоятельно.',
            'Новые записи автоматически появляются в календаре.',
        ],
    },
    {
        icon: CalendarDaysIcon,
        title: 'Календарь и записи',
        description: 'Управление расписанием и визитами клиентов.',
        items: [
            'Создание записи вручную из календаря.',
            'Просмотр дня, недели и месяца.',
            'Перенос записи на другое время.',
            'Отмена записи.',
            'Изменение статуса после визита.',
            'Повторяющиеся записи для постоянных клиентов.',
        ],
    },
    {
        icon: UsersIcon,
        title: 'Клиенты и услуги',
        description: 'База клиентов и каталог услуг.',
        items: [
            'Создание карточки клиента с контактами.',
            'История визитов клиента.',
            'Заметки о клиенте.',
            'Блокировка клиента при необходимости.',
            'Создание и изменение услуг в каталоге.',
            'Услугу, которая уже использовалась в записях, нельзя удалить — это сохраняет историю визитов.',
        ],
    },
    {
        icon: ClockIcon,
        title: 'Расписание',
        description: 'Настройка доступного времени для записи.',
        items: [
            'Рабочие и выходные дни.',
            'Начало и окончание рабочего дня.',
            'Перерывы в течение дня.',
            'Интервал записи (например, каждые 30 минут).',
            'Блокировка времени: отпуск, больничный, личное время и другие причины.',
        ],
    },
    {
        icon: SparklesIcon,
        title: 'AutoFill и «Хочу раньше»',
        description: 'Автоматическое заполнение освободившихся окон.',
        items: [
            'Клиент может запросить более раннее время для записи.',
            'Освободившиеся окна участвуют в AutoFill.',
            'Подходящему клиенту может быть отправлено предложение.',
            'После принятия предложения запись переносится на новое время.',
        ],
    },
    {
        icon: ChatBubbleLeftEllipsisIcon,
        title: 'MAX и VK',
        description: 'Взаимодействие клиентов через мессенджеры.',
        items: [
            'Клиент может взаимодействовать с записью через подключённые MAX/VK сценарии.',
            'Подтверждение визита.',
            'Отмена доступной записи.',
            'Работа с предложениями AutoFill / «Хочу раньше».',
            'Уведомления, реализованные в этих каналах.',
        ],
    },
    {
        icon: ChartBarIcon,
        title: 'Аналитика',
        description: 'Статистика по записи и выручке.',
        items: [
            'Выручка и средний чек.',
            'Посещаемость и конверсия.',
            'Потенциальные потери.',
            'Каналы записи.',
        ],
    },
];

/* ═══════════════ Component ═══════════════ */

export default function HelpPage() {
    return (
        <>
            <Head title="Помощь — Вовремя" />

            <AdminLayout title="Помощь" hideNewAppointment>
                <div className="min-h-full bg-[var(--color-admin-page-bg)] p-3 md:p-7">
                    <div className="max-w-[1280px]">
                        {/* Header */}
                        <h1 className="text-xl font-bold text-[var(--color-ink)] md:text-2xl">
                            Помощь
                        </h1>
                        <p className="mt-1 text-sm text-[var(--color-graphite)]">
                            Короткие инструкции по основным возможностям ИРСИ.
                        </p>

                        {/* Getting Started Card */}
                        <div className="mt-5 rounded-xl border border-[var(--color-line)] bg-white p-4 md:p-5 dark:bg-[var(--color-surface)]">
                            <h2 className="text-base font-semibold text-[var(--color-ink)]">
                                С чего начать
                            </h2>
                            <p className="mt-1.5 text-sm leading-relaxed text-[var(--color-graphite)]">
                                Настройте услуги и рабочее время, затем поделитесь ссылкой на онлайн-запись с клиентами.
                                Все новые записи появятся в календаре.
                            </p>
                        </div>

                        {/* Sections Grid */}
                        <div className="mt-5 grid grid-cols-1 gap-4 md:grid-cols-2">
                            {SECTIONS.map((section) => (
                                <div
                                    key={section.title}
                                    className="rounded-xl border border-[var(--color-line)] bg-white p-4 md:p-5 dark:bg-[var(--color-surface)]"
                                >
                                    <div className="flex items-start gap-3">
                                        <section.icon className="mt-0.5 size-5 shrink-0 text-[var(--color-orange)]" />
                                        <div className="min-w-0 flex-1">
                                            <h3 className="text-sm font-semibold text-[var(--color-ink)]">
                                                {section.title}
                                            </h3>
                                            <p className="mt-0.5 text-xs text-[var(--color-graphite)]">
                                                {section.description}
                                            </p>
                                        </div>
                                    </div>
                                    <ul className="mt-3 space-y-1.5 pl-8">
                                        {section.items.map((item, i) => (
                                            <li
                                                key={i}
                                                className="relative text-sm leading-snug text-[var(--color-ink)] before:absolute before:-left-4 before:top-[7px] before:size-1 before:rounded-full before:bg-[var(--color-line)]"
                                            >
                                                {item}
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            ))}
                        </div>

                        {/* Useful Links */}
                        <div className="mt-5 rounded-xl border border-[var(--color-line)] bg-white p-4 md:p-5 dark:bg-[var(--color-surface)]">
                            <div className="flex items-start gap-3">
                                <DocumentTextIcon className="mt-0.5 size-5 shrink-0 text-[var(--color-orange)]" />
                                <div>
                                    <h2 className="text-sm font-semibold text-[var(--color-ink)]">
                                        Документы
                                    </h2>
                                    <div className="mt-2 flex flex-wrap gap-3">
                                        <a
                                            href="/privacy"
                                            className="inline-flex items-center gap-1.5 rounded-lg border border-[var(--color-line)] bg-[var(--color-surface)] px-3 py-1.5 text-xs font-medium text-[var(--color-ink)] transition-colors hover:bg-[var(--color-surface-hover)]"
                                        >
                                            Политика конфиденциальности
                                        </a>
                                        <a
                                            href="/offer"
                                            className="inline-flex items-center gap-1.5 rounded-lg border border-[var(--color-line)] bg-[var(--color-surface)] px-3 py-1.5 text-xs font-medium text-[var(--color-ink)] transition-colors hover:bg-[var(--color-surface-hover)]"
                                        >
                                            Условия использования
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </AdminLayout>
        </>
    );
}
