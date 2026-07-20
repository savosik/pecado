import { createContext, useContext } from 'react';

/**
 * Конфигурация текущей панели (/admin, /crm, /wms).
 *
 * Через контекст, а не пропами: Sidebar → NavigationMenu → useNavigation —
 * цепочка в три-четыре уровня, прокидывать basePath руками через каждый
 * пришлось бы во всех панелях сразу.
 *
 * @typedef {Object} PanelConfig
 * @property {string} key            Ключ панели из PANELS (admin | crm | wms)
 * @property {string} basePath       Корень домена, например '/crm'
 * @property {Array}  menuConfig     Меню панели (MenuGroup[])
 * @property {string} homeLabel      Подпись корневой хлебной крошки
 * @property {string} logoAlt        alt логотипа
 * @property {string} [badge]        Бейдж рядом с логотипом
 * @property {string} [logoHeight]   Высота логотипа
 * @property {boolean} [actionBreadcrumbs] Добавлять крошку «Создание»/«Редактирование»
 * @property {string} [profileHref]  Ссылка на профиль в меню пользователя
 */
const PanelContext = createContext(null);

export const PanelProvider = PanelContext.Provider;

export const usePanel = () => {
    const panel = useContext(PanelContext);

    if (!panel) {
        throw new Error('usePanel() вызван вне <PanelLayout> — компонент панели должен рендериться внутри layout.');
    }

    return panel;
};
