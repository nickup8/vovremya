import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Globe, Check, ChevronDown } from 'lucide-react';
import { Button } from '@/components/ui/button';

const RU_TIMEZONES = [
    { value: 'Europe/Kaliningrad', label: 'Kaliningrad (UTC+2)' },
    { value: 'Europe/Moscow', label: 'Moscow (UTC+3)' },
    { value: 'Europe/Samara', label: 'Samara (UTC+4)' },
    { value: 'Asia/Yekaterinburg', label: 'Yekaterinburg (UTC+5)' },
    { value: 'Asia/Omsk', label: 'Omsk (UTC+6)' },
    { value: 'Asia/Krasnoyarsk', label: 'Krasnoyarsk (UTC+7)' },
    { value: 'Asia/Irkutsk', label: 'Irkutsk (UTC+8)' },
    { value: 'Asia/Yakutsk', label: 'Yakutsk (UTC+9)' },
    { value: 'Asia/Vladivostok', label: 'Vladivostok (UTC+10)' },
    { value: 'Asia/Magadan', label: 'Magadan (UTC+11)' },
    { value: 'Asia/Kamchatka', label: 'Kamchatka (UTC+12)' },
];

function detectBrowserTimezone(): string {
    try {
        return Intl.DateTimeFormat().resolvedOptions().timeZone;
    } catch {
        return 'Europe/Moscow';
    }
}

interface TimezoneConfirmBannerProps {
    confirmed: boolean;
}

export default function TimezoneConfirmBanner({ confirmed }: TimezoneConfirmBannerProps) {
    const [showPicker, setShowPicker] = useState(false);
    const [selected, setSelected] = useState('');

    if (confirmed && !showPicker) {
        return null;
    }

    const detected = detectBrowserTimezone();

    function submitTimezone(tz: string) {
        router.patch('/admin/settings/timezone', { timezone: tz }, {
            preserveScroll: true,
            onFinish: () => setShowPicker(false),
        });
    }

    if (showPicker) {
        return (
            <div className="mb-6 rounded-2xl border border-amber-200 bg-amber-50 p-5 dark:border-amber-900/50 dark:bg-amber-950/30">
                <p className="mb-3 text-sm font-medium text-amber-800 dark:text-amber-200">
                    Выберите часовой пояс
                </p>
                <div className="flex items-center gap-3">
                    <div className="relative flex-1">
                        <select
                            value={selected}
                            onChange={(e) => setSelected(e.target.value)}
                            className="w-full appearance-none rounded-xl border border-amber-300 bg-white px-4 py-2.5 pr-10 text-sm text-stone-900 dark:border-amber-700 dark:bg-stone-800 dark:text-stone-100"
                        >
                            <option value="">Выберите зону...</option>
                            {RU_TIMEZONES.map((tz) => (
                                <option key={tz.value} value={tz.value}>
                                    {tz.label}
                                </option>
                            ))}
                        </select>
                        <ChevronDown className="pointer-events-none absolute right-3 top-1/2 size-4 -translate-y-1/2 text-amber-500" />
                    </div>
                    <Button
                        size="sm"
                        disabled={!selected}
                        onClick={() => submitTimezone(selected)}
                        className="shrink-0"
                    >
                        <Check className="mr-1 size-4" />
                        Сохранить
                    </Button>
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => setShowPicker(false)}
                    >
                        Отмена
                    </Button>
                </div>
            </div>
        );
    }

    return (
        <div className="mb-6 rounded-2xl border border-blue-200 bg-blue-50 p-5 dark:border-blue-900/50 dark:bg-blue-950/30">
            <div className="flex items-start gap-3">
                <Globe className="mt-0.5 size-5 shrink-0 text-blue-500 dark:text-blue-400" />
                <div className="flex-1">
                    <p className="text-sm font-medium text-blue-800 dark:text-blue-200">
                        Ваш часовой пояс определён как <span className="font-bold">{detected}</span>. Всё верно?
                    </p>
                    <div className="mt-3 flex gap-2">
                        <Button
                            size="sm"
                            onClick={() => submitTimezone(detected)}
                        >
                            <Check className="mr-1 size-4" />
                            Подтвердить
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => setShowPicker(true)}
                        >
                            Выбрать другой
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    );
}
