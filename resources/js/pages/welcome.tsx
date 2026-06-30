import { useState, useEffect } from 'react';
import { Head, router } from '@inertiajs/react';
import {
    Sun, Moon, ShieldCheck, MessageCircleHeart, Gift,
    ArrowRight, Check, Sparkles, Clock, CalendarCheck,
    Calendar, Fingerprint, BadgeCheck, ChevronRight,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useAppearance } from '@/hooks/use-appearance';
import PublicLayout from '@/layouts/PublicLayout';

Welcome.layout = (page: React.ReactNode) => <PublicLayout children={page} />;

/* ═══════════════════════ Shared helpers ═══════════════════════ */

function Glow({ className = '' }: { className?: string }) {
    return <div aria-hidden className={`pointer-events-none absolute rounded-full blur-3xl opacity-40 ${className}`} />;
}

function ThemeToggle({ className = '' }: { className?: string }) {
    const { resolvedAppearance, updateAppearance } = useAppearance();
    const isDark = resolvedAppearance === 'dark';
    return (
        <button
            onClick={() => updateAppearance(isDark ? 'light' : 'dark')}
            className={`inline-flex size-10 items-center justify-center rounded-full transition-colors hover:bg-stone-200/60 dark:hover:bg-stone-700/60 ${className}`}
            aria-label="Переключить тему"
        >
            {isDark ? <Sun className="size-[18px] text-stone-300" /> : <Moon className="size-[18px] text-stone-500" />}
        </button>
    );
}

const cardBase = 'rounded-3xl border border-stone-200/60 bg-white/70 p-8 shadow-sm shadow-stone-200/30 backdrop-blur-sm transition-shadow hover:shadow-md dark:border-stone-700/40 dark:bg-stone-900/50 dark:shadow-none dark:hover:shadow-stone-900/50';
const iconBox = 'flex size-12 items-center justify-center rounded-2xl bg-stone-100 transition-colors group-hover:bg-stone-900 group-hover:text-white dark:bg-stone-800 dark:group-hover:bg-stone-100 dark:group-hover:text-stone-900';

/* ═══════════════════════ B1 — Hero ═══════════════════════ */

function PhoneMockup() {
    return (
        <div className="relative mx-auto w-[260px] sm:w-[280px]">
            {/* Phone frame */}
            <div className="relative rounded-[2.5rem] border-[5px] border-stone-900 bg-stone-900 p-1 shadow-2xl dark:border-stone-200 dark:bg-stone-200">
                <div className="overflow-hidden rounded-[2rem] bg-white dark:bg-stone-950">
                    {/* Status bar */}
                    <div className="flex items-center justify-between px-5 pt-3 pb-2">
                        <span className="text-[10px] font-semibold text-stone-900 dark:text-stone-100">9:41</span>
                        <div className="flex gap-1">
                            <div className="size-1 rounded-full bg-stone-900 dark:bg-stone-100" />
                            <div className="size-1 rounded-full bg-stone-900 dark:bg-stone-100" />
                            <div className="size-1 rounded-full bg-stone-900 dark:bg-stone-100" />
                        </div>
                    </div>

                    {/* Widget content */}
                    <div className="px-4 pb-4">
                        <p className="text-[10px] font-medium uppercase tracking-wider text-stone-400 dark:text-stone-500">Маникюр + покрытие</p>
                        <p className="mt-1 text-lg font-bold text-stone-900 dark:text-stone-50">1 800 ₽</p>
                        <div className="mt-3 grid grid-cols-3 gap-1.5">
                            {['10:00', '11:30', '14:00', '15:00', '16:30', '18:00'].map((t, i) => (
                                <div
                                    key={t}
                                    className={`rounded-xl py-1.5 text-center text-[11px] font-medium transition-colors ${
                                        i === 3
                                            ? 'bg-stone-900 text-white dark:bg-stone-100 dark:text-stone-900'
                                            : 'bg-stone-100 text-stone-600 dark:bg-stone-800 dark:text-stone-400'
                                    }`}
                                >
                                    {t}
                                </div>
                            ))}
                        </div>
                        <div className="mt-3 rounded-xl bg-stone-50 p-2.5 dark:bg-stone-800/50">
                            <div className="flex items-center gap-2">
                                <div className="size-6 rounded-full bg-stone-200 dark:bg-stone-700" />
                                <div>
                                    <p className="text-[10px] font-medium text-stone-900 dark:text-stone-100">15 апреля, Пт</p>
                                    <p className="text-[9px] text-stone-400 dark:text-stone-500">Анна · 2 ч</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {/* Push notification overlay */}
            <div className="absolute -right-4 top-8 w-[210px] rounded-2xl border border-stone-200/80 bg-white/95 p-3 shadow-xl shadow-stone-200/50 backdrop-blur-xl sm:-right-8 dark:border-stone-700/50 dark:bg-stone-900/95 dark:shadow-black/30">
                <div className="flex items-start gap-2.5">
                    <div className="flex size-8 shrink-0 items-center justify-center rounded-xl bg-stone-900 text-white dark:bg-stone-100 dark:text-stone-900">
                        <BadgeCheck className="size-4" />
                    </div>
                    <div className="min-w-0">
                        <p className="text-[10px] font-semibold text-stone-900 dark:text-stone-100">🎉 Новая запись!</p>
                        <p className="mt-0.5 text-[9px] leading-tight text-stone-500 dark:text-stone-400">
                            Анна, Пт 15:00
                        </p>
                        <p className="mt-0.5 text-[9px] font-medium text-green-600 dark:text-green-400">
                            Подтверждено ✓
                        </p>
                    </div>
                </div>
            </div>
        </div>
    );
}

function HeroSection() {
    return (
        <section className="relative overflow-hidden px-6 pt-20 pb-16 sm:pt-28 sm:pb-24">
            <Glow className="-top-32 -right-32 h-96 w-96 bg-rose-200/50 dark:bg-rose-900/15" />
            <Glow className="-bottom-40 -left-40 h-[500px] w-[500px] bg-amber-100/60 dark:bg-amber-900/10" />

            <div className="relative mx-auto flex max-w-6xl flex-col items-center gap-12 lg:flex-row lg:items-center lg:gap-16">
                {/* Left — text */}
                <div className="flex-1 text-center lg:text-left">
                    <span className="text-xl font-bold tracking-tight text-stone-900 dark:text-stone-50">вовремя</span>
                    <h1 className="mt-6 text-4xl font-bold tracking-tight text-stone-900 dark:text-stone-50 sm:text-5xl">
                        Забудьте про переписки в мессенджерах.{' '}
                        <span className="bg-gradient-to-r from-stone-500 to-stone-700 bg-clip-text text-transparent dark:from-stone-300 dark:to-stone-100">
                            Всё записывается само.
                        </span>
                    </h1>
                    <p className="mx-auto mt-6 max-w-lg text-lg leading-relaxed text-stone-500 dark:text-stone-400 lg:mx-0">
                        Цифровой блокнот для мастера. Клиент записывается через Telegram
                        или Max — вы получаете подтверждение и напоминание. Без касс, комиссий и SMS.
                        До 30 записей в месяц — <strong className="text-stone-700 dark:text-stone-200">бесплатно навсегда</strong>.
                    </p>

                    <div className="mt-8 flex flex-col items-center gap-3 sm:flex-row lg:justify-start">
                        <Button
                            size="lg"
                            className="group h-13 rounded-full bg-stone-900 px-8 text-base text-white shadow-lg shadow-stone-900/20 transition-all hover:scale-[1.03] hover:shadow-xl dark:bg-stone-100 dark:text-stone-900 dark:shadow-stone-100/10"
                            onClick={() => router.get('/auth/login')}
                        >
                            Начать бесплатно за 1 минуту
                            <ArrowRight className="size-4 transition-transform group-hover:translate-x-0.5" />
                        </Button>
                        <p className="text-xs text-stone-400 dark:text-stone-500">
                            Регистрация в один клик — без пароля и SMS
                        </p>
                    </div>
                </div>

                {/* Right — phone mockup */}
                <div className="flex-1 flex justify-center lg:justify-end">
                    <PhoneMockup />
                </div>
            </div>
        </section>
    );
}

/* ═══════════════════════ B2 — Pain Points ═══════════════════════ */

function PainPoints() {
    const cards = [
        {
            icon: ShieldCheck,
            title: 'Тишина для налоговой',
            text: 'Полное отсутствие сбора ИНН, ОФД и фискализации. Прямые P2P переводы клиентов мастерам.',
        },
        {
            icon: MessageCircleHeart,
            title: 'Защита от «забывчивых» клиентов',
            text: 'Кастомные сообщения с дедлайнами и условиями бронирования. Клиент точно знает, чего ожидать.',
        },
        {
            icon: Gift,
            title: '0 рублей за SMS-напоминания',
            text: 'Бесплатные напоминания и каскадные нотификации через ботов Telegram и Max.',
        },
    ];

    return (
        <section className="border-y border-stone-200/50 bg-stone-100/40 px-6 py-24 dark:border-stone-800/50 dark:bg-stone-900/30">
            <div className="mx-auto max-w-5xl">
                <p className="text-center text-sm font-medium uppercase tracking-[0.2em] text-stone-400 dark:text-stone-500">
                    Больше никакой рутины
                </p>
                <h2 className="mt-4 text-center text-3xl font-bold tracking-tight text-stone-900 dark:text-stone-50 sm:text-4xl">
                    Что перестанет вас раздражать
                </h2>
                <div className="mt-14 grid gap-5 sm:grid-cols-3">
                    {cards.map(({ icon: Icon, title, text }) => (
                        <div key={title} className={`group ${cardBase}`}>
                            <div className={iconBox}>
                                <Icon className="size-5" />
                            </div>
                            <h3 className="mt-6 text-lg font-semibold text-stone-900 dark:text-stone-50">{title}</h3>
                            <p className="mt-3 text-sm leading-relaxed text-stone-500 dark:text-stone-400">{text}</p>
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}

/* ═══════════════════════ B3 — Calculator ═══════════════════════ */

function Calculator() {
    const [count, setCount] = useState(40);
    const hoursSaved = Math.round((count * 10) / 60);
    const moneySaved = Math.round(count * 0.10 * 2500);

    return (
        <section className="relative overflow-hidden px-6 py-24 sm:py-32">
            <Glow className="-top-40 left-1/2 h-80 w-80 -translate-x-1/2 bg-stone-300/40 dark:bg-amber-900/20" />
            <div className="relative mx-auto max-w-2xl text-center">
                <p className="text-sm font-medium uppercase tracking-[0.2em] text-stone-400 dark:text-stone-500">
                    Калькулятор
                </p>
                <h2 className="mt-4 text-3xl font-bold tracking-tight text-stone-900 dark:text-stone-50 sm:text-4xl">
                    Сколько вы экономите с Вовремя
                </h2>
                <p className="mx-auto mt-4 max-w-md text-stone-500 dark:text-stone-400">
                    Переместите ползунок, чтобы увидеть реальную выгоду для вашего графика
                </p>

                <div className="mt-12 rounded-3xl border border-stone-200/60 bg-white/70 p-8 shadow-sm shadow-stone-200/40 backdrop-blur-sm dark:border-stone-700/50 dark:bg-stone-900/60 dark:shadow-none sm:p-12">
                    <label className="text-xs font-medium uppercase tracking-wider text-stone-400 dark:text-stone-500">
                        Записей в месяц
                    </label>
                    <input
                        type="range"
                        min={10}
                        max={300}
                        step={5}
                        value={count}
                        onChange={(e) => setCount(Number(e.target.value))}
                        className="mt-6 h-2 w-full cursor-pointer appearance-none rounded-full bg-stone-200 dark:bg-stone-700
                            [&::-webkit-slider-thumb]:size-8 [&::-webkit-slider-thumb]:appearance-none [&::-webkit-slider-thumb]:rounded-full [&::-webkit-slider-thumb]:border-[4px] [&::-webkit-slider-thumb]:border-white [&::-webkit-slider-thumb]:bg-stone-900 [&::-webkit-slider-thumb]:shadow-lg [&::-webkit-slider-thumb]:transition-transform [&::-webkit-slider-thumb]:hover:scale-110 dark:[&::-webkit-slider-thumb]:border-stone-800 dark:[&::-webkit-slider-thumb]:bg-stone-100
                            [&::-moz-range-thumb]:size-8 [&::-moz-range-thumb]:appearance-none [&::-moz-range-thumb]:rounded-full [&::-moz-range-thumb]:border-[4px] [&::-moz-range-thumb]:border-white [&::-moz-range-thumb]:bg-stone-900 [&::-moz-range-thumb]:shadow-lg dark:[&::-moz-range-thumb]:border-stone-800 dark:[&::-moz-range-thumb]:bg-stone-100"
                    />
                    <p className="mt-6 text-7xl font-bold tracking-tighter text-stone-900 transition-all dark:text-stone-50 sm:text-8xl">
                        {count}
                    </p>
                </div>

                <div className="mt-8 grid gap-4 sm:grid-cols-2">
                    <div className="rounded-3xl border border-stone-200/60 bg-white/70 p-8 shadow-sm shadow-stone-200/40 backdrop-blur-sm dark:border-stone-700/50 dark:bg-stone-900/60 dark:shadow-none">
                        <div className="mx-auto flex size-12 items-center justify-center rounded-2xl bg-stone-100 dark:bg-stone-800">
                            <Clock className="size-5 text-stone-500 dark:text-stone-400" />
                        </div>
                        <p className="mt-4 text-xs font-medium uppercase tracking-wider text-stone-400 dark:text-stone-500">
                            Экономия на переписках
                        </p>
                        <p className="mt-2 text-4xl font-bold tracking-tight text-stone-900 dark:text-stone-50 sm:text-5xl">
                            {hoursSaved}&nbsp;ч
                        </p>
                        <p className="mt-1 text-xs text-stone-400 dark:text-stone-500">в месяц</p>
                    </div>
                    <div className="rounded-3xl border border-stone-200/60 bg-white/70 p-8 shadow-sm shadow-stone-200/40 backdrop-blur-sm dark:border-stone-700/50 dark:bg-stone-900/60 dark:shadow-none">
                        <div className="mx-auto flex size-12 items-center justify-center rounded-2xl bg-stone-100 dark:bg-stone-800">
                            <CalendarCheck className="size-5 text-stone-500 dark:text-stone-400" />
                        </div>
                        <p className="mt-4 text-xs font-medium uppercase tracking-wider text-stone-400 dark:text-stone-500">
                            Сохранённый бюджет
                        </p>
                        <p className="mt-2 text-4xl font-bold tracking-tight text-stone-900 dark:text-stone-50 sm:text-5xl">
                            {moneySaved.toLocaleString('ru-RU')}&nbsp;₽
                        </p>
                        <p className="mt-1 text-xs text-stone-400 dark:text-stone-500">
                            от неявок при среднем чеке 2 500 ₽
                        </p>
                    </div>
                </div>
            </div>
        </section>
    );
}

/* ═══════════════════════ B4 — How It Works ═══════════════════════ */

function HowItWorks() {
    const steps = [
        { icon: Calendar, num: '01', title: 'Выбор услуги', desc: 'Клиент открывает ваш профиль и выбирает услугу в один тап' },
        { icon: Clock, num: '02', title: 'Интерактивный календарь', desc: 'Видит свободные слоты и выбирает удобное время' },
        { icon: Fingerprint, num: '03', title: 'Вход без паролей', desc: 'Подтверждение по номеру телефона — никаких регистраций' },
        { icon: BadgeCheck, num: '04', title: 'Жёсткое подтверждение', desc: 'Запись зафиксирована. Напоминание придёт само' },
    ];

    return (
        <section className="border-y border-stone-200/50 bg-stone-100/40 px-6 py-24 dark:border-stone-800/50 dark:bg-stone-900/30">
            <div className="mx-auto max-w-5xl">
                <p className="text-center text-sm font-medium uppercase tracking-[0.2em] text-stone-400 dark:text-stone-500">
                    Как это работает
                </p>
                <h2 className="mt-4 text-center text-3xl font-bold tracking-tight text-stone-900 dark:text-stone-50 sm:text-4xl">
                    Клиент записывается за 4 простых шага
                </h2>

                <div className="mt-14 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                    {steps.map(({ icon: Icon, num, title, desc }, i) => (
                        <div key={num} className="relative">
                            {i < steps.length - 1 && (
                                <div className="absolute left-8 top-10 hidden h-px w-full bg-stone-200 dark:bg-stone-700 lg:block" />
                            )}
                            <div className={`relative ${cardBase} text-center`}>
                                <div className="mx-auto flex size-12 items-center justify-center rounded-2xl bg-stone-900 text-white dark:bg-stone-100 dark:text-stone-900">
                                    <Icon className="size-5" />
                                </div>
                                <span className="mt-4 block text-xs font-bold tracking-widest text-stone-300 dark:text-stone-600">{num}</span>
                                <h3 className="mt-1 text-base font-semibold text-stone-900 dark:text-stone-50">{title}</h3>
                                <p className="mt-2 text-sm text-stone-500 dark:text-stone-400">{desc}</p>
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}

/* ═══════════════════════ B5 — Master as Client ═══════════════════════ */

function MasterAsClient() {
    const [mode, setMode] = useState<'b2b' | 'b2c'>('b2b');

    useEffect(() => {
        const id = setInterval(() => setMode((m) => (m === 'b2b' ? 'b2c' : 'b2b')), 4000);
        return () => clearInterval(id);
    }, []);

    return (
        <section className="px-6 py-24 sm:py-32">
            <div className="mx-auto max-w-5xl">
                <p className="text-center text-sm font-medium uppercase tracking-[0.2em] text-stone-400 dark:text-stone-500">
                    Сквозной профиль
                </p>
                <h2 className="mt-4 text-center text-3xl font-bold tracking-tight text-stone-900 dark:text-stone-50 sm:text-4xl">
                    Мастер как клиент
                </h2>
                <p className="mx-auto mt-4 max-w-lg text-center text-stone-500 dark:text-stone-400">
                    Переключайтесь между панелью мастера и личным кабинетом клиента без жёсткой перезагрузки
                </p>

                {/* Toggle */}
                <div className="mx-auto mt-10 flex w-fit rounded-full border border-stone-200 bg-white p-1 dark:border-stone-700 dark:bg-stone-900">
                    {(['b2b', 'b2c'] as const).map((m) => (
                        <button
                            key={m}
                            onClick={() => setMode(m)}
                            className={`relative rounded-full px-5 py-2 text-sm font-medium transition-colors ${
                                mode === m
                                    ? 'bg-stone-900 text-white dark:bg-stone-100 dark:text-stone-900'
                                    : 'text-stone-500 hover:text-stone-700 dark:text-stone-400 dark:hover:text-stone-200'
                            }`}
                        >
                            {m === 'b2b' ? 'Мастер' : 'Клиент'}
                        </button>
                    ))}
                </div>

                {/* Card that transitions */}
                <div className="mx-auto mt-10 max-w-lg overflow-hidden rounded-3xl border border-stone-200/60 bg-white/70 shadow-sm shadow-stone-200/30 backdrop-blur-sm dark:border-stone-700/40 dark:bg-stone-900/50 dark:shadow-none">
                    {/* B2B view */}
                    <div className={`transition-all duration-500 ${mode === 'b2b' ? 'opacity-100' : 'absolute inset-0 opacity-0'}`}>
                        <div className="p-6">
                            <p className="text-xs font-medium uppercase tracking-wider text-stone-400 dark:text-stone-500">
                                Расписание мастера
                            </p>
                            <div className="mt-4 space-y-2">
                                {[
                                    { time: '10:00', client: 'Мария', service: 'Маникюр', status: 'booked' },
                                    { time: '12:00', client: 'Елена', service: 'Стрижка', status: 'no_show' },
                                    { time: '14:30', client: 'Ольга', service: 'Окрашивание', status: 'booked' },
                                ].map((s) => (
                                    <div key={s.time} className="flex items-center gap-3 rounded-2xl bg-stone-50 p-3 dark:bg-stone-800/50">
                                        <span className="w-12 text-sm font-semibold text-stone-900 dark:text-stone-100">{s.time}</span>
                                        <div className="flex-1">
                                            <p className="text-sm font-medium text-stone-900 dark:text-stone-100">{s.client}</p>
                                            <p className="text-xs text-stone-400 dark:text-stone-500">{s.service}</p>
                                        </div>
                                        <span className={`rounded-full px-2 py-0.5 text-[10px] font-medium ${
                                            s.status === 'booked'
                                                ? 'bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-400'
                                                : 'bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400'
                                        }`}>
                                            {s.status === 'booked' ? 'Подтв.' : 'Ожид.'}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>

                    {/* B2C view */}
                    <div className={`p-6 transition-all duration-500 ${mode === 'b2c' ? 'opacity-100' : 'absolute inset-0 opacity-0'}`}>
                        <p className="text-xs font-medium uppercase tracking-wider text-stone-400 dark:text-stone-500">
                            Мои визиты
                        </p>
                        <div className="mt-4 space-y-2">
                            {[
                                { date: '15 апреля', service: 'Маникюр', master: 'Анна', time: '15:00' },
                                { date: '22 апреля', service: 'Стрижка', master: 'Мария', time: '11:00' },
                                { date: '3 мая', service: 'Окрашивание', master: 'Елена', time: '14:30' },
                            ].map((v) => (
                                <div key={v.date} className="flex items-center gap-3 rounded-2xl bg-stone-50 p-3 dark:bg-stone-800/50">
                                    <div className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-stone-200 dark:bg-stone-700">
                                        <Calendar className="size-4 text-stone-500 dark:text-stone-400" />
                                    </div>
                                    <div className="flex-1">
                                        <p className="text-sm font-medium text-stone-900 dark:text-stone-100">{v.service}</p>
                                        <p className="text-xs text-stone-400 dark:text-stone-500">{v.master} · {v.date}, {v.time}</p>
                                    </div>
                                    <ChevronRight className="size-4 text-stone-300 dark:text-stone-600" />
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            </div>
        </section>
    );
}

/* ═══════════════════════ B6 — Pricing ═══════════════════════ */

function Pricing() {
    return (
        <section className="border-y border-stone-200/50 bg-stone-100/40 px-6 py-24 dark:border-stone-800/50 dark:bg-stone-900/30">
            <div className="mx-auto max-w-5xl">
                <p className="text-center text-sm font-medium uppercase tracking-[0.2em] text-stone-400 dark:text-stone-500">
                    Тарифы
                </p>
                <h2 className="mt-4 text-center text-3xl font-bold tracking-tight text-stone-900 dark:text-stone-50 sm:text-4xl">
                    Простые цены без сюрпризов
                </h2>
                <p className="mt-3 text-center text-stone-500 dark:text-stone-400">
                    Начните бесплатно. Подключите Профи, когда вырастете.
                </p>

                <div className="mt-14 grid gap-5 sm:grid-cols-3">
                    {/* Старт */}
                    <div className={cardBase}>
                        <h3 className="text-lg font-semibold text-stone-900 dark:text-stone-50">Старт</h3>
                        <div className="mt-3 flex items-baseline gap-1">
                            <span className="text-4xl font-bold tracking-tight text-stone-900 dark:text-stone-50">0&nbsp;₽</span>
                            <span className="text-sm text-stone-400 dark:text-stone-500">навсегда</span>
                        </div>
                        <ul className="mt-8 space-y-3.5">
                            {['До 30 записей в месяц', 'Кастомные тексты напоминаний', 'Telegram и Max боты'].map((item) => (
                                <li key={item} className="flex items-start gap-3 text-sm text-stone-600 dark:text-stone-300">
                                    <span className="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-stone-100 dark:bg-stone-800">
                                        <Check className="size-3 text-stone-500 dark:text-stone-400" />
                                    </span>
                                    {item}
                                </li>
                            ))}
                        </ul>
                        <Button variant="outline" className="mt-8 w-full rounded-full border-stone-200 text-stone-700 hover:bg-stone-50 dark:border-stone-700 dark:text-stone-300 dark:hover:bg-stone-800">
                            Выбрать Старт
                        </Button>
                    </div>

                    {/* Профи */}
                    <div className="relative rounded-3xl border-2 border-stone-900 bg-white p-8 shadow-lg shadow-stone-200/40 dark:border-stone-100 dark:bg-stone-900 dark:shadow-stone-900/50 sm:-mt-3 sm:mb-[-12px] sm:pb-11">
                        <div className="absolute -top-3.5 left-1/2 -translate-x-1/2">
                            <span className="inline-flex items-center gap-1.5 rounded-full bg-stone-900 px-4 py-1.5 text-xs font-semibold tracking-wide text-white dark:bg-stone-100 dark:text-stone-900">
                                <Sparkles className="size-3" />
                                Популярный
                            </span>
                        </div>
                        <h3 className="text-lg font-semibold text-stone-900 dark:text-stone-50">Профи</h3>
                        <div className="mt-3 flex items-baseline gap-1">
                            <span className="text-4xl font-bold tracking-tight text-stone-900 dark:text-stone-50">490&nbsp;₽</span>
                            <span className="text-sm text-stone-400 dark:text-stone-500">в месяц</span>
                        </div>
                        <ul className="mt-8 space-y-3.5">
                            {['Безлимитные записи', 'Расширенная аналитика', 'Приоритетная поддержка', 'Всё из тарифа «Старт»'].map((item) => (
                                <li key={item} className="flex items-start gap-3 text-sm text-stone-600 dark:text-stone-300">
                                    <span className="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-stone-900 dark:bg-stone-100">
                                        <Check className="size-3 text-white dark:text-stone-900" />
                                    </span>
                                    {item}
                                </li>
                            ))}
                        </ul>
                        <Button className="mt-8 w-full rounded-full bg-stone-900 text-white shadow-md shadow-stone-900/20 hover:bg-stone-800 dark:bg-stone-100 dark:text-stone-900 dark:shadow-stone-100/10 dark:hover:bg-stone-200">
                            Подключить Профи
                        </Button>
                    </div>

                    {/* Студия */}
                    <div className={cardBase}>
                        <h3 className="text-lg font-semibold text-stone-900 dark:text-stone-50">Студия</h3>
                        <div className="mt-3 flex items-baseline gap-1">
                            <span className="text-4xl font-bold tracking-tight text-stone-900 dark:text-stone-50">1&nbsp;290&nbsp;₽</span>
                            <span className="text-sm text-stone-400 dark:text-stone-500">в месяц</span>
                        </div>
                        <ul className="mt-8 space-y-3.5">
                            {['Профиль организации', 'До 5 мастеров', 'Общее расписание', 'Всё из тарифа «Профи»'].map((item) => (
                                <li key={item} className="flex items-start gap-3 text-sm text-stone-600 dark:text-stone-300">
                                    <span className="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-stone-100 dark:bg-stone-800">
                                        <Check className="size-3 text-stone-500 dark:text-stone-400" />
                                    </span>
                                    {item}
                                </li>
                            ))}
                        </ul>
                        <Button variant="outline" className="mt-8 w-full rounded-full border-stone-200 text-stone-700 hover:bg-stone-50 dark:border-stone-700 dark:text-stone-300 dark:hover:bg-stone-800">
                            Создать Студию
                        </Button>
                    </div>
                </div>
            </div>
        </section>
    );
}

/* ═══════════════════════ B7 — Final CTA ═══════════════════════ */

function FinalCTA() {
    return (
        <section className="relative overflow-hidden px-6 py-24 sm:py-32">
            <Glow className="top-1/2 left-1/2 h-96 w-96 -translate-x-1/2 -translate-y-1/2 bg-rose-200/30 dark:bg-rose-900/10" />
            <div className="relative mx-auto max-w-2xl text-center">
                <h2 className="text-3xl font-bold tracking-tight text-stone-900 dark:text-stone-50 sm:text-4xl">
                    Готовы перестать терять клиентов?
                </h2>
                <p className="mx-auto mt-4 max-w-md text-stone-500 dark:text-stone-400">
                    Подключите сервис бесплатно за 1 минуту. Без ввода банковских карт,
                    без обязательств.
                </p>
                <div className="mt-8">
                    <Button
                        size="lg"
                        className="group h-13 rounded-full bg-stone-900 px-8 text-base text-white shadow-lg shadow-stone-900/20 transition-all hover:scale-[1.03] hover:shadow-xl dark:bg-stone-100 dark:text-stone-900 dark:shadow-stone-100/10"
                        onClick={() => router.get('/auth/login')}
                    >
                        Подключить сервис бесплатно
                        <ArrowRight className="size-4 transition-transform group-hover:translate-x-0.5" />
                    </Button>
                    <p className="mt-3 text-xs text-stone-400 dark:text-stone-500">
                        Без ввода банковских карт · Бесплатно навсегда на тарифе Старт
                    </p>
                </div>
            </div>
        </section>
    );
}

/* ═══════════════════════ Footer ═══════════════════════ */

function Footer() {
    return (
        <footer className="border-t border-stone-200/50 px-6 py-12 dark:border-stone-800/50">
            <div className="mx-auto flex max-w-5xl flex-col items-center gap-5 text-center">
                <span className="text-lg font-bold tracking-tight text-stone-900 dark:text-stone-50">вовремя</span>
                <div className="flex items-center gap-6 text-sm text-stone-400 dark:text-stone-500">
                    <a href="#" className="transition-colors hover:text-stone-600 dark:hover:text-stone-300">Условия</a>
                    <a href="#" className="transition-colors hover:text-stone-600 dark:hover:text-stone-300">Конфиденциальность</a>
                </div>
                <div className="flex items-center gap-3">
                    <p className="text-xs text-stone-300 dark:text-stone-600">
                        © {new Date().getFullYear()} Вовремя
                    </p>
                    <ThemeToggle />
                </div>
            </div>
        </footer>
    );
}

/* ═══════════════════════ Main Page ═══════════════════════ */

export default function Welcome() {
    return (
        <>
            <Head title="Вовремя — онлайн-запись для мастеров" />

            {/* Header */}
            <header className="sticky top-0 z-40 border-b border-stone-200/50 bg-[#FAF8F5]/80 backdrop-blur-xl dark:border-stone-800/60 dark:bg-[#121110]/80">
                <div className="mx-auto flex h-16 max-w-5xl items-center justify-between px-6">
                    <span className="text-xl font-bold tracking-tight text-stone-900 dark:text-stone-50">вовремя</span>
                    <div className="flex items-center gap-1">
                        <ThemeToggle />
                        <Button
                            variant="ghost"
                            size="sm"
                            className="ml-1 rounded-full px-4 text-stone-600 hover:text-stone-900 dark:text-stone-400 dark:hover:text-stone-50"
                            onClick={() => router.get('/auth/login')}
                        >
                            Вход для мастеров
                        </Button>
                    </div>
                </div>
            </header>

            <HeroSection />
            <PainPoints />
            <Calculator />
            <HowItWorks />
            <MasterAsClient />
            <Pricing />
            <FinalCTA />
            <Footer />
        </>
    );
}
