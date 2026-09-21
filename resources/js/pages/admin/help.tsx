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
    fullWidth?: boolean;
}

const SECTIONS: HelpSection[] = [
    {
        icon: RocketLaunchIcon,
        title: 'Быстрый старт',
        description: 'Основные шаги для запуска онлайн-записи.',
        items: [
            'Добавьте услуги, которые смогут выбирать клиенты.',
            'Настройте рабочие дни, время работы и перерывы.',
            'Поделитесь ссылкой онлайн-записи с клиентами.',
            'Новые записи автоматически появятся в календаре.',
        ],
    },
    {
        icon: CalendarDaysIcon,
        title: 'Календарь и записи',
        description: 'Управление расписанием и визитами клиентов.',
        items: [
            'Создание записи вручную из календаря.',
            'Просмотр дня, недели и месяца.',
            'Перенос и отмена записи.',
            'Изменение статуса после визита.',
            'Повторяющиеся записи.',
        ],
    },
    {
        icon: UsersIcon,
        title: 'Клиенты и услуги',
        description: 'База клиентов и каталог услуг.',
        items: [
            'Создание карточки клиента с контактами.',
            'История визитов и заметки.',
            'Блокировка клиента при необходимости.',
            'Создание и изменение услуг.',
            'Услугу с историей записей нельзя удалить.',
        ],
    },
    {
        icon: ClockIcon,
        title: 'Расписание',
        description: 'Настройка доступного времени для записи.',
        items: [
            'Рабочие и выходные дни.',
            'Начало, окончание рабочего дня и перерывы.',
            'Интервал записи.',
            'Блокировка времени: отпуск, больничный и др.',
        ],
    },
    {
        icon: SparklesIcon,
        title: 'AutoFill и «Хочу раньше»',
        description: 'Автоматическое заполнение освободившихся окон.',
        items: [
            'Клиент может запросить более раннее время.',
            'Освободившиеся окна участвуют в AutoFill.',
            'Подходящему клиенту отправляется предложение.',
            'После принятия запись переносится автоматически.',
        ],
    },
    {
        icon: ChatBubbleLeftEllipsisIcon,
        title: 'MAX и VK',
        description: 'Взаимодействие клиентов через мессенджеры.',
        items: [
            'Подтверждение и отмена визита через чат.',
            'Работа с предложениями AutoFill.',
            'Уведомления в этих каналах.',
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
        fullWidth: true,
    },
];

/* ═══════════════ Component ═══════════════ */

export default function HelpPage() {
    return (
        <>
            <Head title="Помощь — Вовремя" />

            <AdminLayout title="Помощь" hideNewAppointment fullBleed>
                <div className="min-h-full bg-[var(--color-admin-page-bg)] p-3 md:p-7">
                    <div className="max-w-[1280px]">
                        <p className="mb-4 text-sm text-[var(--color-graphite)]">
                            Короткие инструкции по основным возможностям ИРСИ.
                        </p>

                        {/* Sections Grid */}
                        <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                            {SECTIONS.map((section) => (
                                <div
                                    key={section.title}
                                    className={`rounded-[16px] border border-[var(--color-line)] bg-[var(--color-surface-elevated)] p-[14px] md:p-5 ${
                                        section.fullWidth ? 'md:col-span-2' : ''
                                    }`}
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
                                    <ul className="mt-2.5 space-y-1 pl-8">
                                        {section.items.map((item, i) => (
                                            <li
                                                key={i}
                                                className="relative text-[13px] leading-snug text-[var(--color-ink)] before:absolute before:-left-4 before:top-[7px] before:size-1 before:rounded-full before:bg-[var(--color-line)]"
                                            >
                                                {item}
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            ))}
                        </div>

                        {/* Documents */}
                        <div className="mt-3 rounded-[16px] border border-[var(--color-line)] bg-[var(--color-surface-elevated)] p-[14px] md:p-5">
                            <div className="flex items-start gap-3">
                                <DocumentTextIcon className="mt-0.5 size-5 shrink-0 text-[var(--color-orange)]" />
                                <div>
                                    <h3 className="text-sm font-semibold text-[var(--color-ink)]">
                                        Документы
                                    </h3>
                                    <div className="mt-2 flex flex-wrap gap-2">
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
