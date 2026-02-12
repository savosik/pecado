/**
 * Форматирование даты в русской локали.
 *
 * @param {string|Date|null|undefined} date — дата (ISO-строка или Date)
 * @param {'short'|'long'|'datetime'} [format='short'] — формат вывода
 *   - `short`    → 12.02.2026
 *   - `long`     → 12 февраля 2026
 *   - `datetime` → 12.02.2026 14:30
 * @returns {string}
 */
export function formatDate(date, format = 'short') {
    if (!date) {
        return '';
    }

    const d = date instanceof Date ? date : new Date(date);
    if (Number.isNaN(d.getTime())) {
        return '';
    }

    switch (format) {
        case 'long':
            return new Intl.DateTimeFormat('ru-RU', {
                day: 'numeric',
                month: 'long',
                year: 'numeric',
            }).format(d);

        case 'datetime':
            return new Intl.DateTimeFormat('ru-RU', {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
            }).format(d);

        case 'short':
        default:
            return new Intl.DateTimeFormat('ru-RU', {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric',
            }).format(d);
    }
}
