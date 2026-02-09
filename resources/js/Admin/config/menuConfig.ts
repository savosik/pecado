import {
    LuLayoutDashboard,
    LuPackage,
    LuFolderTree,
    LuBadge,
    LuBox,
    LuList,
    LuLayers,
    LuRuler,
    LuBarcode,
    LuShieldCheck,
    LuSlice,
    LuWarehouse,
    LuMapPin,
    LuShoppingCart,
    LuShoppingBag,
    LuUndo2,
    LuHeart,
    LuBookmark,
    LuTicket,
    LuPercent,
    LuLayoutGrid,
    LuUsers,
    LuBuilding2,
    LuCreditCard,
    LuMapPinned,
    LuBanknote,
    LuWallet,
    LuFileText,
    LuNewspaper,
    LuCircleHelp,
    LuImage,
    LuFile,
    LuCirclePlay,
    LuTags,
    LuImagePlay,
    LuSettings,
    LuDownload,
} from "react-icons/lu";

export interface MenuItem {
    label: string;
    icon: React.ElementType;
    path: string;
}

export interface MenuGroup {
    title: string;
    icon: React.ElementType;
    items: MenuItem[];
}

export const menuConfig: MenuGroup[] = [
    {
        title: "Главная",
        icon: LuLayoutDashboard,
        items: [
            { label: "Обзор", icon: LuLayoutDashboard, path: "/admin" },
        ],
    },
    {
        title: "Каталог",
        icon: LuPackage,
        items: [
            { label: "Товары", icon: LuPackage, path: "/admin/products" },
            { label: "Категории", icon: LuFolderTree, path: "/admin/categories" },
            { label: "Бренды", icon: LuBadge, path: "/admin/brands" },
            { label: "Модели", icon: LuBox, path: "/admin/product-models" },
            { label: "Атрибуты", icon: LuList, path: "/admin/attributes" },
            { label: "Группы атрибутов", icon: LuLayers, path: "/admin/attribute-groups" },
            { label: "Размерные сетки", icon: LuRuler, path: "/admin/size-charts" },
            { label: "Штрихкоды", icon: LuBarcode, path: "/admin/product-barcodes" },
            { label: "Сертификаты", icon: LuShieldCheck, path: "/admin/certificates" },
            { label: "Выгрузки", icon: LuDownload, path: "/admin/product-exports" },

        ],
    },
    {
        title: "Склады",
        icon: LuWarehouse,
        items: [
            { label: "Склады", icon: LuWarehouse, path: "/admin/warehouses" },
            { label: "Регионы", icon: LuMapPin, path: "/admin/regions" },
        ],
    },
    {
        title: "Продажи",
        icon: LuShoppingCart,
        items: [
            { label: "Заказы", icon: LuShoppingCart, path: "/admin/orders" },
            { label: "Корзины", icon: LuShoppingBag, path: "/admin/carts" },
            { label: "Возвраты", icon: LuUndo2, path: "/admin/returns" },
            { label: "Избранное", icon: LuHeart, path: "/admin/favorites" },
            { label: "Список желаний", icon: LuBookmark, path: "/admin/wishlist" },
        ],
    },
    {
        title: "Маркетинг",
        icon: LuTicket,
        items: [
            { label: "Акции", icon: LuTicket, path: "/admin/promotions" },
            { label: "Скидки", icon: LuPercent, path: "/admin/discounts" },
            { label: "Подборки", icon: LuLayoutGrid, path: "/admin/product-selections" },
        ],
    },
    {
        title: "Пользователи",
        icon: LuUsers,
        items: [
            { label: "Пользователи", icon: LuUsers, path: "/admin/users" },
            { label: "Компании", icon: LuBuilding2, path: "/admin/companies" },
            { label: "Банковские счета", icon: LuCreditCard, path: "/admin/company-bank-accounts" },
            { label: "Адреса доставки", icon: LuMapPinned, path: "/admin/delivery-addresses" },
        ],
    },
    {
        title: "Финансы",
        icon: LuBanknote,
        items: [
            { label: "Валюты", icon: LuBanknote, path: "/admin/currencies" },
            { label: "Балансы", icon: LuWallet, path: "/admin/user-balances" },
        ],
    },
    {
        title: "Контент",
        icon: LuFileText,
        items: [
            { label: "Статьи", icon: LuFileText, path: "/admin/articles" },
            { label: "Новости", icon: LuNewspaper, path: "/admin/news" },
            { label: "FAQ", icon: LuCircleHelp, path: "/admin/faqs" },
            { label: "Баннеры", icon: LuImage, path: "/admin/banners" },
            { label: "Страницы", icon: LuFile, path: "/admin/pages" },
            { label: "Истории", icon: LuCirclePlay, path: "/admin/stories" },
        ],
    },
    {
        title: "Теги",
        icon: LuTags,
        items: [
            { label: "Теги", icon: LuTags, path: "/admin/tags" },
        ],
    },
    {
        title: "Система",
        icon: LuSettings,
        items: [
            { label: "Медиа", icon: LuImagePlay, path: "/admin/media" },
            { label: "Настройки", icon: LuSettings, path: "/admin/settings" },
        ],
    },
];
