import {
    LuLayoutDashboard,
    LuUsers,
    LuUsersRound,
    LuBuilding2,
    LuChartLine,
    LuListChecks,
    LuMail,
    LuTarget,
    LuLightbulb,
    LuFileText,
    LuTruck,
    LuReceipt,
    LuKeyRound,
    LuSprout,
    LuWallet,
    LuGauge,
    LuTriangleAlert,
    LuScale,
    LuCalendarClock,
    LuCalendarOff,
    LuCalendarCheck,
    LuUserPlus,
    LuFileDown,
    LuPackageX,
    LuBellRing,
} from "react-icons/lu";

export interface MenuItem {
    label: string;
    icon: React.ElementType;
    path: string;
    permission?: string;
    /** Ключ фиче-флага в общих пропсах Inertia (`config`). Пункт скрыт, пока флаг выключен. */
    feature?: string;
    /** Ключ счётчика в `crmCounters` из общих пропсов — числовой бейдж у пункта. */
    counter?: string;
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
            { label: "Мои партнёры", icon: LuUsers, path: "/crm/partners", permission: "crm-clients.view" },
            { label: "Лиды", icon: LuUserPlus, path: "/crm/leads", permission: "crm-leads.view" },
            // Контрагенты — юрлица партнёров. Отдельный пункт, потому что переписка
            // о реквизитах и сверках идёт по юрлицу, а у партнёра их может быть несколько.
            { label: "Контрагенты", icon: LuBuilding2, path: "/crm/contractors", permission: "crm-contractors.view" },
            { label: "Задачи", icon: LuListChecks, path: "/crm/tasks", permission: "crm-tasks.view", counter: "tasks" },
            { label: "Письма", icon: LuMail, path: "/crm/emails", permission: "crm-emails.view" },
            { label: "Пульт уведомлений", icon: LuBellRing, path: "/crm/notifications/rules", permission: "crm-notifications.view" },
            { label: "Планы продаж", icon: LuTarget, path: "/crm/plans", permission: "crm-plans.view" },
            { label: "Возможности", icon: LuLightbulb, path: "/crm/opportunities", permission: "crm-opportunities.view" },
            { label: "Грядки", icon: LuSprout, path: "/crm/beds", permission: "crm-beds.view" },
            // Недоборы: журнал отменённых строк заказов. Счётчик — неразмеченные
            // отмены: строка есть, а причина («склад» или «клиент») не проставлена.
            { label: "Недоборы", icon: LuPackageX, path: "/crm/shortages", permission: "crm-shortages.view", counter: "shortages" },
            { label: "Отчёты продаж", icon: LuChartLine, path: "/crm/analytics", permission: "crm-analytics.view" },
            { label: "Команда", icon: LuUsersRound, path: "/crm/team", permission: "crm-team.view" },
            // Отсутствия видит весь отдел: кто кого замещает — рабочая информация.
            { label: "Отсутствия", icon: LuCalendarOff, path: "/crm/absences", permission: "crm-absences.view" },
            { label: "Табель", icon: LuCalendarCheck, path: "/crm/timesheet", permission: "crm-timesheet.view" },
            { label: "Токены ИИ-агентов", icon: LuKeyRound, path: "/crm/agent-tokens", permission: "crm-agent-tokens.view" },
        ],
    },
    {
        // Финансы: деньги, которые уже пришли или должны прийти. Отдельно от
        // «Документов», потому что это не журнал первички, а работа с долгом:
        // план поступлений, просрочка и балансы партнёров.
        title: "Финансы",
        icon: LuWallet,
        items: [
            { label: "Пульт платежей", icon: LuGauge, path: "/crm/finance", permission: "crm-finance.view" },
            { label: "План поступлений", icon: LuWallet, path: "/crm/finance/plan", permission: "crm-finance.view" },
            { label: "Просрочка", icon: LuTriangleAlert, path: "/crm/finance/overdue", permission: "crm-finance.view" },
            { label: "Балансы партнёров", icon: LuScale, path: "/crm/finance/balances", permission: "crm-finance.view" },
            { label: "Акт сверки", icon: LuFileText, path: "/crm/finance/reconciliation", permission: "crm-finance.view" },
            // Календарь живёт под правом документов: он появился раньше раздела
            // и остаётся доступен тем, у кого есть журналы, но нет финансов.
            { label: "Календарь платежей", icon: LuCalendarClock, path: "/crm/payments/calendar", permission: "crm-clients.view" },
        ],
    },
    {
        // Документы 1С: читаются, но не редактируются. Живут отдельной группой,
        // потому что это не работа с партнёром, а её результат.
        title: "Документы",
        icon: LuFileText,
        items: [
            { label: "Заказы", icon: LuFileText, path: "/crm/orders", permission: "crm-clients.view" },
            { label: "Реализации", icon: LuTruck, path: "/crm/shipments", permission: "crm-clients.view" },
            { label: "Платежи", icon: LuReceipt, path: "/crm/payments", permission: "crm-clients.view" },
            // Печатные формы из 1С (v16.1.0). Своего права нет — то же решение,
            // что и у журналов выше: «вижу партнёра, но не вижу его документы»
            // это состояние, которого быть не должно.
            { label: "Печатные формы", icon: LuFileDown, path: "/crm/printed-documents", permission: "crm-clients.view", feature: "documents_crm_enabled" },
        ],
    },
];
