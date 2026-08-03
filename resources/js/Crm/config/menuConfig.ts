import {
    LuLayoutDashboard,
    LuUsers,
    LuUsersRound,
    LuChartLine,
    LuListChecks,
    LuMail,
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
            { label: "Задачи", icon: LuListChecks, path: "/crm/tasks", permission: "crm-tasks.view" },
            { label: "Письма", icon: LuMail, path: "/crm/emails", permission: "crm-emails.view" },
            { label: "Отчёты продаж", icon: LuChartLine, path: "/crm/analytics", permission: "crm-analytics.view" },
            { label: "Команда", icon: LuUsersRound, path: "/crm/team", permission: "crm-team.view" },
        ],
    },
];
