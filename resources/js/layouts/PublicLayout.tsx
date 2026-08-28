import type { ReactNode } from 'react';
import { Toaster } from '@/components/ui/sonner';

export default function PublicLayout({ children }: { children: ReactNode }) {
    return (
        <div className="min-h-screen bg-white dark:bg-[#0a0a0a]">
            {children}
            <Toaster />
        </div>
    );
}
