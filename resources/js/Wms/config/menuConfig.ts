import {
    LuLayoutDashboard,
    LuPackageX,
    LuTruck,
    LuScanBarcode,
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
            { label: "К отгрузке", icon: LuTruck, path: "/wms/defects/shipping", permission: "wms-defects.view" },
        ],
    },
    // Разделы приёмки, отбора и инвентаризации добавятся сюда позже.
];
