import { useEffect } from 'react';
import { usePage } from '@inertiajs/react';
import { toast } from 'sonner';

type FlashProps = {
    success?: string;
    error?: string;
    message?: string;
    id?: string | null;
};

// Survives StrictMode double-invoke and Toaster remount while the same
// flash event is still in page props. A new backend flash.id always differs.
let lastHandledFlashId: string | null = null;

export function resetFlashToastHandledState(): void {
    lastHandledFlashId = null;
}

function flashEventKey(flash: FlashProps | undefined): string | null {
    const id = flash?.id ?? null;

    if (id) {
        return id;
    }

    // Legacy fallback when backend does not send id: treat the text bag as
    // one event key so empty flash never toasts.
    if (flash?.success || flash?.error || flash?.message) {
        return `legacy:${flash.success ?? ''}|${flash.error ?? ''}|${flash.message ?? ''}`;
    }

    return null;
}

export function useFlashToast() {
    const { flash } = usePage().props as { flash?: FlashProps };

    const flashId = flash?.id ?? null;
    const success = flash?.success;
    const error = flash?.error;
    const message = flash?.message;

    useEffect(() => {
        const eventKey = flashEventKey({ success, error, message, id: flashId });

        if (!eventKey || eventKey === lastHandledFlashId) {
            return;
        }

        if (success) {
            toast.success(success);
        }

        if (error) {
            toast.error(error);
        }

        if (message) {
            toast(message);
        }

        lastHandledFlashId = eventKey;
    }, [flashId, success, error, message]);
}
