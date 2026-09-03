import { Clock } from 'lucide-react';
import {
    Select, SelectTrigger, SelectValue, SelectContent, SelectItem, SelectSeparator, SelectLabel,
} from '@/components/ui/select';

interface TimeGroup {
    label: string;
    options: string[];
}

interface Props {
    value: string;
    onChange: (value: string) => void;
    options?: string[];
    groups?: TimeGroup[];
    disabled?: boolean;
    placeholder?: string;
}

export function IrsiTimeSelect({ value, onChange, options, groups, disabled, placeholder }: Props) {
    const hasGroups = groups && groups.length > 0;
    const allEmpty = hasGroups && groups.every((g) => g.options.length === 0);

    return (
        <Select value={value} onOpenChange={() => {}} onValueChange={onChange} disabled={disabled}>
            <SelectTrigger className="w-full border-[var(--color-line)] bg-white text-sm text-[var(--color-ink)] dark:border-[var(--color-cal-border)] dark:bg-[var(--color-cal-surface)] [&>span]:flex [&>span]:items-center [&>span]:gap-2">
                <Clock className="size-4 shrink-0 opacity-50" />
                <SelectValue placeholder={placeholder ?? 'ЧЧ:ММ'} />
            </SelectTrigger>
            <SelectContent className="max-h-[280px] rounded-xl border-[var(--color-line)] bg-white shadow-sm dark:border-[var(--color-cal-border)] dark:bg-[var(--color-cal-surface)]">
                {hasGroups ? (
                    allEmpty ? (
                        <div className="px-2 py-4 text-center text-sm text-[var(--color-graphite)]">
                            Нет доступного времени
                        </div>
                    ) : (
                        groups.map((group, gi) => (
                            group.options.length > 0 && (
                                <div key={group.label}>
                                    {gi > 0 && <SelectSeparator />}
                                    <SelectLabel className="text-xs font-semibold text-[var(--color-graphite)] px-2 py-1.5">
                                        {group.label}
                                    </SelectLabel>
                                    {group.options.map((t) => (
                                        <SelectItem
                                            key={t}
                                            value={t}
                                            className="rounded-lg focus:bg-[var(--color-surface-hover)] focus:text-[var(--color-ink)]"
                                        >
                                            {t}
                                        </SelectItem>
                                    ))}
                                </div>
                            )
                        ))
                    )
                ) : (
                    (options ?? []).map((t) => (
                        <SelectItem
                            key={t}
                            value={t}
                            className="rounded-lg focus:bg-[var(--color-surface-hover)] focus:text-[var(--color-ink)]"
                        >
                            {t}
                        </SelectItem>
                    ))
                )}
            </SelectContent>
        </Select>
    );
}
