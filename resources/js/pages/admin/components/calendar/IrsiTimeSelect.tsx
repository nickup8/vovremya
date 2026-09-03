import { Clock } from 'lucide-react';
import {
    Select, SelectTrigger, SelectValue, SelectContent, SelectItem,
} from '@/components/ui/select';

interface Props {
    value: string;
    onChange: (value: string) => void;
    options: string[];
}

export function IrsiTimeSelect({ value, onChange, options }: Props) {
    return (
        <Select value={value} onOpenChange={() => {}} onValueChange={onChange}>
            <SelectTrigger className="w-full border-[var(--color-line)] bg-white text-sm text-[var(--color-ink)] dark:border-[var(--color-cal-border)] dark:bg-[var(--color-cal-surface)] [&>span]:flex [&>span]:items-center [&>span]:gap-2">
                <Clock className="size-4 shrink-0 opacity-50" />
                <SelectValue placeholder="ЧЧ:ММ" />
            </SelectTrigger>
            <SelectContent className="max-h-[280px] rounded-xl border-[var(--color-line)] bg-white shadow-sm dark:border-[var(--color-cal-border)] dark:bg-[var(--color-cal-surface)]">
                {options.map((t) => (
                    <SelectItem
                        key={t}
                        value={t}
                        className="rounded-lg focus:bg-[var(--color-surface-hover)] focus:text-[var(--color-ink)]"
                    >
                        {t}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}
