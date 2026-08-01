// Тип склада — производная от флагов `is_defect` / `is_promo_sample`.
//
// Флаги взаимоисключающи (валидация в WarehouseController), но подпись считается
// в трёх местах админки, поэтому живёт здесь, а не тернарником в каждой странице.

export const WAREHOUSE_TYPE_LABELS = {
    defect: 'Склад некондиции',
    promo_sample: 'Склад рекламных образцов',
    regular: 'Обычный',
};

// Короткие подписи для бейджей в таблице
export const WAREHOUSE_TYPE_SHORT_LABELS = {
    defect: 'Некондиция',
    promo_sample: 'Реклама',
    regular: 'Обычный',
};

// Фиолетовый у некондиции уже был; рекламному даём синий — цвет промо-заказов
export const WAREHOUSE_TYPE_COLORS = {
    defect: 'purple',
    promo_sample: 'blue',
    regular: 'gray',
};

export const getWarehouseType = (warehouse) => {
    if (warehouse?.is_defect) return 'defect';
    if (warehouse?.is_promo_sample) return 'promo_sample';

    return 'regular';
};

export const getWarehouseTypeLabel = (warehouse) =>
    WAREHOUSE_TYPE_LABELS[getWarehouseType(warehouse)];

export const getWarehouseTypeShortLabel = (warehouse) =>
    WAREHOUSE_TYPE_SHORT_LABELS[getWarehouseType(warehouse)];

export const getWarehouseTypeColor = (warehouse) =>
    WAREHOUSE_TYPE_COLORS[getWarehouseType(warehouse)];
