/**
 * Собирает props для компонента ProductInfo из данных товара.
 * Используется в Show.jsx и ProductQuickViewModal.jsx.
 */
export function buildProductInfoProps(product, currencySymbol = '₽') {
    const price = product.sale_price ?? product.base_price;
    const originalPrice = product.sale_price != null && product.sale_price < product.base_price
        ? product.base_price
        : null;

    const stockQty = product.stock_quantity || 0;
    const isInStock = stockQty > 0;
    const isPreorder = !isInStock && (product.preorder_quantity || 0) > 0;

    return {
        productId: product.id,
        name: product.name,
        price,
        originalPrice,
        currencySymbol,
        sku: product.sku,
        code: product.code,
        barcodes: product.barcodes || [],
        brand: product.brand,
        category: product.category,
        isNew: product.is_new,
        isBestseller: product.is_bestseller,
        inStock: isInStock,
        isPreorder,
        tags: product.tags || [],
        discountPct: product.discount_percentage,
    };
}

/**
 * Возвращает описание товара с правильной цепочкой приоритетов:
 * rich_content (если есть блоки) → description_html → description → short_description
 *
 * Editor.js при пустом контенте сохраняет { blocks: [] }, что truthy в JS.
 * Поэтому нужна явная проверка наличия блоков.
 */
export function getProductDescription(product) {
    // rich_content — только если есть реальные блоки
    const rc = product.rich_content;
    if (rc && typeof rc === 'object' && Array.isArray(rc.blocks) && rc.blocks.length > 0) {
        return rc;
    }

    return product.description_html || product.description || product.short_description || null;
}
