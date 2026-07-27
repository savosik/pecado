/**
 * Преобразование правила акции между форматом хранения (только id)
 * и форматом формы (объекты {id, name} — этого требуют селекторы админки).
 */

const entity = (id, names) => ({ id, name: names?.[id] || `#${id}` });

const entityList = (ids = [], names = {}) => (ids || []).map((id) => entity(id, names));

const idList = (items = []) => (items || []).map((item) => (typeof item === 'object' ? item.id : item));

export const emptySelector = () => ({
    products: [],
    categories: [],
    with_descendants: false,
    brands: [],
    tags: [],
    erp_promotions: [],
    whole_cart: false,
});

export const emptyCondition = () => ({
    selector: emptySelector(),
    aggregate: 'amount',
    operator: '>=',
    value: 0,
});

export const emptyReward = () => ({
    type: 'fixed',
    product: null,
    choices: [],
    quantity: 1,
    price: 0,
    promo_kind: 'accountable',
    warehouse_id: null,
    multiply: 'once',
    per_value: null,
    max_multiplier: 1,
    optional: false,
});

/**
 * Данные с бэкенда → состояние формы.
 */
export function toFormState(rule) {
    const productNames = rule.product_names || {};
    const entityNames = rule.entity_names || {};

    return {
        name: rule.name || '',
        promotion_id: rule.promotion_id ?? null,
        mode: rule.mode || 'info',
        is_active: Boolean(rule.is_active),
        starts_at: rule.starts_at || '',
        ends_at: rule.ends_at || '',
        priority: rule.priority ?? 0,
        stackable: rule.stackable ?? true,
        conditions: {
            mode: rule.conditions?.mode || 'all',
            items: (rule.conditions?.items || []).map((item) => ({
                selector: {
                    ...emptySelector(),
                    ...item.selector,
                    products: entityList(item.selector?.products, productNames),
                    categories: entityList(item.selector?.categories, entityNames.categories),
                    brands: entityList(item.selector?.brands, entityNames.brands),
                    tags: item.selector?.tags || [],
                    erp_promotions: item.selector?.erp_promotions || [],
                    with_descendants: Boolean(item.selector?.with_descendants),
                    whole_cart: Boolean(item.selector?.whole_cart),
                },
                aggregate: item.aggregate || 'quantity',
                operator: item.operator || '>=',
                value: item.value ?? 0,
            })),
        },
        rewards: (rule.rewards || []).map((reward) => ({
            ...emptyReward(),
            ...reward,
            product: reward.product_id ? entity(reward.product_id, productNames) : null,
            choices: entityList(reward.choices, productNames),
            per_value: reward.per_value ?? null,
            max_multiplier: reward.max_multiplier ?? 1,
            optional: Boolean(reward.optional),
        })),
        audience: {
            region_ids: rule.audience?.region_ids || [],
            users: entityList(rule.audience?.user_ids, entityNames.users),
            managers: entityList(rule.audience?.manager_ids, entityNames.managers),
            channels: rule.audience?.channels || [],
        },
        limits: {
            per_client_total: rule.limits?.per_client_total ?? null,
            total: rule.limits?.total ?? null,
        },
    };
}

/**
 * Состояние формы → payload для контроллера (только идентификаторы).
 */
export function toPayload(data) {
    return {
        name: data.name,
        promotion_id: data.promotion_id || null,
        mode: data.mode,
        is_active: data.is_active,
        starts_at: data.starts_at || null,
        ends_at: data.ends_at || null,
        priority: Number(data.priority) || 0,
        stackable: data.stackable,
        conditions: {
            mode: data.conditions.mode,
            items: data.conditions.items.map((item) => ({
                selector: {
                    products: idList(item.selector.products),
                    categories: idList(item.selector.categories),
                    with_descendants: item.selector.with_descendants,
                    brands: idList(item.selector.brands),
                    tags: item.selector.tags,
                    erp_promotions: item.selector.erp_promotions,
                    whole_cart: item.selector.whole_cart,
                },
                aggregate: item.aggregate,
                operator: item.operator,
                value: Number(item.value) || 0,
            })),
        },
        rewards: data.rewards.map((reward) => ({
            type: reward.type,
            product_id: reward.type === 'fixed' ? reward.product?.id ?? null : null,
            choices: reward.type === 'choice' ? idList(reward.choices) : [],
            quantity: Number(reward.quantity) || 1,
            price: Number(reward.price) || 0,
            promo_kind: reward.promo_kind,
            warehouse_id: reward.warehouse_id || null,
            multiply: reward.multiply,
            per_value: reward.multiply === 'per_threshold' ? Number(reward.per_value) || 0 : null,
            max_multiplier: reward.multiply === 'per_threshold' ? Number(reward.max_multiplier) || 0 : 1,
            optional: reward.optional,
        })),
        audience: {
            region_ids: data.audience.region_ids,
            user_ids: idList(data.audience.users),
            manager_ids: idList(data.audience.managers),
            channels: data.audience.channels,
        },
        limits: {
            per_client_total: data.limits.per_client_total || null,
            total: data.limits.total || null,
        },
    };
}

/** Единица измерения порога. */
export const aggregateSuffix = (aggregate) => (aggregate === 'amount' ? '₽' : 'шт.');

export const formatAggregate = (value, aggregate) => {
    const number = new Intl.NumberFormat('ru-RU', {
        maximumFractionDigits: aggregate === 'amount' ? 2 : 0,
    }).format(value || 0);

    return `${number} ${aggregateSuffix(aggregate)}`;
};
