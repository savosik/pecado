import { useState, useEffect, useMemo, useCallback } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { Box, Flex, Button } from '@chakra-ui/react';
import { LuShoppingCart, LuScanBarcode, LuFileUp } from 'react-icons/lu';
import UserLayout from '../UserLayout';
import Breadcrumbs from '@/components/common/Breadcrumbs';
import EmptyState from '@/components/common/EmptyState';
import CartHeader from './CartHeader';
import CartToolbar from './CartToolbar';
import CartTable from './CartTable';
import CartDefectSection from './CartDefectSection';
import CartSummary from './CartSummary';
import CartFlash from './CartFlash';
import BarcodeScannerDialog from './BarcodeScannerDialog';
import ImportOrderDialog from './ImportOrderDialog';
import { useCartStore } from '@/stores/useCartStore';
import { toastSuccess, toastInfo, toastError } from '@/utils/toast';

/**
 * Страница корзины — Inertia-страница (GET /cart/{cart}).
 *
 * Props от CartController@show:
 *   - cart: { id, name, is_active, description }
 *   - cartDetails: { items, total_quantity, instock_quantity, preorder_quantity, total_amount_regular, total_amount_discounted }
 *   - userCarts: [{ id, name, is_active, items_count }]
 */
export default function CartIndex({ cart, cartDetails, userCarts }) {
    const { auth } = usePage().props;
    const allItems = cartDetails?.items ?? [];
    // Уценка живёт отдельной секцией: она привязана к партии, а основная таблица
    // построена на product_id-агрегации со spillover instock/preorder.
    const items = allItems.filter((it) => it.item_type !== 'defect');
    const defectItems = allItems.filter((it) => it.item_type === 'defect');
    const hasPreorderItems = (cartDetails?.preorder_quantity ?? 0) > 0;

    // ── Search (client-side filtering) ──
    const [searchQuery, setSearchQuery] = useState('');
    const normalizedQuery = (searchQuery || '').trim().toLowerCase();

    const filteredItems = useMemo(() => {
        if (!normalizedQuery) return items;
        return items.filter((it) => {
            const name = String(it.product?.name || '').toLowerCase();
            const brand = String(it.product?.brand?.name || '').toLowerCase();
            const sku = String(it.product?.sku || '').toLowerCase();
            const barcodes = (it.product?.barcodes || []).map((b) => String(b).toLowerCase());
            return (
                name.includes(normalizedQuery) ||
                brand.includes(normalizedQuery) ||
                sku.includes(normalizedQuery) ||
                barcodes.some((b) => b.includes(normalizedQuery))
            );
        });
    }, [items, normalizedQuery]);

    // ── Sorting ──
    const [sortKey, setSortKey] = useState('name');
    const [sortDir, setSortDir] = useState('asc');
    const handleSort = useCallback((key) => {
        setSortDir((prev) => (sortKey === key ? (prev === 'asc' ? 'desc' : 'asc') : 'asc'));
        setSortKey(key);
    }, [sortKey]);

    // ── Selection (bulk) — FIX #9: use filteredItems ──
    const [selected, setSelected] = useState(() => new Set());

    const toggleAll = useCallback(
        (checked) => {
            if (checked) {
                const allIds = new Set(
                    filteredItems.map((it) => Number(it.product?.id)).filter(Boolean),
                );
                setSelected(allIds);
            } else {
                setSelected(new Set());
            }
        },
        [filteredItems],
    );

    const toggleOne = useCallback((id, checked) => {
        setSelected((prev) => {
            const next = new Set(prev);
            if (checked) next.add(id);
            else next.delete(id);
            return next;
        });
    }, []);

    // ── Cart store integration ──
    useEffect(() => {
        if (!auth?.user) return;
        const store = useCartStore.getState();
        store.init(auth.user);
    }, [auth?.user]);

    // Reload cartDetails при кросс-таб добавлении товара, которого нет в items.
    // Цены и итоги обновляются оптимистично от стора, поэтому регулярный
    // reload-по-каждому-sync убран — это и был источник «ступенчатого лага».
    useEffect(() => {
        const onChanged = () => {
            const known = new Set((cartDetails?.items ?? []).map((it) => Number(it.product?.id)));
            const stored = Object.keys(useCartStore.getState().quantities).map(Number);
            const hasUnknown = stored.some((pid) => pid > 0 && !known.has(pid));
            if (hasUnknown) {
                router.reload({ only: ['cartDetails'], preserveScroll: true });
            }
        };
        window.addEventListener('cart:changed', onChanged);
        return () => window.removeEventListener('cart:changed', onChanged);
    }, [cartDetails?.items]);

    // ── Сканер для пустой корзины (внутри тулбара живёт собственный) ──
    const [emptyScannerOpen, setEmptyScannerOpen] = useState(false);
    // ── Импорт заказа для пустой корзины ──
    const [emptyImportOpen, setEmptyImportOpen] = useState(false);

    const handleImportSuccess = useCallback(() => {
        useCartStore.getState()._serverSync();
        router.reload({ only: ['cartDetails'], preserveScroll: true });
    }, []);

    // ── Inline-flash для нечастых уведомлений (вместо мобильных тостов) ──
    const [flash, setFlash] = useState(null);
    const dismissFlash = useCallback(() => setFlash(null), []);
    const showFlash = useCallback((next) => {
        setFlash({ id: Date.now() + Math.random(), ...next });
    }, []);

    // ── Clamping fallback (редко, при рассинхроне между вкладками или с 1С) ──
    useEffect(() => {
        const onClamped = (e) => {
            const { requested, clamped, instock, preorder } = e.detail;
            const excess = requested - clamped;
            const parts = [];

            if (instock > 0) parts.push(`${instock} со склада`);
            if (preorder > 0) parts.push(`${preorder} по предзаказу`);
            if (excess > 0) parts.push(`${excess} недоступно`);

            showFlash({
                type: 'warning',
                title: 'Количество скорректировано',
                description: `Запрошено ${requested}. Установлено ${clamped}: ${parts.join(', ')}.`,
            });
            // Сервер срезал — подтянуть свежий cartDetails (цены, max_total).
            router.reload({ only: ['cartDetails'], preserveScroll: true });
        };

        window.addEventListener('cart:clamped', onClamped);
        return () => window.removeEventListener('cart:clamped', onClamped);
    }, [showFlash]);

    // ── Handlers ──
    const clampDesired = (n) =>
        Math.max(0, Math.min(999, Math.floor(Number(n) || 0)));

    const handleRemoveItem = useCallback((productId) => {
        const prevQty = useCartStore.getState().getQuantity(productId);
        if (prevQty <= 0) return;
        useCartStore.getState().setQuantity(productId, 0);
        showFlash({
            type: 'success',
            title: 'Товар удалён',
            description: `Можно вернуть ${prevQty} шт. в корзину.`,
            action: {
                label: 'Отменить',
                onClick: () => useCartStore.getState().setQuantity(productId, prevQty),
            },
        });
    }, [showFlash]);

    const handleSetProductQuantity = useCallback((productId, newQuantity) => {
        const next = clampDesired(newQuantity);
        // qty=0 — это удаление, показываем flash с undo
        if (next === 0) {
            handleRemoveItem(productId);
            return;
        }
        useCartStore.getState().setQuantity(productId, next);
    }, [handleRemoveItem]);

    // FIX #1: use store.clear() instead of N×setQuantity
    const handleClearCart = useCallback(() => {
        useCartStore.getState().clear();
        toastSuccess('Корзина очищена');
    }, []);

    // Bulk через единый POST /api/cart/set-products-quantity (один запрос
    // вместо N параллельных — иначе InnoDB-дедлоки на cart_items одной
    // корзины, см. bug "Ошибка сервера при массовых действиях").
    const handleBulkSetQty = useCallback(
        async (qty) => {
            const n = clampDesired(qty);
            const ids = Array.from(selected);
            if (ids.length === 0) return;

            const map = {};
            ids.forEach((pid) => { map[pid] = n; });

            try {
                const { clamped } = await useCartStore.getState().setQuantitiesBulk(map);
                if (clamped.length > 0) {
                    toastInfo(
                        'Количество скорректировано',
                        `Запрошено ${n} для ${ids.length} товаров. Для ${clamped.length} количество ограничено остатками.`,
                    );
                } else {
                    toastInfo('Количество обновлено', `Установлено ${n} для ${ids.length} товаров.`);
                }
            } catch {
                // toastError уже показан внутри стора, состояние подтянуто с сервера
            }
        },
        [selected],
    );

    const handleBulkDelete = useCallback(async () => {
        const { getQuantity, setQuantitiesBulk } = useCartStore.getState();
        const snapshot = Array.from(selected)
            .map((pid) => [pid, getQuantity(pid)])
            .filter(([, qty]) => qty > 0);
        const count = snapshot.length;
        if (count === 0) return;

        const map = {};
        snapshot.forEach(([pid]) => { map[pid] = 0; });

        setSelected(new Set());

        try {
            await setQuantitiesBulk(map);
            showFlash({
                type: 'success',
                title: 'Товары удалены',
                description: `Удалено ${count} позиций из корзины.`,
                action: {
                    label: 'Отменить',
                    onClick: () => {
                        const restoreMap = Object.fromEntries(snapshot);
                        useCartStore.getState().setQuantitiesBulk(restoreMap);
                    },
                },
            });
        } catch {
            // toastError + serverSync уже отработали внутри стора
        }
    }, [selected, showFlash]);

    const handleBulkExport = useCallback(async () => {
        try {
            // FIX #6: pinned CDN version instead of unpredictable xlsx-latest
            const mod = await import('https://cdn.sheetjs.com/xlsx-0.20.3/package/xlsx.mjs');
            const XLSX = mod.default || mod;

            const header = ['Название', 'Бренд', 'Артикул', 'Заказано, шт', 'В наличии', 'Предзаказ', 'Цена', 'Сумма'];
            const aoa = [header];

            // Build per-product map
            const byProduct = new Map();
            for (const it of items) {
                const pid = Number(it.product?.id);
                if (!pid) continue;
                if (!byProduct.has(pid)) byProduct.set(pid, { product: it.product, instock: null, preorder: null });
                if (it.item_type === 'instock') byProduct.get(pid).instock = it;
                if (it.item_type === 'preorder') byProduct.get(pid).preorder = it;
            }

            for (const pid of selected) {
                const row = byProduct.get(pid);
                if (!row) continue;
                const name = row.product?.name || '';
                const brand = row.product?.brand?.name || '';
                const sku = row.product?.sku || '';
                const instock = Number(row.instock?.quantity || 0);
                const preorder = Number(row.preorder?.quantity || 0);
                const qty = instock + preorder;
                const item = row.instock || row.preorder;
                const price = Number(item?.price_discounted ?? item?.price ?? 0);
                const sum =
                    Number(row.instock?.total_amount_discounted ?? row.instock?.total_amount ?? 0) +
                    Number(row.preorder?.total_amount_discounted ?? row.preorder?.total_amount ?? 0);
                aoa.push([name, brand, sku, qty, instock, preorder, price, sum]);
            }

            const ws = XLSX.utils.aoa_to_sheet(aoa);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, 'Корзина');
            if (XLSX.writeFileXLSX) {
                XLSX.writeFileXLSX(wb, 'cart_selected.xlsx');
            } else {
                XLSX.writeFile(wb, 'cart_selected.xlsx');
            }
            toastInfo('Экспорт завершён', 'Файл cart_selected.xlsx скачан.');
        } catch {
            toastError('Ошибка экспорта', 'Не удалось экспортировать в Excel.');
        }
    }, [items, selected]);

    const handleBulkMove = useCallback(async (targetCartId) => {
        const productIds = Array.from(selected);
        try {
            const { data } = await axios.post('/api/cart/move-items', {
                target_cart_id: targetCartId,
                product_ids: productIds,
            });
            setSelected(new Set());
            toastSuccess('Товары перенесены', data.message);
            // Reload cart data
            useCartStore.getState()._serverSync();
            router.reload({ only: ['cartDetails', 'userCarts'], preserveScroll: true });
        } catch (err) {
            const msg = err.response?.data?.message || 'Не удалось перенести товары.';
            toastError('Ошибка', msg);
        }
    }, [selected]);

    // FIX #7: use Inertia router.reload + server sync instead of full page reload
    const handleRefresh = useCallback(() => {
        useCartStore.getState()._serverSync();
        router.reload({ only: ['cartDetails'], preserveScroll: true });
    }, []);

    // Breadcrumbs
    const breadcrumbs = [
        { label: 'Главная', url: '/' },
        { label: 'Корзина' },
    ];

    return (
        <UserLayout fluid>
            <Head title="Корзина" />

            {/* Текстовые/управляющие блоки — с боковыми отступами на мобиле */}
            <Box px={{ base: '3', md: '0' }}>
                <Breadcrumbs items={breadcrumbs} />
                <Box spaceY="3">
                    <CartHeader cart={cart} userCarts={userCarts} />
                    <CartFlash flash={flash} onDismiss={dismissFlash} />
                </Box>
            </Box>

            {allItems.length > 0 ? (
                <Box spaceY="3" mt="3">
                    {/* Единая карточка: тулбар + таблица. На base — full-bleed без рамки и скруглений */}
                    {items.length > 0 && (
                    <Box
                        borderWidth={{ base: '0', lg: '1px' }}
                        borderColor="border"
                        rounded={{ base: 'none', lg: 'lg' }}
                        bg={{ base: 'transparent', lg: 'bg' }}
                        overflow={{ base: 'visible', lg: 'hidden' }}
                    >
                        <CartToolbar
                            searchQuery={searchQuery}
                            onSearchChange={setSearchQuery}
                            bulkSelectedCount={selected.size}
                            onBulkSetQty={handleBulkSetQty}
                            onBulkDelete={handleBulkDelete}
                            onBulkExport={handleBulkExport}
                            onBulkMove={handleBulkMove}
                            onClearCart={handleClearCart}
                            onRefresh={handleRefresh}
                            userCarts={userCarts}
                            currentCartId={cart?.id}
                        />

                        <CartTable
                            items={filteredItems}
                            selected={selected}
                            onToggleAll={toggleAll}
                            onToggleOne={toggleOne}
                            sortKey={sortKey}
                            sortDir={sortDir}
                            onSort={handleSort}
                            onSetProductQuantity={handleSetProductQuantity}
                            onRemove={handleRemoveItem}
                            hasPreorderItems={hasPreorderItems}
                        />
                    </Box>
                    )}

                    {defectItems.length > 0 && (
                        <CartDefectSection items={defectItems} onChanged={handleRefresh} />
                    )}

                    <CartSummary cartDetails={cartDetails} hasItems={allItems.length > 0} />
                </Box>
            ) : (
                <Box px={{ base: '3', md: '0' }}>
                    <EmptyState
                        icon={LuShoppingCart}
                        title="Корзина пуста"
                        description="Добавьте товары в корзину, чтобы оформить заказ"
                        action={{ label: 'Перейти в каталог', href: '/products' }}
                        secondaryAction={{
                            label: 'Сканировать штрихкод',
                            icon: LuScanBarcode,
                            onClick: () => setEmptyScannerOpen(true),
                        }}
                    />
                    <Flex justify="center" mt="-4" mb="8">
                        <Button
                            variant="outline"
                            size="md"
                            onClick={() => setEmptyImportOpen(true)}
                        >
                            <LuFileUp size={16} />
                            Импорт заказа
                        </Button>
                    </Flex>
                    <BarcodeScannerDialog
                        open={emptyScannerOpen}
                        onClose={() => setEmptyScannerOpen(false)}
                        onSuccess={() => {
                            router.reload({ only: ['cartDetails'], preserveScroll: true });
                        }}
                    />
                    <ImportOrderDialog
                        open={emptyImportOpen}
                        onClose={() => setEmptyImportOpen(false)}
                        onSuccess={handleImportSuccess}
                    />
                </Box>
            )}
        </UserLayout>
    );
}
