import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';

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
        } catch (e) {
            if (e.name !== 'AbortError') {
                closeQuickView();
            }
        }
    }, [loadData, closeQuickView]);

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
