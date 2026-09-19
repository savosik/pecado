/**
 * Контекст страницы для помощника: где клиент сейчас на сайте.
 *
 * Считается из имени Inertia-компонента и его props — без запросов к серверу.
 * Уходит с сообщением клиента текстовым блоком («Страница: карточка товара …»)
 * и на сервер за репликой иконки. Только тип, id, заголовок и адрес.
 */

const RULES = [
    { match: /^User\/Products\/Show$/, type: 'product', id: (p) => p.product?.id, title: (p) => p.product?.name },
    { match: /^User\/Products\/Index$/, type: 'catalog', id: (p) => p.category?.id, title: (p) => p.category?.name },
    { match: /^User\/Search/, type: 'search', title: (p) => p.query || p.q },
    { match: /^User\/Cart/, type: 'cart' },
    { match: /^User\/Checkout/, type: 'checkout' },
    { match: /^User\/Orders\/Show$/, type: 'order', id: (p) => p.order?.id, title: (p) => p.order?.number },
    { match: /^User\/Cabinet\/Orders\/Show$/, type: 'order', id: (p) => p.order?.id, title: (p) => p.order?.number },
    { match: /^User\/Cabinet\/Orders/, type: 'orders' },
    { match: /^User\/Cabinet\/Reserves/, type: 'reserves' },
    { match: /^User\/Cabinet\/Shipments/, type: 'shipments', id: (p) => p.shipment?.id, title: (p) => p.shipment?.number },
    { match: /^User\/Cabinet\/Documents/, type: 'documents' },
    { match: /^User\/Cabinet\/Payments/, type: 'finance' },
    { match: /^User\/Cabinet\/PaymentOrders/, type: 'finance' },
    { match: /^User\/Cabinet\/Returns/, type: 'returns' },
    { match: /^User\/Cabinet/, type: 'cabinet' },
    { match: /^User\/Promotions/, type: 'promotions' },
    { match: /^User\/Faq/, type: 'faq' },
    { match: /^User\/Home$/, type: 'home' },
];

const clip = (value, max) => {
    if (value === undefined || value === null) return null;
    const s = String(value).trim();
    return s === '' ? null : s.slice(0, max);
};

/**
 * @param {string} component имя Inertia-компонента (usePage().component)
 * @param {object} props props страницы
 * @returns {{type: string, id: string|null, title: string|null, url: string|null}}
 */
export function pageContextFrom(component, props) {
    const rule = RULES.find((r) => r.match.test(component || ''));
    const url = typeof window !== 'undefined' ? window.location.pathname : null;

    if (!rule) {
        return { type: 'other', id: null, title: null, url: clip(url, 300) };
    }

    return {
        type: rule.type,
        id: clip(rule.id ? rule.id(props || {}) : null, 64),
        title: clip(rule.title ? rule.title(props || {}) : null, 160),
        url: clip(url, 300),
    };
}

/** Страницы служебных панелей — виджет там не живёт. */
export function isStaffPanel(component) {
    return /^(Admin|Crm|Wms)\//.test(component || '');
}
