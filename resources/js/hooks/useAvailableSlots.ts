import { useState, useEffect, useRef } from 'react';

interface AvailableSlots {
    freeSlots: string[];
    outsideSlots: string[];
}

export function useAvailableSlots(date: string, serviceId: string | undefined) {
    const [slots, setSlots] = useState<AvailableSlots>({ freeSlots: [], outsideSlots: [] });
    const [loading, setLoading] = useState(false);
    const abortRef = useRef<AbortController | null>(null);

    useEffect(() => {
        if (!date) {
            setSlots({ freeSlots: [], outsideSlots: [] });
            abortRef.current?.abort();
            return;
        }

        abortRef.current?.abort();
        const controller = new AbortController();
        abortRef.current = controller;
        setLoading(true);

        const url = serviceId
            ? `/admin/calendar/available-slots?date=${date}&service_id=${serviceId}`
            : `/admin/calendar/available-slots?date=${date}`;

        fetch(url, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            signal: controller.signal,
        })
            .then((res) => res.json())
            .then((data) => {
                setSlots({
                    freeSlots: data.freeSlots ?? [],
                    outsideSlots: data.outsideSlots ?? [],
                });
            })
            .catch(() => {
                if (!controller.signal.aborted) {
                    setSlots({ freeSlots: [], outsideSlots: [] });
                }
            })
            .finally(() => {
                if (!controller.signal.aborted) {
                    setLoading(false);
                }
            });

        return () => { controller.abort(); };
    }, [date, serviceId]);

    return { slots, loading };
}
