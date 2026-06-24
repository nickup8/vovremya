import { Toaster } from '@/components/ui/sonner';
import type { ReactNode } from 'react';

export default function ClientLayout({ children }: { children: ReactNode }) {
    return (
        <div className="min-h-screen bg-white dark:bg-[#0a0a0a]">
            <div className="mx-auto max-w-3xl px-4 py-8 sm:px-6 lg:px-8">
                {children}
            </div>
            <Toaster />
        </div>
    );
}
