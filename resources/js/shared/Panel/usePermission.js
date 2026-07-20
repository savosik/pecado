import { usePage } from '@inertiajs/react';

/**
 * usePermission — хук для проверки прав на фронтенде.
 *
 * can('products.view') — проверяет конкретное право
 * hasRole('super-admin') — проверяет роль
 */
export const usePermission = () => {
    const { auth } = usePage().props;

    const can = (permission) => {
        if (!auth?.user) return false;
        if (auth.user.is_super_admin) return true;
        if (auth.user.permissions.includes('*')) return true;
        return auth.user.permissions.includes(permission);
    };

    const hasRole = (role) => {
        if (!auth?.user) return false;
        return auth.user.roles.includes(role);
    };

    return { can, hasRole };
};
