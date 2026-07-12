import { useRef, useCallback } from 'react';
import { IMaskInput } from 'react-imask';
import { cn } from '@/lib/utils';

interface PhoneInputProps {
    value: string;
    onChange: (value: string) => void;
    placeholder?: string;
    className?: string;
    disabled?: boolean;
    id?: string;
}

export function PhoneInput({
    value,
    onChange,
    placeholder = '+7 (911) 123-45-67',
    className,
    disabled,
    id,
}: PhoneInputProps) {
    const inputRef = useRef(null);

    const handleAccept = useCallback(
        (_value: string, maskRef: { value: string }) => {
            onChange(maskRef.value);
        },
        [onChange],
    );

    return (
        <IMaskInput
            mask="+7 (000) 000-00-00"
            value={value}
            onAccept={handleAccept}
            inputRef={inputRef}
            placeholder={placeholder}
            disabled={disabled}
            id={id}
            autoComplete="tel"
            inputMode="tel"
            className={cn(
                'flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-sm transition-colors file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50 md:text-sm',
                className,
            )}
        />
    );
}
