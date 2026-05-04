// Палитра для диаграмм (recharts). Подобрана под фирменный pecado.* и базовые токены.
const PALETTE = [
    '#9e1b32', '#3b82f6', '#10b981', '#f59e0b', '#8b5cf6',
    '#ec4899', '#14b8a6', '#f43f5e', '#0ea5e9', '#84cc16',
    '#a855f7', '#f97316', '#06b6d4', '#22c55e', '#eab308',
    '#6366f1', '#d946ef', '#65a30d', '#0891b2', '#dc2626',
];

export function usePalette() {
    return PALETTE;
}

export function colorAt(index) {
    return PALETTE[index % PALETTE.length];
}
