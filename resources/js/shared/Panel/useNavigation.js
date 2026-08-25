import { usePage } from '@inertiajs/react';
import { usePanel } from './PanelContext';

/**
 * useNavigation — определяет активный пункт меню текущей панели.
 *
 * Подсвечивается ровно один пункт — самый точный из подходящих. Простой
 * `startsWith` зажигал сразу два: на `/crm/finance/plan` горели и «Пульт
 * платежей» (`/crm/finance`), и «План поступлений», а меню из-за этого
 * читалось как «я нахожусь в двух разделах сразу».
 */
export const useNavigation = () => {
    const { url } = usePage();
    const { basePath, menuConfig } = usePanel();

    // Хвост запроса к разделу не относится: `/crm/orders?page=2` — те же «Заказы».
    const path = url.split('?')[0].replace(/\/$/, '') || '/';

    const matches = (itemPath) => {
        const clean = itemPath.replace(/\/$/, '');

        // Корень панели — только точное совпадение: иначе он подошёл бы
        // к каждой странице домена и всегда был бы «самым коротким» кандидатом.
        if (clean === basePath) {
            return path === basePath;
        }

        return path === clean || path.startsWith(`${clean}/`);
    };

    // Самый длинный подошедший путь и есть текущий раздел: `/crm/payments/calendar`
    // выигрывает у `/crm/payments`, а карточка `/crm/payments/17` остаётся за журналом.
    const activePath = menuConfig
        .flatMap((group) => group.items.map((item) => item.path))
        .filter(matches)
        .sort((a, b) => b.length - a.length)[0] ?? null;

    const isActive = (itemPath) => itemPath === activePath;

    const getActiveGroups = () => {
        const activeGroups = menuConfig
            .filter((group) => group.items.some((item) => isActive(item.path)))
            .map((group) => group.title);

        return activeGroups.length > 0 ? activeGroups : [menuConfig[0]?.title].filter(Boolean);
    };

    return { isActive, getActiveGroups, currentUrl: url };
};
