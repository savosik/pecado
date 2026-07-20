import { LuShieldCheck, LuHeadset, LuWarehouse } from 'react-icons/lu';

/**
 * Реестр панелей приложения.
 *
 * `flag` — имя пропа в auth.user, который сообщает о доступе (его собирает
 * SharesPanelAuth на бэкенде). Header показывает ссылки на все панели, кроме
 * текущей, для которых флаг истинен — поэтому новый домен достаточно добавить
 * сюда, и кросс-навигация появится во всех панелях сразу.
 */
export const PANELS = [
    { key: 'admin', basePath: '/admin', label: 'Админка', icon: LuShieldCheck, flag: 'is_admin' },
    { key: 'crm', basePath: '/crm', label: 'CRM', icon: LuHeadset, flag: 'is_crm' },
    { key: 'wms', basePath: '/wms', label: 'Склад', icon: LuWarehouse, flag: 'is_wms' },
];
