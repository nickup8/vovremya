import { useEffect } from 'react';
import { usePage } from '@inertiajs/react';
import { toast } from 'sonner';

type FlashProps = {
    success?: string;
    error?: string;
    message?: string;
    id?: string | null;
};

export function useFlashToast() {
    const { flash } = usePage().props as { flash?: FlashProps };

    const flashId = flash?.id ?? null;
    const success = flash?.success;
    const error = flash?.error;
    const message = flash?.message;

    useEffect(() => {
        if (success) {
            toast.success(success);
        }

        if (error) {
            toast.error(error);
        }

        if (message) {
            toast(message);
        }
    }, [flashId, success, error, message]);
}
