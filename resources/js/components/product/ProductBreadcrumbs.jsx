import { useEffect, useMemo, useState } from 'react';
import Breadcrumbs from '@/components/common/Breadcrumbs';

/**
 * ProductBreadcrumbs — хлебные крошки: Каталог → Категория → ... → Товар.
 * С lazy-загрузкой siblings для каждой категории.
 *
 * @param {{ categoryTrail: Array<{id, name, slug, parent_id}>, productName: string }} props
 */
export default function ProductBreadcrumbs({ categoryTrail = [], productName = '' }) {
    const buildItems = () => {
        const items = [
            { label: 'Каталог', url: '/products' },
        ];

        for (let i = 0; i < categoryTrail.length; i++) {
            const current = categoryTrail[i];
            items.push({
                label: current.name,
                url: `/categories/${current.slug}`,
                siblings: [],
            });
        }

        // Последний элемент — имя товара (без ссылки), если не совпадает с последней категорией
        const lastCategoryName = categoryTrail.length > 0
            ? categoryTrail[categoryTrail.length - 1]?.name
            : '';
        const shouldAppendProduct = !!productName
            && productName.trim() !== ''
            && productName.trim() !== lastCategoryName?.trim();

        if (shouldAppendProduct) {
            items.push({ label: productName });
        }

        return items;
    };

    const initialItems = useMemo(() => buildItems(), [categoryTrail, productName]);
    const [items, setItems] = useState(initialItems);

    useEffect(() => {
        setItems(initialItems);
    }, [initialItems]);

    // Lazy-загрузка siblings для каждой категории в trail
    useEffect(() => {
        if (categoryTrail.length === 0) return;

        const parentIds = Array.from(
            new Set(
                categoryTrail
                    .map(c => c?.parent_id)
                    .filter(pid => pid !== null && pid !== undefined)
            )
        );

        const needRoots = categoryTrail.some(c => c?.parent_id == null);
        let aborted = false;

        (async () => {
            try {
                // Загружаем children для каждого parent_id
                const results = parentIds.length > 0
                    ? await Promise.all(
                        parentIds.map(async (pid) => {
                            const res = await fetch(`/api/categories/${pid}`);
                            const data = await res.json();
                            const children = (data && data.children) ? data.children : [];
                            return [pid, children];
                        })
                    )
                    : [];

                // Загружаем корневые категории для узлов с parent_id = null
                let rootCategories = [];
                if (needRoots) {
                    const resRoots = await fetch('/api/categories');
                    const dataRoots = await resRoots.json();
                    rootCategories = Array.isArray(dataRoots.categories) ? dataRoots.categories : [];
                }

                if (aborted) return;

                const byParent = {};
                for (const [pid, children] of results) {
                    byParent[pid] = children;
                }

                setItems(prev =>
                    prev.map(it => {
                        if (!('siblings' in it)) return it;

                        // Находим узел в trail по href
                        const node = categoryTrail.find(c => `/categories/${c.slug}` === it.url);
                        const parentId = node?.parent_id;

                        let children = [];
                        if (parentId == null) {
                            children = rootCategories;
                        } else {
                            children = byParent[parentId] || [];
                        }

                        const sibs = children
                            .filter(c => `/categories/${c.slug}` !== it.url)
                            .map(c => ({ label: c.name, href: `/categories/${c.slug}` }));

                        if (sibs.length === 0) return it;
                        return { ...it, siblings: sibs };
                    })
                );
            } catch {
                // Игнорируем ошибки — siblings просто не покажутся
            }
        })();

        return () => {
            aborted = true;
        };
    }, [categoryTrail]);

    // BreadcrumbList JSON-LD отдаётся сервером (seo.structured_data) — клиентский дубль отключаем.
    return <Breadcrumbs items={items} jsonLd={false} />;
}
