import { Appointment, AppointmentWithCollision } from './types';
import { timeToMinutes } from './helpers';

/**
 * Алгоритм расчёта коллизий для записей одного дня.
 * Работает аналогично Google Calendar: пересекающиеся записи
 * распределяются по колонкам, каждая получает свою ширину.
 */
export function calculateCollisions(appointments: Appointment[]): AppointmentWithCollision[] {
    if (appointments.length === 0) return [];

    // Шаг 1: Сортировка по времени начала, при равенстве — по убыванию длительности
    const sorted = [...appointments].sort((a, b) => {
        const startDiff = timeToMinutes(a.time) - timeToMinutes(b.time);
        if (startDiff !== 0) return startDiff;
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
