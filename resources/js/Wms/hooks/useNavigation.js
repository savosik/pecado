import { usePage } from '@inertiajs/react';
import { menuConfig } from '../config/menuConfig';

/**
 * useNavigation — хук для определения активного пункта меню кабинета склада.
 * Копия админского: там путь захардкожен на /admin.
 */
export const useNavigation = () => {
    const { url } = usePage();

    const isActive = (path) => {
        // Корень домена — только точное совпадение: startsWith подсветил бы
        // «Рабочий стол» на каждой странице склада.
        if (path === "/wms") {
            return url === "/wms" || url === "/wms/";
        }
        return url.startsWith(path);
    };

    const getActiveGroups = () => {
        const activeGroups = [];
        for (const group of menuConfig) {
            for (const item of group.items) {
                if (isActive(item.path)) {
                    activeGroups.push(group.title);
                    break;
                }
            }
        }
        return activeGroups.length > 0 ? activeGroups : ["Главная"];
    };

    return { isActive, getActiveGroups, currentUrl: url };
};
