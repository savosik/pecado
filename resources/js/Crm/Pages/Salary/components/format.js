/**
 * Форматирование чисел раздела «Зарплата»: всё в рублях, всё по-русски.
 */
export const fmtRub = (value, digits = 2) => `${Number(value ?? 0).toLocaleString('ru-RU', {
    minimumFractionDigits: digits,
    maximumFractionDigits: digits,
})} ₽`;

/** Без копеек — для плиток и заголовков. */
export const fmtRub0 = (value) => fmtRub(value, 0);

/** Со знаком: «+5 000 ₽», «−16 565 ₽». */
export const fmtSigned = (value) => {
    const amount = Number(value ?? 0);
    if (Math.abs(amount) < 0.005) return '0 ₽';

    return `${amount > 0 ? '+' : '−'}${fmtRub0(Math.abs(amount))}`;
};

export const fmtCompact = (value) => {
    const amount = Number(value ?? 0);
    const abs = Math.abs(amount);

    if (abs >= 1_000_000) return `${(amount / 1_000_000).toLocaleString('ru-RU', { maximumFractionDigits: 2 })} млн ₽`;
    if (abs >= 1_000) return `${(amount / 1_000).toLocaleString('ru-RU', { maximumFractionDigits: 0 })} тыс ₽`;

    return fmtRub0(amount);
};

/** Доля 0.7364 → «73,6 %». */
export const fmtPercent = (share, digits = 1) => (share === null || share === undefined
    ? '—'
    : `${(Number(share) * 100).toLocaleString('ru-RU', { maximumFractionDigits: digits })} %`);

/** Коэффициент 0.8 → «0,8». */
export const fmtFactor = (value) => (value === null || value === undefined
    ? '—'
    : Number(value).toLocaleString('ru-RU', { maximumFractionDigits: 3 }));

/** «12 авг» из «2026-08-12». */
export const fmtDay = (iso) => {
    if (!iso) return '—';
    const d = new Date(`${iso}T00:00:00`);
    if (Number.isNaN(d.getTime())) return iso;

    return d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' }).replace('.', '');
};

/** «12.08.2026 14:03» из ISO-времени. */
export const fmtDateTime = (iso) => {
    if (!iso) return '—';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return iso;

    return d.toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
};

/** «только что» / «3 мин назад» / «2 ч назад». */
export const fmtAgo = (iso, now = Date.now()) => {
    if (!iso) return '';
    const diff = Math.max(0, Math.round((now - new Date(iso).getTime()) / 1000));

    if (diff < 60) return 'только что';
    if (diff < 3600) return `${Math.round(diff / 60)} мин назад`;
    if (diff < 86400) return `${Math.round(diff / 3600)} ч назад`;

    return fmtDateTime(iso);
};

export const plural = (count, one, few, many) => {
    const n = Math.abs(Number(count ?? 0));
    const tail = n % 10;
    const teen = n % 100 >= 11 && n % 100 <= 14;

    if (!teen && tail === 1) return one;
    if (!teen && tail >= 2 && tail <= 4) return few;

    return many;
};
