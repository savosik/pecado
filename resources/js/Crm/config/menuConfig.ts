import {
    LuLayoutDashboard,
    LuUsers,
    LuUsersRound,
    LuBuilding2,
    LuContact,
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
    LuFilePen,
    LuPackageX,
    LuBellRing,
    LuHistory,
    LuMegaphone,
    LuBan,
    LuBanknote,
    LuSlidersHorizontal,
    LuCoins,
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
        // Картотека: с кем работаем. Справочники людей и юрлиц, а не ежедневная
        // очередь дел — та живёт в «Продажах».
        title: "Клиенты",
        icon: LuUsers,
        items: [
            { label: "Мои партнёры", icon: LuUsers, path: "/crm/partners", permission: "crm-clients.view" },
            { label: "Лиды", icon: LuUserPlus, path: "/crm/leads", permission: "crm-leads.view" },
            // Контрагенты — юрлица партнёров. Отдельный пункт, потому что переписка
            // о реквизитах и сверках идёт по юрлицу, а у партнёра их может быть несколько.
            { label: "Контрагенты", icon: LuBuilding2, path: "/crm/contractors", permission: "crm-contractors.view" },
            // Справочник людей: контактные лица партнёров и их юрлиц. Отдельным
            // пунктом, а не вкладкой в карточке, — человек бывает у нескольких
            // юрлиц сразу, и искать его надо в одном месте.
            { label: "Контакты", icon: LuContact, path: "/crm/contacts", permission: "crm-contacts.view" },
        ],
    },
    {
        // Ежедневная работа менеджера: что сделать сегодня и на чём заработать.
        title: "Продажи",
        icon: LuListChecks,
        items: [
            { label: "Задачи", icon: LuListChecks, path: "/crm/tasks", permission: "crm-tasks.view", counter: "tasks" },
            // Заказы из 1С: перенесены из «Документов» — менеджер смотрит их
            // каждый день рядом с задачами и планом, а не в архиве первички.
            { label: "Заказы", icon: LuFileText, path: "/crm/orders", permission: "crm-clients.view" },
            { label: "Планы продаж", icon: LuTarget, path: "/crm/plans", permission: "crm-plans.view" },
            // Зарплата рядом с планом: это ответ на вопрос «сколько я заработал
            // на этом плане прямо сейчас» — менеджер смотрит их вместе.
            { label: "Моя зарплата", icon: LuBanknote, path: "/crm/salary", permission: "crm-salary.view" },
            // Недоборы: журнал отменённых строк заказов. Счётчик — неразмеченные
            // отмены: строка есть, а причина («склад» или «клиент») не проставлена.
            { label: "Недоборы", icon: LuPackageX, path: "/crm/shortages", permission: "crm-shortages.view", counter: "shortages" },
        ],
    },
    {
        // Исходящая почта — один поток. И то, что менеджер написал руками,
        // и то, что система собрала по поводу, лежит в одном списке с одним
        // самолётиком; правила-фильтры живут вкладкой внутри, а не разделом.
        //
        // Разделение на «Письма» и «Уведомления» здесь было и оказалось главной
        // ошибкой прошлого подхода: объяснить разницу не удалось никому.
        title: "Почта",
        icon: LuMail,
        items: [
            { label: "Письма", icon: LuMail, path: "/crm/emails", permission: "crm-emails.view" },
            // Без права: свои настройки правит каждый, у кого есть доступ в CRM.
            { label: "Мои уведомления", icon: LuBellRing, path: "/crm/my-notifications" },
        ],
    },
    {
        // Осмысление продаж: факт по отгрузкам и инструменты поиска точек
        // роста — что докупить клиенту и как засеян план периода.
        title: "Аналитика",
        icon: LuChartLine,
        items: [
            { label: "Отчёты продаж", icon: LuChartLine, path: "/crm/analytics", permission: "crm-analytics.view" },
            { label: "Грядки", icon: LuSprout, path: "/crm/beds", permission: "crm-beds.view" },
            { label: "Возможности", icon: LuLightbulb, path: "/crm/opportunities", permission: "crm-opportunities.view" },
        ],
    },
    {
        // Финансы: деньги, которые уже пришли или должны прийти. Отдельно от
        // «Документов», потому что это не журнал первички, а работа с долгом:
        // план поступлений, просрочка, факт платежей и балансы партнёров.
        //
        // Порядок ведёт от факта к прогнозу: что пришло (платежи) → на что
        // начислено (реализации) → сколько всего должны (сверка, балансы) →
        // что просрочено → что ждём дальше (план, пульт, календарь).
        // Внутри страниц переключателей между пунктами нет: единственная
        // навигация по разделу — это меню слева.
        title: "Финансы",
        icon: LuWallet,
        items: [
            // Журнал и календарь платежей живут под правом документов: они появились
            // раньше раздела и остаются доступны тем, у кого есть журналы, но нет финансов.
            { label: "Платежи", icon: LuReceipt, path: "/crm/payments", permission: "crm-clients.view" },
            // Реализации живут здесь, а не в «Документах»: менеджер открывает их
            // ради денег — что отгружено, что оплачено и что попадёт в сверку.
            { label: "Реализации", icon: LuTruck, path: "/crm/shipments", permission: "crm-clients.view" },
            { label: "Акт сверки", icon: LuFileText, path: "/crm/finance/reconciliation", permission: "crm-finance.view" },
            // Балансы сразу под актом: оба отвечают на вопрос «сколько должен»,
            // только акт разворачивает ответ по движениям, а балансы — по юрлицам.
            { label: "Балансы", icon: LuScale, path: "/crm/finance/balances", permission: "crm-finance.view" },
            // Просрочка сразу за балансами: баланс отвечает «сколько должен»,
            // просрочка — «сколько из этого уже пора требовать». Разрывать пару
            // пультом и планом значило бы уводить от долга к прогнозу и обратно.
            { label: "Просрочка", icon: LuTriangleAlert, path: "/crm/finance/overdue", permission: "crm-finance.view" },
            // Дебиторка сразу за просрочкой: просрочка — «сколько пора требовать»,
            // дебиторка — «что система уже сделала с этим и кому звонить».
            { label: "Дебиторка", icon: LuBan, path: "/crm/debt", permission: "crm-finance.view" },
            // План сразу за просрочкой: это одна шкала времени — что уже
            // должны были заплатить и что заплатят дальше. Пульт со сводкой
            // уходит ниже: к нему обращаются реже, чем к обоим спискам.
            { label: "План поступлений", icon: LuWallet, path: "/crm/finance/plan", permission: "crm-finance.view" },
            // Календарь сразу за планом: прогноз отвечает «сколько будет»,
            // календарь — «какого числа обещано и когда пришло». Один смотрит
            // вперёд с поправкой на дисциплину, второй показывает документ
            // как есть, и читают их подряд.
            { label: "Календарь поступлений", icon: LuCalendarClock, path: "/crm/payments/calendar", permission: "crm-clients.view" },
            { label: "Пульт платежей", icon: LuGauge, path: "/crm/finance", permission: "crm-finance.view" },
        ],
    },
    {
        // Документы 1С: читаются, но не редактируются. Живут отдельной группой,
        // потому что это не работа с партнёром, а её результат.
        //
        // Платежи и реализации отсюда переехали в «Финансы»: и то и другое
        // менеджер ищет рядом с планом, просрочкой и сверкой, а не в общей
        // куче первички. Заказы ушли в «Продажи»: это ежедневная работа
        // менеджера, а не архив.
        title: "Документы",
        icon: LuFileText,
        items: [
            // Печатные формы из 1С (v16.1.0). Своего права нет — то же решение,
            // что и у журналов выше: «вижу партнёра, но не вижу его документы»
            // это состояние, которого быть не должно.
            { label: "Печатные формы", icon: LuFileDown, path: "/crm/printed-documents", permission: "crm-clients.view", feature: "documents_crm_enabled" },
            // Реестр договоров: своё право — раздел заведён взамен Google-таблицы,
            // и выдавать его нужно тем же, кто вёл таблицу.
            { label: "Договоры", icon: LuFilePen, path: "/crm/contracts", permission: "crm-contracts.view" },
        ],
    },
    {
        // Отдел как люди: состав, кто на месте и учёт времени. Видна в основном
        // РОП-у — у рядового менеджера остаются «Отсутствия».
        title: "Команда",
        icon: LuUsersRound,
        items: [
            { label: "Команда", icon: LuUsersRound, path: "/crm/team", permission: "crm-team.view" },
            // Отсутствия видит весь отдел: кто кого замещает — рабочая информация.
            { label: "Отсутствия", icon: LuCalendarOff, path: "/crm/absences", permission: "crm-absences.view" },
            { label: "Табель", icon: LuCalendarCheck, path: "/crm/timesheet", permission: "crm-timesheet.view" },
            // Зарплата отдела: сводка, утверждение и выплата — тем, кто видит чужие деньги.
            { label: "Зарплата отдела", icon: LuCoins, path: "/crm/salary/team", permission: "crm-clients-all.view" },
            // Константы зарплаты на менеджера × месяц и ручные строки дохода — только РОП.
            { label: "Настройки зарплаты", icon: LuSlidersHorizontal, path: "/crm/salary/settings", permission: "crm-salary.edit" },
        ],
    },
    {
        // Технические настройки, а не продажи. Группа скрывается целиком,
        // если у сотрудника нет права на её единственный пункт.
        title: "Сервис",
        icon: LuKeyRound,
        items: [
            { label: "Токены ИИ-агентов", icon: LuKeyRound, path: "/crm/agent-tokens", permission: "crm-agent-tokens.view" },
        ],
    },
];
