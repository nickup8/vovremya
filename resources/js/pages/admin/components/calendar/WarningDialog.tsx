import { AlertTriangle } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter,
} from '@/components/ui/dialog';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    message: string;
    confirmLabel: string;
    onConfirm: () => void;
    onCancel: () => void;
}

export function WarningDialog({ open, onOpenChange, title, message, confirmLabel, onConfirm, onCancel }: Props) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="rounded-2xl border-amber-200 bg-white dark:border-amber-800 dark:bg-zinc-900 sm:max-w-md">
                <DialogHeader>
                    <div className="mx-auto flex size-12 items-center justify-center rounded-full bg-amber-100 dark:bg-amber-950/40">
                        <AlertTriangle className="size-6 text-amber-600 dark:text-amber-400" />
                    </div>
                    <DialogTitle className="text-center text-slate-900 dark:text-zinc-100">
                        {title}
                    </DialogTitle>
                    <DialogDescription className="text-center text-slate-500 dark:text-zinc-400">
                        {message}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter className="flex flex-col gap-2 sm:flex-row">
                    <Button
                        variant="outline"
                        onClick={onCancel}
                        className="flex-1 rounded-xl sm:flex-none"
                    >
                        Отмена
                    </Button>
                    <Button
                        onClick={onConfirm}
                        className="flex-1 rounded-xl bg-amber-600 text-white hover:bg-amber-700 sm:flex-none"
                    >
                        {confirmLabel}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
