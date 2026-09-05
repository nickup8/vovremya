import { useCallback, useEffect, useRef, useState } from 'react';

interface MaxTimePickerProps {
    value: string;
    onChange: (value: string) => void;
    disabled?: boolean;
}

const HOURS = Array.from({ length: 24 }, (_, i) => i);
const MINUTES = Array.from({ length: 60 }, (_, i) => i);

function formatDisplay(v: string): string {
    return v || 'ЧЧ:ММ';
}

function scrollToSelected(el: HTMLElement | null, index: number) {
    if (!el) return;
    const item = el.children[index] as HTMLElement | undefined;
    if (item) {
        el.scrollTo({ top: item.offsetTop - el.offsetHeight / 2 + item.offsetHeight / 2, behavior: 'auto' });
    }
}

export function MaxTimePicker({ value, onChange, disabled = false }: MaxTimePickerProps) {
    const [open, setOpen] = useState(false);

    const initHour = value ? parseInt(value.split(':')[0], 10) : 10;
    const initMinute = value ? parseInt(value.split(':')[1], 10) : 0;

    const [hour, setHour] = useState(initHour);
    const [minute, setMinute] = useState(initMinute);

    const hourRef = useRef<HTMLDivElement>(null);
    const minuteRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) return;
        const h = value ? parseInt(value.split(':')[0], 10) : 10;
        const m = value ? parseInt(value.split(':')[1], 10) : 0;
        setHour(h);
        setMinute(m);
        requestAnimationFrame(() => {
            scrollToSelected(hourRef.current, HOURS.indexOf(h));
            scrollToSelected(minuteRef.current, MINUTES.indexOf(m));
        });
    }, [open]);

    const handleConfirm = useCallback(() => {
        const hh = String(hour).padStart(2, '0');
        const mm = String(minute).padStart(2, '0');
        onChange(`${hh}:${mm}`);
        setOpen(false);
    }, [hour, minute, onChange]);

    const handleOpen = () => {
        if (!disabled) setOpen(true);
    };

    return (
        <div className="mdp">
            <button
                type="button"
                className="mdp-trigger"
                onClick={handleOpen}
                disabled={disabled}
            >
                <span className={value ? 'mdp-trigger-value' : 'mdp-trigger-placeholder'}>
                    {formatDisplay(value)}
                </span>
                <svg className="mdp-trigger-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <circle cx="12" cy="12" r="9" />
                    <path d="M12 7v5l3 3" />
                </svg>
            </button>

            {open && (
                <>
                    <div className="mdp-backdrop" onClick={() => setOpen(false)} />
                    <div className="mdp-popover mtp-popover">
                        <div className="mtp-title">Выберите время</div>
                        <div className="mtp-columns">
                            <div className="mtp-column">
                                <div className="mtp-column-label">Часы</div>
                                <div className="mtp-scroll" ref={hourRef}>
                                    {HOURS.map((h) => (
                                        <button
                                            key={h}
                                            type="button"
                                            className={`mtp-cell${hour === h ? ' mtp-cell--selected' : ''}`}
                                            onClick={() => setHour(h)}
                                        >
                                            {String(h).padStart(2, '0')}
                                        </button>
                                    ))}
                                </div>
                            </div>
                            <div className="mtp-sep">:</div>
                            <div className="mtp-column">
                                <div className="mtp-column-label">Минуты</div>
                                <div className="mtp-scroll" ref={minuteRef}>
                                    {MINUTES.map((m) => (
                                        <button
                                            key={m}
                                            type="button"
                                            className={`mtp-cell${minute === m ? ' mtp-cell--selected' : ''}`}
                                            onClick={() => setMinute(m)}
                                        >
                                            {String(m).padStart(2, '0')}
                                        </button>
                                    ))}
                                </div>
                            </div>
                        </div>
                        <button type="button" className="mtp-confirm" onClick={handleConfirm}>
                            Готово
                        </button>
                    </div>
                </>
            )}
        </div>
    );
}
