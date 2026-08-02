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
    LuWarehouse,
    LuMapPin,
    LuShoppingCart,
    LuShoppingBag,
    LuUndo2,
    LuTruck,
    LuHeart,
    LuBookmark,
    LuTicket,
    LuPercent,
    LuPackageX,
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
    LuMessageSquare,
    LuImage,
    LuFile,
    LuCirclePlay,
    LuTags,
    LuImagePlay,
    LuSettings,
    LuSettings2,
    LuDownload,
    LuRadio,
    LuSend,
    LuAward,
    LuMenu,
    LuUserRound,
    LuLandmark,
} from "react-icons/lu";

export interface MenuItem {
    label: string;
    icon: React.ElementType;
    path: string;
    permission?: string;
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
            { label: "Товары", icon: LuPackage, path: "/admin/products", permission: "products.view" },
            { label: "Категории", icon: LuFolderTree, path: "/admin/categories", permission: "categories.view" },
            { label: "Бренды", icon: LuBadge, path: "/admin/brands", permission: "brands.view" },
            { label: "Модели", icon: LuBox, path: "/admin/product-models", permission: "product-models.view" },
            { label: "Атрибуты", icon: LuList, path: "/admin/attributes", permission: "attributes.view" },
            { label: "Группы атрибутов", icon: LuLayers, path: "/admin/attribute-groups", permission: "attribute-groups.view" },
            { label: "Размерные сетки", icon: LuRuler, path: "/admin/size-charts", permission: "size-charts.view" },
            { label: "Штрихкоды", icon: LuBarcode, path: "/admin/product-barcodes", permission: "product-barcodes.view" },
            { label: "Сертификаты", icon: LuShieldCheck, path: "/admin/certificates", permission: "certificates.view" },
            { label: "Выгрузки", icon: LuDownload, path: "/admin/product-exports", permission: "product-exports.view" },
        ],
    },
    {
        title: "Склады",
        icon: LuWarehouse,
        items: [
            { label: "Склады", icon: LuWarehouse, path: "/admin/warehouses", permission: "warehouses.view" },
            { label: "Регионы", icon: LuMapPin, path: "/admin/regions", permission: "regions.view" },
        ],
    },
    {
        title: "Продажи",
        icon: LuShoppingCart,
        items: [
            { label: "Заказы", icon: LuShoppingCart, path: "/admin/orders", permission: "orders.view" },
            { label: "Корзины", icon: LuShoppingBag, path: "/admin/carts", permission: "carts.view" },
            { label: "Возвраты", icon: LuUndo2, path: "/admin/returns", permission: "returns.view" },
            { label: "Реализации", icon: LuTruck, path: "/admin/shipments", permission: "shipments.view" },
            { label: "Предзаказы поставщику", icon: LuSend, path: "/admin/supplier-preorders", permission: "supplier-preorders.view" },
            { label: "Уценка", icon: LuPackageX, path: "/admin/defects", permission: "defects.view" },
            { label: "Справочник дефектов", icon: LuList, path: "/admin/defect-types", permission: "defect-types.view" },
            { label: "Избранное", icon: LuHeart, path: "/admin/favorites", permission: "favorites.view" },
            { label: "Список желаний", icon: LuBookmark, path: "/admin/wishlist", permission: "wishlist.view" },
        ],
    },
    {
        title: "Маркетинг",
        icon: LuTicket,
        items: [
            { label: "Акции", icon: LuTicket, path: "/admin/promotions", permission: "promotions.view" },
            { label: "Правила акций", icon: LuSettings2, path: "/admin/promotion-rules", permission: "promotion-rules.view" },
            { label: "Подборки", icon: LuLayoutGrid, path: "/admin/product-selections", permission: "product-selections.view" },
        ],
    },
    {
        title: "Пользователи",
        icon: LuUsers,
        items: [
            { label: "Пользователи", icon: LuUsers, path: "/admin/users", permission: "users.view" },
            { label: "Анкеты", icon: LuFileText, path: "/admin/user-questionnaires", permission: "user-questionnaires.view" },
            { label: "Компании", icon: LuBuilding2, path: "/admin/companies", permission: "companies.view" },
            { label: "Банковские счета", icon: LuCreditCard, path: "/admin/company-bank-accounts", permission: "company-bank-accounts.view" },
            { label: "Адреса доставки", icon: LuMapPinned, path: "/admin/delivery-addresses", permission: "delivery-addresses.view" },
            { label: "Статусы клиентов", icon: LuAward, path: "/admin/client-statuses", permission: "client-statuses.view" },
            { label: "Персональные менеджеры", icon: LuUserRound, path: "/admin/personal-managers", permission: "personal-managers.view" },
        ],
    },
    {
        title: "Финансы",
        icon: LuBanknote,
        items: [
            // Наши юрлица, от имени которых 1С проводит документы. Не путать
            // с «Компаниями» в разделе «Пользователи» — те контрагенты-клиенты.
            { label: "Организации", icon: LuLandmark, path: "/admin/organizations", permission: "organizations.view" },
            { label: "Валюты", icon: LuBanknote, path: "/admin/currencies", permission: "currencies.view" },
            { label: "Балансы контрагентов", icon: LuWallet, path: "/admin/contractor-balances", permission: "contractor-balances.view" },
            { label: "Инд. цены", icon: LuPercent, path: "/admin/individual-prices", permission: "individual-prices.view" },
        ],
    },
    {
        title: "Контент",
        icon: LuFileText,
        items: [
            { label: "Статьи", icon: LuFileText, path: "/admin/articles", permission: "articles.view" },
            { label: "О брендах", icon: LuBadge, path: "/admin/brand-stories", permission: "brand-stories.view" },
            { label: "Новости", icon: LuNewspaper, path: "/admin/news", permission: "news.view" },
            { label: "FAQ", icon: LuCircleHelp, path: "/admin/faqs", permission: "faqs.view" },
            { label: "Вопросы пользователей", icon: LuMessageSquare, path: "/admin/user-questions", permission: "user-questions.view" },
            { label: "Баннеры", icon: LuImage, path: "/admin/banners", permission: "banners.view" },
            { label: "Страницы", icon: LuFile, path: "/admin/pages", permission: "pages.view" },
            { label: "Истории", icon: LuCirclePlay, path: "/admin/stories", permission: "stories.view" },
            { label: "Меню", icon: LuMenu, path: "/admin/menu-items", permission: "menu-items.view" },
        ],
    },
    {
        title: "Теги",
        icon: LuTags,
        items: [
            { label: "Теги", icon: LuTags, path: "/admin/tags", permission: "tags.view" },
        ],
    },
    {
        title: "Система",
        icon: LuSettings,
        items: [
            { label: "Канбан", icon: LuLayoutDashboard, path: "/admin/kanban", permission: "settings.view" },
            { label: "Шина ERP", icon: LuRadio, path: "/admin/erp-bus", permission: "erp-bus.view" },
            { label: "Медиа", icon: LuImagePlay, path: "/admin/media", permission: "media.view" },
            { label: "Роли", icon: LuShieldCheck, path: "/admin/roles", permission: "roles.view" },
            { label: "Настройки", icon: LuSettings, path: "/admin/settings", permission: "settings.view" },
        ],
    },
];
