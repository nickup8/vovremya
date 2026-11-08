import { AppointmentStatus } from '@/types/appointment-status';
import type { Appointment, AppointmentWithCollision } from './types';
import { timeToMinutes } from './helpers';

const FREE_SLOT_PREFIX = '__free_slot__:';

function overlapsInTime(a: Appointment, b: Appointment): boolean {
    const aStart = timeToMinutes(a.time);
    const bStart = timeToMinutes(b.time);

    return aStart < bStart + b.duration && bStart < aStart + a.duration;
}

/**
 * Маркер свободной зоны под отменённой записью (N11).
 * Если запись отменена и не пересекается с действующими — слот считается
 * свободным: добавляем маркер, чтобы calculateCollisions отвёл карточке
 * свою колонку, а соседняя осталась открытой для клика по слоту (создание
 * записи). Маркеры участвуют только в раскладке и не рендерятся.
 */
function withFreeSlotMarkers(appointments: Appointment[]): Appointment[] {
    // Освобождённые отменённые: не пересекаются с действующими записями.
    const freed = appointments.filter(
        (appt) =>
            appt.status === AppointmentStatus.Cancelled &&
            !appointments.some(
                (other) =>
                    other.id !== appt.id &&
                    other.status !== AppointmentStatus.Cancelled &&
                    overlapsInTime(appt, other),
            ),
    );

    if (freed.length === 0) {
        return appointments;
    }

    // Один маркер на цепочку взаимно пересекающихся отменённых: отдельный
    // маркер на каждую запись добавлял бы колонки и сужал карточки.
    const sorted = [...freed].sort(
        (a, b) => timeToMinutes(a.time) - timeToMinutes(b.time),
    );
    const clusters: Array<{ first: Appointment; end: number }> = [];

    for (const appt of sorted) {
        const start = timeToMinutes(appt.time);
        const top = clusters[clusters.length - 1];

        if (!top || start >= top.end) {
            clusters.push({ first: appt, end: start + appt.duration });
        } else {
            top.end = Math.max(top.end, start + appt.duration);
        }
    }

    const markers = clusters.map(({ first }) => ({
        ...first,
        id: `${FREE_SLOT_PREFIX}${first.id}`,
    }));

    return [...appointments, ...markers];
}

/**
 * Алгоритм расчёта коллизий для записей одного дня.
 * Работает аналогично Google Calendar: пересекающиеся записи
 * распределяются по колонкам, каждая получает свою ширину.
 */
export function calculateCollisions(appointments: Appointment[]): AppointmentWithCollision[] {
    if (appointments.length === 0) {
return [];
}

    // Шаг 1: Сортировка по времени начала, при равенстве — по убыванию длительности
    const sorted = [...appointments].sort((a, b) => {
        const startDiff = timeToMinutes(a.time) - timeToMinutes(b.time);

        if (startDiff !== 0) {
return startDiff;
}

        return b.duration - a.duration;
    });

    // Шаг 2: Разбивка на группы (кластеры) пересекающихся записей
    const groups: Appointment[][] = [];
    let currentGroup: Appointment[] = [sorted[0]];

    for (let i = 1; i < sorted.length; i++) {
        const current = sorted[i];
        const currentStart = timeToMinutes(current.time);
        const currentEnd = currentStart + current.duration;

        // Проверяем, пересекается ли текущая запись с любой из текущей группы
        const overlaps = currentGroup.some((g) => {
            const gStart = timeToMinutes(g.time);
            const gEnd = gStart + g.duration;

            return currentStart < gEnd && gStart < currentEnd;
        });

        if (overlaps) {
            currentGroup.push(current);
        } else {
            groups.push(currentGroup);
            currentGroup = [current];
        }
    }

    groups.push(currentGroup);

    // Шаг 3: Внутри каждой группы распределяем по колонкам
    const result: AppointmentWithCollision[] = [];

    for (const group of groups) {
        // Массив колонок: в каждой колонке хранится время окончания последней записи
        const columns: number[][] = [];

        for (const appt of group) {
            const apptStart = timeToMinutes(appt.time);
            const apptEnd = apptStart + appt.duration;

            // Ищем первую свободную колонку
            let placed = false;

            for (let col = 0; col < columns.length; col++) {
                const lastEnd = columns[col][columns[col].length - 1];

                if (apptStart >= lastEnd) {
                    columns[col].push(apptEnd);
                    result.push({ ...appt, colIndex: col, totalCols: 0 });
                    placed = true;
                    break;
                }
            }

            // Если свободной колонки нет — создаём новую
            if (!placed) {
                columns.push([apptEnd]);
                result.push({ ...appt, colIndex: columns.length - 1, totalCols: 0 });
            }
        }

        // Присваиваем totalCols для всех записей в группе
        const totalCols = columns.length;

        for (const appt of result) {
            if (group.some((g) => g.id === appt.id)) {
                appt.totalCols = totalCols;
            }
        }
    }

    return result;
}

/**
 * Раскладка записей одного дня для сетки: маркеры свободной зоны участвуют
 * только в расчёте колонок и в результат не попадают (N11).
 */
export function calculateDayLayout(
    appointments: Appointment[],
): AppointmentWithCollision[] {
    return calculateCollisions(withFreeSlotMarkers(appointments)).filter(
        (appt) => !appt.id.startsWith(FREE_SLOT_PREFIX),
    );
}
