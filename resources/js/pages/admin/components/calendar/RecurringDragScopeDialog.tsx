import { Button } from '@/components/ui/button';
import {
    Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter,
} from '@/components/ui/dialog';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onOnlyThis: () => void;
    onThisAndFuture: () => void;
}

export function RecurringDragScopeDialog({ open, onOpenChange, onOnlyThis, onThisAndFuture }: Props) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="rounded-2xl border-slate-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 sm:max-w-sm">
                <DialogHeader>
                    <DialogTitle className="text-slate-900 dark:text-zinc-100">
                        Перенести запись
                    </DialogTitle>
                    <DialogDescription className="text-slate-500 dark:text-zinc-400">
                        Запись является частью серии. Что перенести?
                    </DialogDescription>
                </DialogHeader>

                <DialogFooter className="flex flex-col gap-2">
                    <Button
                        variant="outline"
                        onClick={onOnlyThis}
                        className="w-full rounded-xl"
                    >
                        Только эту запись
                    </Button>
                    <Button
                        onClick={onThisAndFuture}
                        className="w-full rounded-xl bg-[var(--color-orange)] text-white hover:bg-[var(--color-orange-600)]"
                    >
                        Эту и следующие
                    </Button>
                    <Button
                        variant="ghost"
                        onClick={() => onOpenChange(false)}
                        className="w-full rounded-xl"
                    >
                        Отмена
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
