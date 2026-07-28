import { useState, useMemo, useRef, useEffect } from 'react';
import axios from 'axios';
import { toast } from 'sonner';
import { Check, ChevronsUpDown, UserPlus, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Command, CommandInput, CommandList, CommandEmpty, CommandGroup, CommandItem,
} from '@/components/ui/command';
import { PhoneInput } from '@/components/PhoneInput';
import { cn } from '@/lib/utils';
import type { ClientOption } from './types';

interface Props {
    clients: ClientOption[];
    value: string;
    onChange: (clientId: string) => void;
    onClientCreated: (client: ClientOption) => void;
}

function looksLikePhone(text: string): boolean {
    const digits = text.replace(/\D/g, '');

    return digits.length >= 3;
}

export function ClientCombobox({ clients, value, onChange, onClientCreated }: Props) {
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState('');
    const [creating, setCreating] = useState(false);
    const [newName, setNewName] = useState('');
    const [newPhone, setNewPhone] = useState('');
    const [saving, setSaving] = useState(false);
    const containerRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        function handleClickOutside(e: MouseEvent) {
            if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
                setOpen(false);
                setCreating(false);
                setSearch('');
            }
        }

        function handleKeyDown(e: KeyboardEvent) {
            if (e.key === 'Escape' && open) {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                setOpen(false);
                setCreating(false);
                setSearch('');
            }
        }

        if (open) {
            document.addEventListener('mousedown', handleClickOutside, true);
            document.addEventListener('keydown', handleKeyDown, true);
        }

        return () => {
            document.removeEventListener('mousedown', handleClickOutside, true);
            document.removeEventListener('keydown', handleKeyDown, true);
        };
    }, [open]);

    const selectedClient = useMemo(
        () => clients.find((c) => String(c.id) === value) ?? null,
        [clients, value],
    );

    const dedupedClients = useMemo(() => {
        const byPhone = new Map<string, ClientOption>();
        const noPhone: ClientOption[] = [];

        for (const c of clients) {
            const key = (c.phone ?? '').replace(/\D/g, '');

            if (!key) {
                noPhone.push(c);
                continue;
            }

            if (!byPhone.has(key)) {
                byPhone.set(key, c);
            }
        }

        return [...byPhone.values(), ...noPhone];
    }, [clients]);

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();

        if (!q) {
            return dedupedClients;
        }

        return dedupedClients.filter(
            (c) => c.name.toLowerCase().includes(q) || (c.phone ?? '').toLowerCase().includes(q),
        );
    }, [dedupedClients, search]);

    function openCreateForm() {
        if (looksLikePhone(search)) {
            setNewPhone(search);
            setNewName('');
        } else {
            setNewName(search);
            setNewPhone('');
        }

        setCreating(true);
    }

    function closeCreateForm() {
        setCreating(false);
        setNewName('');
        setNewPhone('');
    }

    function closeAll() {
        setOpen(false);
        setCreating(false);
        setSearch('');
    }

    async function handleCreate() {
        if (!newName.trim() || !newPhone.trim()) {
            toast.error('Укажите имя и телефон');

            return;
        }

        setSaving(true);

        try {
            const res = await axios.post('/admin/clients', {
                name: newName.trim(),
                phone: newPhone.trim(),
            });

            const created: ClientOption = {
                id: String(res.data.id),
                name: res.data.name,
                phone: res.data.phone ?? null,
            };

            onClientCreated(created);
            onChange(created.id);

            toast.success('Клиент добавлен');
            closeAll();
        } catch (err) {
            if (axios.isAxiosError(err) && err.response?.status === 422) {
                const errors = err.response.data?.errors ?? {};
                const first = Object.values(errors)[0];
                toast.error(Array.isArray(first) ? first[0] : 'Ошибка валидации');
            } else {
                toast.error('Не удалось создать клиента');
            }
        } finally {
            setSaving(false);
        }
    }

    return (
        <div ref={containerRef}>
            <button
                type="button"
                onClick={() => setOpen((prev) => !prev)}
                className="flex w-full items-center justify-between rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
            >
                <span className={cn(!selectedClient && 'text-slate-400 dark:text-zinc-500')}>
                    {selectedClient
                        ? `${selectedClient.name}${selectedClient.phone ? ` (${selectedClient.phone})` : ''}`
                        : 'Выберите или создайте клиента'}
                </span>
                <ChevronsUpDown className="ml-2 size-4 shrink-0 opacity-50" />
            </button>

            {open && (
                <div className="mt-1 rounded-xl border border-slate-200 bg-white p-1 shadow-md dark:border-zinc-800 dark:bg-zinc-900">
                    {creating ? (
                        <div className="space-y-3 p-3">
                            <div className="flex items-center justify-between">
                                <span className="text-sm font-medium text-slate-700 dark:text-zinc-300">
                                    Новый клиент
                                </span>
                                <button
                                    type="button"
                                    onClick={closeCreateForm}
                                    className="rounded p-1 text-slate-400 hover:bg-slate-100 dark:hover:bg-zinc-800"
                                >
                                    <X className="size-4" />
                                </button>
                            </div>

                            <div>
                                <label className="mb-1 block text-xs text-slate-500 dark:text-zinc-400">
                                    Имя *
                                </label>
                                <input
                                    type="text"
                                    value={newName}
                                    onChange={(e) => setNewName(e.target.value)}
                                    placeholder="Имя клиента"
                                    autoFocus={!looksLikePhone(search)}
                                    className="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
                                />
                            </div>

                            <div>
                                <label className="mb-1 block text-xs text-slate-500 dark:text-zinc-400">
                                    Телефон *
                                </label>
                                <PhoneInput value={newPhone} onChange={setNewPhone} />
                            </div>

                            <div className="flex gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={closeCreateForm}
                                    className="flex-1 rounded-lg"
                                >
                                    Отмена
                                </Button>
                                <Button
                                    type="button"
                                    onClick={handleCreate}
                                    disabled={saving || !newName.trim() || !newPhone.trim()}
                                    className="flex-1 rounded-lg bg-blue-600 text-white hover:bg-blue-700"
                                >
                                    {saving ? 'Сохранение...' : 'Создать'}
                                </Button>
                            </div>
                        </div>
                    ) : (
                        <Command shouldFilter={false}>
                            <CommandInput
                                placeholder="Поиск по имени или телефону..."
                                value={search}
                                onValueChange={setSearch}
                            />
                            <CommandList>
                                {filtered.length === 0 && (
                                    <CommandEmpty>Ничего не найдено</CommandEmpty>
                                )}
                                <CommandGroup>
                                    {filtered.map((c) => (
                                        <CommandItem
                                            key={c.id}
                                            value={String(c.id)}
                                            onSelect={() => {
                                                onChange(String(c.id));
                                                closeAll();
                                            }}
                                        >
                                            <Check
                                                className={cn(
                                                    'mr-2 size-4',
                                                    String(c.id) === value ? 'opacity-100' : 'opacity-0',
                                                )}
                                            />
                                            <span className="truncate">
                                                {c.name}
                                                {c.phone ? (
                                                    <span className="ml-1 text-slate-400 dark:text-zinc-500">
                                                        {c.phone}
                                                    </span>
                                                ) : null}
                                            </span>
                                        </CommandItem>
                                    ))}
                                </CommandGroup>
                            </CommandList>

                            <div className="border-t border-slate-200 p-1 dark:border-zinc-800">
                                <button
                                    type="button"
                                    onClick={openCreateForm}
                                    className="flex w-full items-center gap-2 rounded-lg px-2 py-2 text-sm font-medium text-blue-600 hover:bg-blue-50 dark:text-blue-400 dark:hover:bg-blue-950/40"
                                >
                                    <UserPlus className="size-4" />
                                    {search.trim()
                                        ? `Создать клиента: «${search.trim()}»`
                                        : 'Создать нового клиента'}
                                </button>
                            </div>
                        </Command>
                    )}
                </div>
            )}
        </div>
    );
}
