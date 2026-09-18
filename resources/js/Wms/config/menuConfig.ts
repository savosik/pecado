import {
    LuClipboardList,
    LuLayoutDashboard,
    LuPackageX,
    LuShieldCheck,
    LuTruck,
    LuPackageCheck,
    LuLink,
    LuScanBarcode,
    LuListOrdered,
    LuPackageSearch,
    LuSettings,
    LuTriangleAlert,
    LuBookOpen,
} from "react-icons/lu";

export interface MenuItem {
    label: string;
    icon: React.ElementType;
    path: string;
    permission?: string;
    /** Ключ из общего пропа `config`: пункт виден только при включённой функции. */
    feature?: string;
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
            { label: "Рабочий стол", icon: LuLayoutDashboard, path: "/wms", permission: "wms-dashboard.view" },
        ],
    },
    {
        title: "Некондиция",
        icon: LuPackageX,
        items: [
            { label: "Быстрый приём", icon: LuScanBarcode, path: "/wms/defects/quick", permission: "wms-defects.create" },
            { label: "Партии брака", icon: LuPackageX, path: "/wms/defects", permission: "wms-defects.view" },
            { label: "Не закрыто партиями", icon: LuTriangleAlert, path: "/wms/defects/uncovered", permission: "wms-defects.view" },
            { label: "К отгрузке", icon: LuTruck, path: "/wms/defects/shipping", permission: "wms-defects.view" },
            { label: "Коды дефектов", icon: LuListOrdered, path: "/wms/defects/codes", permission: "wms-defects.view" },
            { label: "Справочник дефектов", icon: LuClipboardList, path: "/wms/defect-types", permission: "wms-defect-types.view" },
        ],
    },
    {
        title: "Страховой запас",
        icon: LuShieldCheck,
        items: [
            { label: "Рисковые SKU", icon: LuShieldCheck, path: "/wms/stock-buffers", permission: "wms-stock-buffers.view" },
        ],
    },
    {
        title: "Отгрузка",
        icon: LuTruck,
        items: [
            { label: "Выдача заказов", icon: LuPackageCheck, path: "/wms/pickups", permission: "wms-pickups.view", feature: "pickup" },
            { label: "Расходные ордера", icon: LuClipboardList, path: "/wms/goods-issues", permission: "wms-goods-issues.view" },
        ],
    },
    {
        title: "Доставка",
        icon: LuTruck,
        items: [
            { label: "Реализации к доставке", icon: LuPackageSearch, path: "/wms/delivery-candidates", permission: "wms-deliveries.view" },
            { label: "Отправки", icon: LuTruck, path: "/wms/deliveries", permission: "wms-deliveries.view" },
            { label: "Настройки ApiShip", icon: LuSettings, path: "/wms/delivery-settings", permission: "wms-delivery-settings.view" },
        ],
    },
    {
        title: "Сотрудники",
        icon: LuLink,
        items: [
            { label: "Ссылки для кладовщиков", icon: LuLink, path: "/wms/access-links", permission: "wms-access.view" },
        ],
    },
    {
        // Инструкции для склада из админки: текст, PDF или видео. Без права.
        title: "Справка",
        icon: LuBookOpen,
        items: [
            { label: "Инструкции", icon: LuBookOpen, path: "/wms/instructions" },
        ],
    },
    // Разделы приёмки, отбора и инвентаризации добавятся сюда позже.
];
