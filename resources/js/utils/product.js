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
 * Проверяет, является ли HTML-строка «пустой» визуально.
 * WYSIWYG может оставить <p><br></p>, <p></p>, &nbsp; и т.д.
 */
function isEmptyHtml(html) {
    if (!html) return true;
    // Убираем теги, &nbsp;, пробелы — если ничего не осталось, значит пусто
    const text = html.replace(/<[^>]*>/g, '').replace(/&nbsp;/g, '').trim();
    return text.length === 0;
}

/**
 * Возвращает описание товара с правильной цепочкой приоритетов:
 * rich_content (если есть блоки) → description_html → description_rendered (markdown→html) → short_description
 *
 * Учитывает:
 * - Editor.js при пустом контенте сохраняет { blocks: [] }
 * - WYSIWYG может оставить <p><br></p> как «пустой» HTML
 * - description в базе хранится как markdown; бэкенд отдаёт уже отрендеренный HTML в description_rendered
 */
export function getProductDescription(product) {
    // rich_content — только если есть реальные блоки
    const rc = product.rich_content;
    if (rc && typeof rc === 'object' && Array.isArray(rc.blocks) && rc.blocks.length > 0) {
        return rc;
    }

    if (!isEmptyHtml(product.description_html)) return product.description_html;
    if (!isEmptyHtml(product.description_rendered)) return product.description_rendered;
    if (product.short_description?.trim()) return product.short_description;

    return null;
}
