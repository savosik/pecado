import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { router } from '@inertiajs/react';

const ProductQuickViewContext = createContext({
    open: false,
    productSlug: null,
    data: null,
    loading: false,
    openQuickView: (_slug) => { },
    closeQuickView: () => { },
    prefetchQuickView: (_slug) => { },
});

const CACHE_TTL_MS = 60_000; // 60 секунд

export function ProductQuickViewProvider({ children }) {
    const [open, setOpen] = useState(false);
    const [productSlug, setProductSlug] = useState(null);
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(false);
    const abortRef = useRef(null);
    const prefetchAbortRef = useRef(null);
    const cacheRef = useRef(new Map());

    const closeQuickView = useCallback(() => {
        if (abortRef.current) {
            abortRef.current.abort();
            abortRef.current = null;
        }
        setOpen(false);
        setProductSlug(null);
        setData(null);
        setLoading(false);
        document.body.style.overflow = '';
    }, []);

    const loadData = useCallback(async (slug, signal) => {
        const cached = cacheRef.current.get(slug);
        if (cached && Date.now() - cached._ts < CACHE_TTL_MS) return cached.data;

        const res = await fetch(`/api/products/${encodeURIComponent(slug)}`, {
            headers: { 'Accept': 'application/json' },
            signal,
        });
        if (!res.ok) throw new Error('Failed to load product');
        const json = await res.json();
        cacheRef.current.set(slug, { data: json, _ts: Date.now() });
        return json;
    }, []);

    const prefetchQuickView = useCallback(async (slug) => {
        try {
            const cached = cacheRef.current.get(slug);
            if (!slug || (cached && Date.now() - cached._ts < CACHE_TTL_MS)) return;
            if (prefetchAbortRef.current) {
                prefetchAbortRef.current.abort();
            }
            const controller = new AbortController();
            prefetchAbortRef.current = controller;
            await loadData(slug, controller.signal);
            prefetchAbortRef.current = null;
        } catch (_) { /* ignore prefetch errors */ }
    }, [loadData]);

    // Cleanup prefetch on unmount
    useEffect(() => {
        return () => {
            if (prefetchAbortRef.current) {
                prefetchAbortRef.current.abort();
            }
        };
    }, []);

    // Закрывать QuickView при Inertia-навигации (клик по ссылке внутри модалки)
    useEffect(() => {
        return router.on('before', () => {
            if (open) closeQuickView();
        });
    }, [open, closeQuickView]);

    const ensureRichContent = useCallback(async (slug, json, signal) => {
        const blocks = json?.product?.rich_content?.blocks;
        if (Array.isArray(blocks) && blocks.length > 0) return;

        try {
            const res = await fetch(`/api/products/${encodeURIComponent(slug)}/rich-content`, {
                headers: { 'Accept': 'application/json' },
                signal,
            });
            if (res.status !== 200) return;

            const payload = await res.json();
            if (!Array.isArray(payload?.blocks) || payload.blocks.length === 0) return;

            const richContent = { blocks: payload.blocks };
            const updatedProduct = { ...json.product, rich_content: richContent };
            const updatedJson = { ...json, product: updatedProduct };

            cacheRef.current.set(slug, { data: updatedJson, _ts: Date.now() });
            setData((prev) => (prev?.product?.slug === slug ? updatedJson : prev));
        } catch (e) {
            if (e.name !== 'AbortError') {
                console.warn('[QuickView] Не удалось загрузить rich-content:', e);
            }
        }
    }, []);

    const openQuickView = useCallback(async (slug) => {
        try {
            if (!slug) return;
            if (abortRef.current) {
                abortRef.current.abort();
            }
            const controller = new AbortController();
            abortRef.current = controller;
            setProductSlug(slug);
            setOpen(true);
            setLoading(true);
            document.body.style.overflow = 'hidden';
            const json = await loadData(slug, controller.signal);
            setData(json);
            setLoading(false);

            // Лениво подтягиваем сгенерированный rich-content (если его ещё нет в БД).
            ensureRichContent(slug, json, controller.signal);
        } catch (e) {
            if (e.name !== 'AbortError') {
                console.error('[QuickView] Ошибка загрузки товара:', e);
                closeQuickView();
                // Fallback: переходим на страницу товара
                window.location.href = `/products/${encodeURIComponent(slug)}`;
            }
        }
    }, [loadData, closeQuickView, ensureRichContent]);

    const value = useMemo(() => ({
        open,
        productSlug,
        data,
        loading,
        openQuickView,
        closeQuickView,
        prefetchQuickView,
    }), [open, productSlug, data, loading, openQuickView, closeQuickView, prefetchQuickView]);

    return (
        <ProductQuickViewContext.Provider value={value}>
            {children}
        </ProductQuickViewContext.Provider>
    );
}

export function useProductQuickView() {
    return useContext(ProductQuickViewContext);
}
