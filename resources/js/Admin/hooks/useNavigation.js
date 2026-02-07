import { usePage } from '@inertiajs/react';
import { menuConfig } from '../config/menuConfig';

/**
 * useNavigation — хук для определения активного пункта меню
 * Используется в Sidebar и MobileSidebar
 */
export const useNavigation = () => {
    const { url } = usePage();

    const isActive = (path) => {
        if (path === "/admin") {
            return url === "/admin" || url === "/admin/";
        }
        return url.startsWith(path);
    };

    // Определяем активные группы меню на основе текущего URL
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
