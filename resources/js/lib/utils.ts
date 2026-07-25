import { clsx } from 'clsx';
import type { ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

export function getInitials(name?: string): string {
    if (!name) return 'AM';
    return name.split(' ').map(w => w[0]).join('').toUpperCase().slice(0, 2);
}
