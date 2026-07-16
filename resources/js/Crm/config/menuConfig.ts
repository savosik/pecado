import {
    LuLayoutDashboard,
    LuUsers,
    LuUsersRound,
    LuChartLine,
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
            { label: "Рабочий стол", icon: LuLayoutDashboard, path: "/crm", permission: "crm-dashboard.view" },
        ],
    },
    {
        title: "Продажи",
        icon: LuUsers,
        items: [
            { label: "Мои клиенты", icon: LuUsers, path: "/crm/clients", permission: "crm-clients.view" },
            { label: "Отчёты продаж", icon: LuChartLine, path: "/crm/analytics", permission: "crm-analytics.view" },
            { label: "Команда", icon: LuUsersRound, path: "/crm/team", permission: "crm-team.view" },
        ],
    },
];
