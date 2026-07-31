// Справочник типов заказа — соответствует App\Enums\OrderType.
//
// До этого тип разбирался тернарником `type === 'preorder' ? … : …` в восьми
// местах, поэтому заказы уценки показывались как «Со склада» / «Заказ».
// Новый тип (например, промо) добавляется здесь и подхватывается везде.

export const ORDER_TYPE_LABELS = {
    order: 'Заказ со склада',
    preorder: 'Предзаказ',
    defect: 'Уценка',
    promo: 'Промо-позиции',
    promo_sample: 'Рекламные образцы',
};

// Короткие подписи для бейджей в таблицах и карточках списка
export const ORDER_TYPE_SHORT_LABELS = {
    order: 'Со склада',
    preorder: 'Предзаказ',
    defect: 'Уценка',
    promo: 'Промо',
    promo_sample: 'Образцы',
};

// Оранжевый для предзаказа — та же палитра, что и в каталоге
export const ORDER_TYPE_COLORS = {
    order: 'teal',
    preorder: 'orange',
    defect: 'red',
    promo: 'blue',
    promo_sample: 'gray',
};

// Неизвестный тип не роняет страницу и не выдаёт себя за «Заказ со склада»:
// из 1С может приехать документ с типом, которого сайт ещё не знает
const UNKNOWN_LABEL = 'Заказ';
const UNKNOWN_COLOR = 'gray';

export const getOrderTypeLabel = (type) =>
    ORDER_TYPE_LABELS[type] ?? UNKNOWN_LABEL;

export const getOrderTypeShortLabel = (type) =>
    ORDER_TYPE_SHORT_LABELS[type] ?? UNKNOWN_LABEL;

export const getOrderTypeColor = (type) =>
    ORDER_TYPE_COLORS[type] ?? UNKNOWN_COLOR;
