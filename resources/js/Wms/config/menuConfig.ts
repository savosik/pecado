import {
    LuClipboardList,
    LuLayoutDashboard,
    LuPackageX,
    LuTruck,
    LuScanBarcode,
    LuListOrdered,
    LuTriangleAlert,
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
    // Разделы приёмки, отбора и инвентаризации добавятся сюда позже.
];
