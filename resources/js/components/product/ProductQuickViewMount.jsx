import { useEffect } from 'react';
import { useProductQuickView } from '@/contexts/ProductQuickViewContext';
import ProductQuickViewModal from './ProductQuickViewModal';

/**
 * Монтирует модалку QuickView и пробрасывает функции на window.__
 * для глобального перехвата кликов из app.js.
 */
export default function ProductQuickViewMount() {
    const { openQuickView, prefetchQuickView } = useProductQuickView();

    useEffect(() => {
        window.__openProductQuickView = openQuickView;
        window.__prefetchProductQuickView = prefetchQuickView;
        return () => {
            if (window.__openProductQuickView === openQuickView) delete window.__openProductQuickView;
            if (window.__prefetchProductQuickView === prefetchQuickView) delete window.__prefetchProductQuickView;
        };
    }, [openQuickView, prefetchQuickView]);

    return <ProductQuickViewModal />;
}
