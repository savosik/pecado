import { usePage } from '@inertiajs/react';
import { usePanel } from './PanelContext';

/**
 * useNavigation — определяет активный пункт меню текущей панели.
 */
export const useNavigation = () => {
    const { url } = usePage();
    const { basePath, menuConfig } = usePanel();

    const isActive = (path) => {
        // Корень панели — только точное совпадение: startsWith подсветил бы
        // первый пункт на каждой странице домена.
        if (path === basePath) {
            return url === basePath || url === `${basePath}/`;
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

        return activeGroups.length > 0 ? activeGroups : [menuConfig[0]?.title].filter(Boolean);
    };

    return { isActive, getActiveGroups, currentUrl: url };
};
