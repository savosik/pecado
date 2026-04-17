import { useEffect, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { useFavoritesStore } from '@/stores/useFavoritesStore';

/**
 * useProductHelpers — общий хук для ProductCard и ProductListItem.
 *
 * Объединяет: подписку на стор избранного, toggleFavorite, formatPrice,
 * вычисление hasSale / isInStock / isPreorder / brandName.
 *
 * @param {Object|null} product
 * @returns {{
 *   user: Object|null,
 *   isFav: boolean,
 *   toggleFavorite: (e: Event) => void,
 *   formatPrice: (value: number|null) => string,
 *   hasSale: boolean,
 *   isInStock: boolean,
 *   isPreorder: boolean,
 *   brandName: string|null,
 *   price: number|null,
 *   salePrice: number|null,
 *   discountPct: number|null,
 * }}
 */
export function useProductHelpers(product) {
    const { auth, currency } = usePage().props;
    const authUser = auth?.user || null;
    const user = authUser?.status === 'active' ? authUser : null;
    const currencySymbol = currency?.symbol || '₽';

    const [isFav, setIsFav] = useState(false);

    // Подписка на стор избранного
    useEffect(() => {
        if (!user || !product?.id) return;

        const store = useFavoritesStore.getState();
        store.loadOnce(user);
        setIsFav(store.isFavorite(product.id));

        const unsub = useFavoritesStore.subscribe((state) => {
            setIsFav(state.ids.has(Number(product.id)));
        });
        return unsub;
    }, [product?.id, user]);

    const toggleFavorite = (e) => {
        e?.preventDefault?.();
        e?.stopPropagation?.();
        useFavoritesStore.getState().toggle(product.id);
    };

    const formatPrice = (value) => {
        if (value === null || value === undefined) return '';
        return Number(value).toLocaleString('ru-RU') + ' ' + currencySymbol;
    };

    const price = product?.base_price ?? null;
    const salePrice = product?.sale_price || null;
    const discountPct = product?.discount_percentage || null;
    const hasSale = salePrice != null && salePrice < price;
    const stockQty = product?.stock_quantity || 0;
    const preorderQty = product?.preorder_quantity || 0;
    const isInStock = stockQty > 0;
    const isPreorder = !isInStock && preorderQty > 0;
    const brandName = product?.brand_name || null;

    return {
        user,
        isFav,
        toggleFavorite,
        formatPrice,
        hasSale,
        isInStock,
        isPreorder,
        brandName,
        price,
        salePrice,
        discountPct,
    };
}
