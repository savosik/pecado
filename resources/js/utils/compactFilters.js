/**
 * Compact URL утилиты для каталога.
 *
 * Кодируют/декодируют параметры фильтров в короткие URL-параметры.
 * Дефолтные значения опускаются для чистых URL.
 */

/** Маппинг: полное имя → compact alias */
export const ALIASES = {
    attribute_value_ids: 'fv',
    brand_ids: 'b',
    category_ids: 'c',
    collection_ids: 'cl',
    sort: 's',
    view: 'v',
    per_page: 'pp',
    page: 'p',
};

/**
 * Возвращает дефолтный режим отображения товаров в зависимости от ширины экрана.
 * На мобиле (<768px) — 'list', чтобы карточки занимали всю ширину;
 * на md+ — 'grid'.
 */
export function getResponsiveDefaultView() {
    if (typeof window === 'undefined' || !window.matchMedia) return 'grid';
    return window.matchMedia('(max-width: 767px)').matches ? 'list' : 'grid';
}



/** Параметры-массивы (используют alias[] в URL) */
const ARRAY_PARAMS = new Set([
    'attribute_value_ids',
    'brand_ids',
    'category_ids',
    'collection_ids',
]);

/** Дефолтные значения — не попадают в URL */
export const DEFAULTS = {
    sort: 'newest',
    view: 'grid',
    per_page: 20,
    page: 1,
};

/** Параметры, которые передаются «как есть» (не через alias) */
const PASSTHROUGH_PARAMS = [
    'q',
    'category_id',
    'include_descendants',
    'price_min',
    'price_max',
    'in_stock',
    'in_stock_mode',
    'in_sale',
    'in_favourites',
    'is_new',
    'is_bestseller',
    'attribute_any',
];

/**
 * Построить compact query string из фильтров и вида.
 *
 * @param {object} filters — объект фильтров
 * @param {string} [view] — текущий вид (grid/list)
 * @returns {string} — query string без ведущего `?`
 */
export function buildCompactQuery(filters, view) {
    const params = new URLSearchParams();

    // Массивные параметры → alias[]
    for (const key of ARRAY_PARAMS) {
        const alias = ALIASES[key];
        const arr = filters[key];
        if (Array.isArray(arr) && arr.length > 0) {
            arr.forEach((val) => params.append(`${alias}[]`, val));
        }
    }

    // Inline attribute filters → fi[attr_id][]=value
    const inlineFilters = filters.attribute_inline_filters;
    if (inlineFilters && typeof inlineFilters === 'object') {
        for (const [attrId, values] of Object.entries(inlineFilters)) {
            if (Array.isArray(values) && values.length > 0) {
                values.forEach((val) => params.append(`fi[${attrId}][]`, val));
            }
        }
    }

    // Passthrough-параметры (без alias)
    for (const key of PASSTHROUGH_PARAMS) {
        const val = filters[key];
        if (val != null && val !== '' && val !== false && val !== 0) {
            params.set(key, typeof val === 'boolean' ? '1' : String(val));
        }
    }

    // Контролы — только если отличаются от дефолта
    if (filters.sort && filters.sort !== DEFAULTS.sort) {
        params.set(ALIASES.sort, filters.sort);
    }

    if (view && view !== DEFAULTS.view) {
        params.set(ALIASES.view, view);
    }

    const perPage = filters.per_page;
    if (perPage && Number(perPage) !== DEFAULTS.per_page) {
        params.set(ALIASES.per_page, perPage);
    }

    const page = filters.page;
    if (page && Number(page) !== DEFAULTS.page) {
        params.set(ALIASES.page, page);
    }

    return params.toString();
}

/**
 * Распарсить compact query string в объект { filters, view }.
 *
 * @param {string} search — window.location.search
 * @returns {{ filters: object, view: string }}
 */
export function parseCompactQuery(search) {
    const params = new URLSearchParams(search);
    const filters = {};

    // Массивные параметры: alias[] → полное имя
    for (const [fullName, alias] of Object.entries(ALIASES)) {
        if (!ARRAY_PARAMS.has(fullName)) continue;

        const values = params.getAll(`${alias}[]`);
        if (values.length > 0) {
            filters[fullName] = values.map(Number).filter((n) => !isNaN(n));
        }
    }

    // Inline attribute filters: fi[attr_id][] → attribute_inline_filters
    const inlineFilters = {};
    for (const [key, val] of params.entries()) {
        const match = key.match(/^fi\[(\d+)\]\[\]$/);
        if (match) {
            const attrId = match[1];
            if (!inlineFilters[attrId]) inlineFilters[attrId] = [];
            inlineFilters[attrId].push(val);
        }
    }
    if (Object.keys(inlineFilters).length > 0) {
        filters.attribute_inline_filters = inlineFilters;
    }

    // Passthrough-параметры
    for (const key of PASSTHROUGH_PARAMS) {
        if (params.has(key)) {
            filters[key] = params.get(key);
        }
    }

    // Контролы с дефолтами
    filters.sort = params.get(ALIASES.sort) || DEFAULTS.sort;
    filters.per_page = params.has(ALIASES.per_page)
        ? Number(params.get(ALIASES.per_page))
        : DEFAULTS.per_page;
    filters.page = params.has(ALIASES.page)
        ? Number(params.get(ALIASES.page))
        : DEFAULTS.page;

    const view = params.get(ALIASES.view) || DEFAULTS.view;

    return { filters, view };
}

/**
 * Построить URLSearchParams для API-запроса (полные имена параметров).
 *
 * @param {object} filters — объект фильтров
 * @returns {URLSearchParams}
 */
export function buildApiParams(filters) {
    const params = new URLSearchParams();

    // Passthrough-параметры
    for (const key of PASSTHROUGH_PARAMS) {
        const val = filters[key];
        if (val != null && val !== '' && val !== false && val !== 0) {
            params.set(key, typeof val === 'boolean' ? '1' : String(val));
        }
    }

    // Контролы
    if (filters.sort && filters.sort !== 'newest') {
        params.set('sort', filters.sort);
    }
    if (filters.per_page && Number(filters.per_page) !== DEFAULTS.per_page) {
        params.set('per_page', filters.per_page);
    }
    if (filters.page && Number(filters.page) > 1) {
        params.set('page', filters.page);
    }

    // Массивные параметры → полное имя[]
    for (const key of ARRAY_PARAMS) {
        const arr = filters[key];
        if (Array.isArray(arr) && arr.length > 0) {
            arr.forEach((val) => params.append(`${key}[]`, val));
        }
    }

    // Inline attribute filters → attribute_inline_filters[attr_id][]=value
    const inlineFilters = filters.attribute_inline_filters;
    if (inlineFilters && typeof inlineFilters === 'object') {
        for (const [attrId, values] of Object.entries(inlineFilters)) {
            if (Array.isArray(values) && values.length > 0) {
                values.forEach((val) => params.append(`attribute_inline_filters[${attrId}][]`, val));
            }
        }
    }

    return params;
}
