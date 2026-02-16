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
