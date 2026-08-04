/**
 * Шкала заливки грядки: одна последовательная шкала «пусто → собрано».
 *
 * Один тон, монотонная светлота — поэтому читается и при дальтонизме: различие
 * несёт светлота, а не оттенок. Шаги прогнаны валидатором палитры (монотонность,
 * зазор между шагами, контраст светлого конца к фону) отдельно для светлой
 * и тёмной темы; тёмная — не автоматическая инверсия светлой, а свой набор шагов
 * от того же тона, проверенный против тёмного фона.
 *
 * Ноль в шкалу не входит намеренно: «план есть, отгрузок нет» — это голая земля,
 * а не первый шаг заливки. Бледно-голубая плитка выглядела бы как «немножко
 * сделано», хотя не сделано ничего.
 */

/** Пять шагов заливки для 1…100 % и выше. Светлая тема. */
const LIGHT_STEPS = ['#86b6ef', '#5598e7', '#2a78d6', '#1c5cab', '#0d366b'];

/** Тёмная тема: те же пять ступеней того же тона, подобранные к тёмному фону. */
const DARK_STEPS = ['#184f95', '#256abf', '#3987e5', '#6da7ec', '#b7d3f6'];

/**
 * Пороги, по которым процент попадает в ступень.
 * Последняя ступень — «план закрыт», включая перевыполнение.
 */
const BOUNDS = [25, 50, 75, 100];

/** Голая грядка: план стоит, отгрузок нет. */
const EMPTY_FILL = { light: '#e7e5e0', dark: '#2e2e2b' };

/** Плана нет — площадь взята от оборота. Отсутствие плана не равно нулю. */
const NO_PLAN_FILL = { light: '#f0efec', dark: '#262624' };

function stepIndex(percent) {
    for (let i = 0; i < BOUNDS.length; i += 1) {
        if (percent <= BOUNDS[i]) return i;
    }

    return BOUNDS.length;
}

/**
 * Заливка плитки.
 *
 * @param {{percent: number|null, plan: number|null}} tile
 * @param {boolean} dark тёмная тема
 */
export function bedFill(tile, dark) {
    if (tile.plan === null || tile.plan === undefined) {
        return dark ? NO_PLAN_FILL.dark : NO_PLAN_FILL.light;
    }

    const percent = Number(tile.percent ?? 0);

    if (percent <= 0) {
        return dark ? EMPTY_FILL.dark : EMPTY_FILL.light;
    }

    const steps = dark ? DARK_STEPS : LIGHT_STEPS;

    return steps[stepIndex(percent)];
}

/**
 * Цвет подписи на плитке.
 *
 * Считается от той же ступени, что и заливка: подпись на плитке — единственное
 * место, где текст лежит поверх цвета данных, и её контраст нельзя оставлять
 * на усмотрение темы.
 */
export function bedInk(tile, dark) {
    if (tile.plan === null || tile.plan === undefined || Number(tile.percent ?? 0) <= 0) {
        return dark ? '#e8e6e1' : '#1b1b19';
    }

    const index = stepIndex(Number(tile.percent ?? 0));

    // Светлая тема темнеет с ростом заливки, тёмная — светлеет, поэтому
    // «когда переключать чернила на белые» у них зеркальное.
    return dark
        ? (index >= 3 ? '#10203a' : '#ffffff')
        : (index >= 2 ? '#ffffff' : '#10203a');
}

/**
 * Легенда шкалы — подписи ступеней в том же порядке, что и заливка.
 *
 * Легенда обязательна: заливка кодирует величину, и без подписей ступеней
 * читатель угадывает, что означает «средне-синий».
 */
export function bedLegend(dark) {
    const steps = dark ? DARK_STEPS : LIGHT_STEPS;

    return [
        { label: 'плана нет', color: dark ? NO_PLAN_FILL.dark : NO_PLAN_FILL.light },
        { label: 'пусто', color: dark ? EMPTY_FILL.dark : EMPTY_FILL.light },
        { label: 'до 25%', color: steps[0] },
        { label: '25–50%', color: steps[1] },
        { label: '50–75%', color: steps[2] },
        { label: '75–100%', color: steps[3] },
        { label: 'закрыт', color: steps[4] },
    ];
}
