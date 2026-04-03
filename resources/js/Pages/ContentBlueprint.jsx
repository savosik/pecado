import { Head } from '@inertiajs/react';
import { Box, Text, HStack, VStack } from '@chakra-ui/react';
import UserLayout from './User/UserLayout';
import { Prose } from '@/components/ui/prose';

/**
 * Blueprint-страница контентной типографики.
 *
 * Цели:
 * 1. Визуальный справочник оформления текстового контента (статьи, новости, бренды и т.д.)
 * 2. Компиляция всех Tailwind prose-классов через скрытый блок
 */
export default function ContentBlueprint() {
    return (
        <UserLayout>
            <Head title="Blueprint типографики" />

            {/* ── Шапка ──────────────────────────────────────────── */}
            <Box mb="8">
                <Text as="h1" fontSize={{ base: '2xl', md: '3xl' }} fontWeight="bold" color="fg" letterSpacing="-0.02em">
                    Blueprint типографики контента
                </Text>
                <Text mt="2" fontSize="sm" color="fg.muted">
                    Эталонная страница для просмотра всех блоков оформления текста. Также
                    используется для компиляции Tailwind-классов.
                </Text>
            </Box>

            {/* ══════════════════════════════════════════════════════
                ОСНОВНОЙ КОНТЕНТ — через Prose (Chakra UI)
                ══════════════════════════════════════════════════════ */}
            <Box
                bg={{ base: 'white', _dark: 'gray.800' }}
                border="1px solid"
                borderColor={{ base: 'gray.200', _dark: 'gray.700' }}
                borderRadius="xl"
                overflow="hidden"
            >
                <Box p={{ base: '6', md: '10', lg: '14' }}>
                    <Prose size="lg" maxW="none" dangerouslySetInnerHTML={{ __html: BLUEPRINT_HTML }} />
                </Box>
            </Box>

            {/* ── Скрытый блок: компиляция Tailwind prose-классов ── */}
            <div className="hidden">
                <div className="prose prose-sm prose-base prose-lg prose-xl prose-2xl" />
                <div className="dark:prose-invert prose-stone prose-gray prose-zinc prose-neutral prose-slate" />
                <div className="sm:prose-base md:prose-lg lg:prose-xl" />
                <div className="
                    prose-headings:font-bold prose-headings:text-gray-900 prose-headings:tracking-tight
                    prose-p:text-gray-700 prose-p:leading-relaxed
                    prose-a:text-pink-600 prose-a:no-underline prose-a:underline prose-a:decoration-pink-600/30
                    hover:prose-a:decoration-pink-600
                    prose-blockquote:border-pink-500 prose-blockquote:not-italic
                    prose-strong:text-gray-900 prose-strong:font-bold
                    prose-em:text-gray-700
                    prose-code:text-pink-600 prose-code:bg-pink-50 prose-code:rounded
                    prose-code:before:content-none prose-code:after:content-none
                    prose-pre:bg-gray-900 prose-pre:text-gray-100 prose-pre:rounded-xl
                    prose-ol:list-decimal prose-ul:list-disc
                    prose-li:marker:text-pink-500 prose-li:marker:text-gray-400
                    prose-img:rounded-xl prose-img:shadow-lg prose-img:shadow-md
                    prose-figure:my-8
                    prose-figcaption:text-center prose-figcaption:italic prose-figcaption:text-gray-500
                    prose-table:overflow-hidden
                    prose-th:bg-gray-100 prose-th:text-left
                    prose-td:border-t
                    prose-hr:border-gray-300
                    prose-lead:text-xl prose-lead:text-gray-600
                    prose-video:rounded-xl prose-video:shadow-lg
                " />
                <div className="
                    dark:prose-headings:text-white
                    dark:prose-p:text-gray-300
                    dark:prose-a:text-pink-400
                    dark:prose-strong:text-white
                    dark:prose-em:text-gray-300
                    dark:prose-code:text-pink-400 dark:prose-code:bg-pink-950/30
                    dark:prose-pre:bg-gray-950
                    dark:prose-th:bg-gray-800
                    dark:prose-td:border-gray-700
                    dark:prose-hr:border-gray-600
                    dark:prose-blockquote:border-pink-400
                    dark:prose-figcaption:text-gray-400
                    dark:prose-lead:text-gray-400
                " />
            </div>
        </UserLayout>
    );
}


/* ═══════════════════════════════════════════════════════════════════
   HTML-контент — имитирует то, что приходит из CMS / WYSIWYG-редактора
   ═══════════════════════════════════════════════════════════════════ */
const BLUEPRINT_HTML = `

<!-- ── 1. ЗАГОЛОВКИ ──────────────────────────────────────── -->
<h1>Заголовок первого уровня (H1)</h1>
<p>Используется как главный заголовок страницы или статьи. Обычно один на всю страницу. Задаёт контекст и привлекает внимание читателя.</p>

<h2>Заголовок второго уровня (H2)</h2>
<p>Основные разделы внутри статьи. Каждый раздел логически разделяет контент и помогает читателю ориентироваться в длинном тексте.</p>

<h3>Заголовок третьего уровня (H3)</h3>
<p>Подразделы внутри секции. Помогает структурировать длинные тексты и упрощает навигацию по материалу.</p>

<h4>Заголовок четвёртого уровня (H4)</h4>
<p>Подпункт внутри подраздела. Используется реже, но важен для сложного контента с многоуровневой структурой.</p>

<h5>Заголовок пятого уровня (H5)</h5>
<p>Мелкий подзаголовок — пояснения, примечания, детали реализации.</p>

<h6>Заголовок шестого уровня (H6)</h6>
<p>Самый мелкий заголовок. Для деталей, уточнений и мета-информации.</p>

<hr>

<!-- ── 2. LEAD-ПАРАГРАФ ─────────────────────────────────── -->
<h2>Lead-параграф</h2>
<p style="font-size:1.25em;color:#6b7280;font-weight:300;line-height:1.7">
    Вводный абзац статьи, который привлекает внимание читателя и кратко описывает, о чём пойдёт речь. Обычно выделяется увеличенным размером шрифта, более светлым цветом и лёгким начертанием.
</p>
<p>Обычный параграф, который следует за вводным. Здесь начинается основное повествование. Текст должен легко читаться, с комфортной шириной строки и достаточным межстрочным интервалом. Качественная типографика — залог того, что читатель останется на странице.</p>
<p>Второй параграф продолжает повествование. Обратите внимание на расстояние между абзацами — оно создаёт визуальный ритм и не даёт тексту «слипаться» в единую массу.</p>

<hr>

<!-- ── 3. ИНЛАЙН-ВЫДЕЛЕНИЯ ──────────────────────────────── -->
<h2>Инлайн-выделения текста</h2>
<p>В тексте можно использовать <strong>жирное выделение</strong> для ключевых понятий, <em>курсив</em> для акцентов и смысловых оттенков, <mark style="background:#fef08a;padding:0 4px;border-radius:3px">маркер для подсветки</mark> важных фрагментов, а также <del>зачёркнутый текст</del> для устаревшей информации и <ins>вставленный текст</ins> для обновлений.</p>

<p>Химическая формула воды — H<sub>2</sub>O. Площадь комнаты — 15&nbsp;м<sup>2</sup>. <small>Мелкий текст для примечаний и оговорок.</small> Аббревиатура <abbr title="Hypertext Markup Language" style="text-decoration:underline dotted;cursor:help">HTML</abbr> расшифровывается как «Hypertext Markup Language».</p>

<p>Комбинация: <strong><em>жирный курсив</em></strong> для максимального акцента, а также <code>инлайн-код</code> для технических терминов и переменных.</p>

<p>Клавиша <kbd style="padding:2px 8px;font-size:0.875em;font-family:monospace;background:#f3f4f6;border:1px solid #d1d5db;border-radius:4px;box-shadow:0 1px 0 #d1d5db">Ctrl</kbd> + <kbd style="padding:2px 8px;font-size:0.875em;font-family:monospace;background:#f3f4f6;border:1px solid #d1d5db;border-radius:4px;box-shadow:0 1px 0 #d1d5db">S</kbd> — сохранить документ.</p>

<hr>

<!-- ── 4. ССЫЛКИ ─────────────────────────────────────────── -->
<h2>Ссылки</h2>
<p>Обычная <a href="#">внутренняя ссылка</a> в тексте выделяется цветом бренда и подчёркиванием. Внешняя <a href="https://example.com" target="_blank" rel="noopener noreferrer">ссылка на внешний ресурс&nbsp;↗</a> может сопровождаться иконкой для обозначения нового окна. Ещё бывают <a href="#">ссылки с длинным текстом, которые могут переноситься на несколько строк</a> — подчёркивание сохраняется на всех строках.</p>

<hr>

<!-- ── 5. ЦИТАТЫ ─────────────────────────────────────────── -->
<h2>Цитаты</h2>

<h3>Обычная цитата</h3>
<blockquote>
    <p>«Красота — это обещание счастья.» Хорошо оформленная цитата привлекает внимание и придаёт тексту авторитетность. Она визуально отделена от основного потока текста.</p>
</blockquote>

<h3>Цитата с указанием автора</h3>
<figure>
    <blockquote>
        <p>Простота — это высшая степень утончённости. Каждый продукт, который мы создаём, должен быть интуитивно понятным и при этом элегантным.</p>
    </blockquote>
    <figcaption>— Леонардо да Винчи</figcaption>
</figure>

<h3>Pull-цитата (акцентная)</h3>
<div style="border-left:4px solid #e94560;background:linear-gradient(135deg,#fdf2f8 0%,#fce7f3 100%);border-radius:0 12px 12px 0;padding:24px 32px;margin:2.5em 0">
    <p style="font-size:1.375em;font-weight:600;color:#1f2937;font-style:italic;line-height:1.4;margin:0">«Дизайн — это не то, как вещь выглядит, а то, как она работает.»</p>
    <p style="margin-top:12px;font-size:0.875em;color:#6b7280;margin-bottom:0">— Стив Джобс</p>
</div>

<hr>

<!-- ── 6. СПИСКИ ─────────────────────────────────────────── -->
<h2>Списки</h2>

<h3>Маркированный список</h3>
<ul>
    <li>Первый элемент списка</li>
    <li>Второй элемент с более длинным описанием, которое может занимать несколько строк текста для демонстрации переноса</li>
    <li>Третий элемент с вложенным списком
        <ul>
            <li>Вложенный элемент первого уровня</li>
            <li>Вложенный элемент второго уровня
                <ul>
                    <li>Ещё глубже — третий уровень вложенности</li>
                </ul>
            </li>
        </ul>
    </li>
    <li>Четвёртый элемент</li>
</ul>

<h3>Нумерованный список</h3>
<ol>
    <li>Зарегистрируйтесь в системе и подтвердите email</li>
    <li>Заполните профиль компании: реквизиты, контактные данные</li>
    <li>Выберите интересующие бренды из каталога</li>
    <li>Отправьте заявку на подтверждение
        <ol>
            <li>Заполните юридические реквизиты</li>
            <li>Прикрепите сканы документов</li>
            <li>Нажмите «Отправить на проверку»</li>
        </ol>
    </li>
    <li>Дождитесь подтверждения от менеджера (обычно 1–2 рабочих дня)</li>
</ol>

<h3>Список определений</h3>
<dl>
    <dt style="font-weight:600;color:#111827;margin-top:1em"><strong>SKU</strong></dt>
    <dd style="margin-left:0;color:#6b7280">Уникальный идентификатор товара в каталоге (Stock Keeping Unit). Формируется автоматически или задаётся поставщиком.</dd>

    <dt style="font-weight:600;color:#111827;margin-top:1em"><strong>РРЦ</strong></dt>
    <dd style="margin-left:0;color:#6b7280">Рекомендованная розничная цена — цена, установленная производителем для конечного потребителя.</dd>

    <dt style="font-weight:600;color:#111827;margin-top:1em"><strong>MOQ</strong></dt>
    <dd style="margin-left:0;color:#6b7280">Минимальный объём заказа (Minimum Order Quantity) — может отличаться в зависимости от бренда и категории.</dd>
</dl>

<h3>Чеклист</h3>
<ul style="list-style:none;padding-left:0">
    <li style="display:flex;align-items:flex-start;gap:8px;margin-top:6px"><span style="color:#22c55e;flex-shrink:0">✓</span> Создать аккаунт</li>
    <li style="display:flex;align-items:flex-start;gap:8px;margin-top:6px"><span style="color:#22c55e;flex-shrink:0">✓</span> Заполнить профиль</li>
    <li style="display:flex;align-items:flex-start;gap:8px;margin-top:6px"><span style="color:#9ca3af;flex-shrink:0">○</span> Добавить банковские реквизиты</li>
    <li style="display:flex;align-items:flex-start;gap:8px;margin-top:6px"><span style="color:#9ca3af;flex-shrink:0">○</span> Дождаться активации аккаунта</li>
</ul>

<hr>

<!-- ── 7. ИЗОБРАЖЕНИЯ ────────────────────────────────────── -->
<h2>Изображения</h2>

<h3>Полноширинное изображение</h3>
<img src="https://placehold.co/1200x500/1a1a2e/e94560?text=Полноширинное+изображение&font=inter" alt="Полноширинное изображение" style="width:100%;border-radius:12px">

<h3>Изображение с подписью</h3>
<figure>
    <img src="https://placehold.co/800x400/16213e/53bbf4?text=Фото+с+подписью&font=inter" alt="Пример фотографии с подписью">
    <figcaption>Рис. 1 — Пример изображения с подписью (figcaption). Подпись помогает объяснить контекст фотографии и является важным элементом типографики.</figcaption>
</figure>

<h3>Два изображения рядом</h3>
<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:16px;margin:2em 0">
    <figure style="margin:0">
        <img src="https://placehold.co/600x400/533483/e94560?text=Фото+1&font=inter" alt="Фото 1" style="width:100%;border-radius:12px">
        <figcaption style="text-align:center;font-size:0.85em;color:#6b7280;margin-top:8px;font-style:italic">Фото 1 — Левый элемент</figcaption>
    </figure>
    <figure style="margin:0">
        <img src="https://placehold.co/600x400/0f3460/e94560?text=Фото+2&font=inter" alt="Фото 2" style="width:100%;border-radius:12px">
        <figcaption style="text-align:center;font-size:0.85em;color:#6b7280;margin-top:8px;font-style:italic">Фото 2 — Правый элемент</figcaption>
    </figure>
</div>

<h3>Изображение с обтеканием текстом</h3>
<div style="overflow:hidden">
    <img src="https://placehold.co/300x200/1a1a2e/53bbf4?text=Float&font=inter" alt="Обтекание" style="float:left;margin-right:24px;margin-bottom:12px;border-radius:12px;max-width:40%">
    <p style="margin-top:0">Здесь текст обтекает изображение. Этот приём полезен, когда нужно показать небольшую иллюстрацию рядом с описанием. На мобильных устройствах рекомендуется переключать на полную ширину.</p>
    <p>Дополнительный параграф продолжает обтекание. Такой макет повсеместно используется в журнальной и редакторской вёрстке для более динамичного расположения визуальных элементов рядом с текстом.</p>
</div>

<hr>

<!-- ── 8. ТАБЛИЦЫ ────────────────────────────────────────── -->
<h2>Таблицы</h2>

<h3>Простая таблица</h3>
<table>
    <thead>
        <tr>
            <th>Бренд</th>
            <th>Категория</th>
            <th>Количество SKU</th>
            <th>Статус</th>
        </tr>
    </thead>
    <tbody>
        <tr><td>PECADO</td><td>Аксессуары</td><td>342</td><td>Активен</td></tr>
        <tr><td>Satisfyer</td><td>Электроника</td><td>156</td><td>Активен</td></tr>
        <tr><td>Womanizer</td><td>Электроника</td><td>89</td><td>Активен</td></tr>
        <tr><td>Lelo</td><td>Премиум</td><td>210</td><td>В обработке</td></tr>
    </tbody>
</table>

<h3>Стилизованная таблица</h3>
<div style="overflow-x:auto;margin:2em 0;border-radius:12px;border:1px solid #e5e7eb">
    <table style="width:100%;font-size:0.875em;border-collapse:collapse">
        <thead style="background:#f9fafb">
            <tr>
                <th style="padding:12px 16px;text-align:left;font-weight:600;border-bottom:2px solid #e5e7eb">Артикул</th>
                <th style="padding:12px 16px;text-align:left;font-weight:600;border-bottom:2px solid #e5e7eb">Наименование</th>
                <th style="padding:12px 16px;text-align:right;font-weight:600;border-bottom:2px solid #e5e7eb">РРЦ</th>
                <th style="padding:12px 16px;text-align:right;font-weight:600;border-bottom:2px solid #e5e7eb">Остаток</th>
                <th style="padding:12px 16px;text-align:center;font-weight:600;border-bottom:2px solid #e5e7eb">Статус</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td style="padding:12px 16px;font-family:monospace;color:#6b7280;border-bottom:1px solid #f3f4f6">PCD-001</td>
                <td style="padding:12px 16px;border-bottom:1px solid #f3f4f6">Набор для массажа «Элегант»</td>
                <td style="padding:12px 16px;text-align:right;border-bottom:1px solid #f3f4f6">4 590 ₽</td>
                <td style="padding:12px 16px;text-align:right;border-bottom:1px solid #f3f4f6">48</td>
                <td style="padding:12px 16px;text-align:center;border-bottom:1px solid #f3f4f6"><span style="display:inline-block;padding:2px 10px;border-radius:9999px;font-size:0.75em;font-weight:500;background:#dcfce7;color:#166534">В наличии</span></td>
            </tr>
            <tr>
                <td style="padding:12px 16px;font-family:monospace;color:#6b7280;border-bottom:1px solid #f3f4f6">PCD-042</td>
                <td style="padding:12px 16px;border-bottom:1px solid #f3f4f6">Комплект белья «Нуар»</td>
                <td style="padding:12px 16px;text-align:right;border-bottom:1px solid #f3f4f6">7 290 ₽</td>
                <td style="padding:12px 16px;text-align:right;border-bottom:1px solid #f3f4f6">12</td>
                <td style="padding:12px 16px;text-align:center;border-bottom:1px solid #f3f4f6"><span style="display:inline-block;padding:2px 10px;border-radius:9999px;font-size:0.75em;font-weight:500;background:#fef9c3;color:#854d0e">Мало</span></td>
            </tr>
            <tr>
                <td style="padding:12px 16px;font-family:monospace;color:#6b7280">PCD-099</td>
                <td style="padding:12px 16px">Масло для тела «Тропикана»</td>
                <td style="padding:12px 16px;text-align:right">1 890 ₽</td>
                <td style="padding:12px 16px;text-align:right">0</td>
                <td style="padding:12px 16px;text-align:center"><span style="display:inline-block;padding:2px 10px;border-radius:9999px;font-size:0.75em;font-weight:500;background:#fee2e2;color:#991b1b">Нет в наличии</span></td>
            </tr>
        </tbody>
    </table>
</div>

<hr>

<!-- ── 9. КОД ────────────────────────────────────────────── -->
<h2>Блоки кода</h2>

<h3>Инлайн-код</h3>
<p>Для получения списка товаров используйте метод <code>Product::query()</code>, а фильтрацию по бренду — через <code>->whereBrand($brandId)</code>. Результат оборачивается в <code>ProductResource::collection()</code>.</p>

<h3>Блок кода</h3>
<pre><code>// Пример запроса к каталогу
$products = Product::query()
    ->where('is_active', true)
    ->whereBetween('price', [1000, 5000])
    ->with(['brand', 'category', 'media'])
    ->orderBy('created_at', 'desc')
    ->paginate(24);

return Inertia::render('Catalog/Index', [
    'products' => ProductResource::collection($products),
]);</code></pre>

<h3>JSON-пример</h3>
<pre><code>{
  "id": 42,
  "sku": "PCD-001",
  "name": "Набор для массажа «Элегант»",
  "price": 4590.00,
  "currency": "RUB",
  "in_stock": true,
  "brand": {
    "id": 1,
    "name": "PECADO"
  }
}</code></pre>

<hr>

<!-- ── 10. CALLOUT-БЛОКИ ─────────────────────────────────── -->
<h2>Информационные блоки (Callouts)</h2>

<div style="display:flex;gap:16px;border-radius:12px;border:1px solid #bfdbfe;background:#eff6ff;padding:20px;margin:1.5em 0">
    <div style="flex-shrink:0;font-size:1.5em">ℹ️</div>
    <div>
        <p style="font-weight:600;color:#1e3a5f;margin:0">Информация</p>
        <p style="margin-top:6px;font-size:0.875em;color:#1e40af;margin-bottom:0">Вы можете изменить настройки уведомлений в разделе «Профиль» → «Настройки». Изменения вступят в силу немедленно.</p>
    </div>
</div>

<div style="display:flex;gap:16px;border-radius:12px;border:1px solid #bbf7d0;background:#f0fdf4;padding:20px;margin:1.5em 0">
    <div style="flex-shrink:0;font-size:1.5em">✅</div>
    <div>
        <p style="font-weight:600;color:#14532d;margin:0">Успешно</p>
        <p style="margin-top:6px;font-size:0.875em;color:#166534;margin-bottom:0">Ваш заказ №12847 успешно оформлен! Менеджер свяжется с вами в течение рабочего дня для подтверждения.</p>
    </div>
</div>

<div style="display:flex;gap:16px;border-radius:12px;border:1px solid #fde68a;background:#fefce8;padding:20px;margin:1.5em 0">
    <div style="flex-shrink:0;font-size:1.5em">⚠️</div>
    <div>
        <p style="font-weight:600;color:#713f12;margin:0">Внимание</p>
        <p style="margin-top:6px;font-size:0.875em;color:#854d0e;margin-bottom:0">Товары из вашей корзины будут зарезервированы только после подтверждения заказа менеджером. До этого момента наличие может измениться.</p>
    </div>
</div>

<div style="display:flex;gap:16px;border-radius:12px;border:1px solid #fecaca;background:#fef2f2;padding:20px;margin:1.5em 0">
    <div style="flex-shrink:0;font-size:1.5em">🚫</div>
    <div>
        <p style="font-weight:600;color:#7f1d1d;margin:0">Важно</p>
        <p style="margin-top:6px;font-size:0.875em;color:#991b1b;margin-bottom:0">Возврат товара возможен только в течение 14 дней с момента получения. Товар должен быть в оригинальной упаковке, без следов использования.</p>
    </div>
</div>

<hr>

<!-- ── 11. ДЕКОРАТИВНЫЕ РАЗДЕЛИТЕЛИ ──────────────────────── -->
<h2>Разделители</h2>
<p>Стандартный разделитель (hr) отображается выше и между каждой секцией.</p>

<h3>Декоративный разделитель</h3>
<div style="display:flex;align-items:center;gap:16px;margin:2em 0">
    <div style="flex:1;height:1px;background:linear-gradient(to right,transparent,#d1d5db,transparent)"></div>
    <span style="color:#9ca3af;font-size:0.875em">✦ ✦ ✦</span>
    <div style="flex:1;height:1px;background:linear-gradient(to right,transparent,#d1d5db,transparent)"></div>
</div>

<hr>

<!-- ── 12. DETAILS / SUMMARY ─────────────────────────────── -->
<h2>Раскрывающиеся блоки</h2>

<details style="border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;margin:1em 0">
    <summary style="cursor:pointer;padding:16px 20px;font-weight:600;background:#f9fafb">Как оформить заказ? (нажмите, чтобы раскрыть)</summary>
    <div style="padding:16px 20px;font-size:0.875em;color:#4b5563">
        <p style="margin-top:0">1. Добавьте нужные товары в корзину.</p>
        <p>2. Перейдите в корзину и проверьте состав заказа.</p>
        <p>3. Нажмите «Оформить заказ» и выберите способ доставки.</p>
        <p style="margin-bottom:0">4. Подтвердите заказ. Менеджер свяжется с вами для уточнения деталей.</p>
    </div>
</details>

<details style="border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;margin:1em 0">
    <summary style="cursor:pointer;padding:16px 20px;font-weight:600;background:#f9fafb">Какие условия возврата?</summary>
    <div style="padding:16px 20px;font-size:0.875em;color:#4b5563">
        <p style="margin-top:0">Возврат товара осуществляется в течение 14 дней с момента получения.</p>
        <p>Товар должен быть в оригинальной упаковке, без следов использования.</p>
        <p style="margin-bottom:0">Для оформления возврата свяжитесь с менеджером или создайте заявку в личном кабинете.</p>
    </div>
</details>

<hr>

<!-- ── 13. ВИДЕО-ПЛЕЙСХОЛДЕР ─────────────────────────────── -->
<h2>Встроенное видео</h2>
<div style="position:relative;padding-top:56.25%;background:#f3f4f6;border-radius:12px;overflow:hidden;margin:1.5em 0;box-shadow:0 4px 6px -1px rgba(0,0,0,0.1)">
    <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;flex-direction:column;color:#9ca3af">
        <div style="font-size:3em;margin-bottom:8px">▶</div>
        <p style="font-size:0.875em;margin:0">Здесь будет видео (responsive iframe, 16:9)</p>
    </div>
</div>
<p style="text-align:center;font-size:0.875em;color:#6b7280;font-style:italic">Видео: обзор коллекции «Весна-Лето 2026»</p>

<hr>

<!-- ── 14. КАРТОЧКИ И АКЦЕНТНЫЕ БЛОКИ ────────────────────── -->
<h2>Карточки и акцентные блоки</h2>

<h3>Карточка с градиентом</h3>
<div style="border-radius:16px;background:linear-gradient(135deg,#ec4899 0%,#8b5cf6 100%);padding:40px;color:white;margin:2em 0;box-shadow:0 20px 25px -5px rgba(0,0,0,0.15)">
    <h3 style="font-size:1.5em;font-weight:700;margin:0 0 12px 0;color:white">Специальное предложение</h3>
    <p style="color:#fce7f3;font-size:1.125em;line-height:1.6;margin:0">Скидка 15% на весь ассортимент бренда PECADO при заказе от 50 000 ₽. Предложение действует до конца месяца.</p>
    <div style="margin-top:24px">
        <span style="display:inline-block;padding:12px 24px;background:white;color:#ec4899;font-weight:600;border-radius:12px;box-shadow:0 4px 6px rgba(0,0,0,0.1);cursor:pointer">Подробнее →</span>
    </div>
</div>

<h3>Серая карточка-заметка</h3>
<div style="border-radius:12px;background:#f9fafb;border:1px solid #e5e7eb;padding:24px 28px;margin:2em 0">
    <h4 style="font-size:1.1em;font-weight:600;margin:0 0 8px 0">📝 На заметку</h4>
    <p style="font-size:0.875em;color:#4b5563;line-height:1.7;margin:0">Все цены на сайте указаны без учёта НДС. Итоговая стоимость с НДС рассчитывается при оформлении заказа в зависимости от вашей системы налогообложения.</p>
</div>

<h3>Статистика в карточках</h3>
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin:2em 0">
    <div style="text-align:center;border-radius:12px;background:white;border:1px solid #e5e7eb;padding:20px;box-shadow:0 1px 2px rgba(0,0,0,0.05)">
        <div style="font-size:1.75em;font-weight:700;color:#ec4899">1 200+</div>
        <div style="margin-top:4px;font-size:0.875em;color:#6b7280">Товаров</div>
    </div>
    <div style="text-align:center;border-radius:12px;background:white;border:1px solid #e5e7eb;padding:20px;box-shadow:0 1px 2px rgba(0,0,0,0.05)">
        <div style="font-size:1.75em;font-weight:700;color:#ec4899">45</div>
        <div style="margin-top:4px;font-size:0.875em;color:#6b7280">Брендов</div>
    </div>
    <div style="text-align:center;border-radius:12px;background:white;border:1px solid #e5e7eb;padding:20px;box-shadow:0 1px 2px rgba(0,0,0,0.05)">
        <div style="font-size:1.75em;font-weight:700;color:#ec4899">98%</div>
        <div style="margin-top:4px;font-size:0.875em;color:#6b7280">Довольных</div>
    </div>
    <div style="text-align:center;border-radius:12px;background:white;border:1px solid #e5e7eb;padding:20px;box-shadow:0 1px 2px rgba(0,0,0,0.05)">
        <div style="font-size:1.75em;font-weight:700;color:#ec4899">24ч</div>
        <div style="margin-top:4px;font-size:0.875em;color:#6b7280">Отгрузка</div>
    </div>
</div>

<hr>

<!-- ── 15. БЕЙДЖИ / МЕТКИ ────────────────────────────────── -->
<h2>Метки и бейджи</h2>
<div style="display:flex;flex-wrap:wrap;gap:8px;margin:1em 0">
    <span style="display:inline-flex;align-items:center;padding:4px 12px;border-radius:9999px;font-size:0.75em;font-weight:500;background:#f3f4f6;color:#374151">Обычный</span>
    <span style="display:inline-flex;align-items:center;padding:4px 12px;border-radius:9999px;font-size:0.75em;font-weight:500;background:#fce7f3;color:#9d174d">Акция</span>
    <span style="display:inline-flex;align-items:center;padding:4px 12px;border-radius:9999px;font-size:0.75em;font-weight:500;background:#dcfce7;color:#166534">Новинка</span>
    <span style="display:inline-flex;align-items:center;padding:4px 12px;border-radius:9999px;font-size:0.75em;font-weight:500;background:#dbeafe;color:#1e40af">Популярный</span>
    <span style="display:inline-flex;align-items:center;padding:4px 12px;border-radius:9999px;font-size:0.75em;font-weight:500;background:#fef9c3;color:#854d0e">Ожидается</span>
    <span style="display:inline-flex;align-items:center;padding:4px 12px;border-radius:9999px;font-size:0.75em;font-weight:500;background:#fee2e2;color:#991b1b">Снят с продажи</span>
    <span style="display:inline-flex;align-items:center;padding:4px 12px;border-radius:9999px;font-size:0.75em;font-weight:500;background:#f3e8ff;color:#6b21a8">Эксклюзив</span>
</div>

<hr>

<!-- ── 16. СНОСКИ ─────────────────────────────────────────── -->
<h2>Сноски</h2>
<p>Минимальная партия заказа зависит от бренда<sup style="color:#ec4899;cursor:pointer">[1]</sup>. Стоимость доставки рассчитывается индивидуально<sup style="color:#ec4899;cursor:pointer">[2]</sup>.</p>
<div style="margin-top:2em;padding-top:1.5em;border-top:1px solid #e5e7eb;font-size:0.875em;color:#6b7280">
    <p style="margin:4px 0"><span style="color:#ec4899;font-weight:500">[1]</span> Для брендов Satisfyer и Womanizer MOQ составляет 30 000 ₽.</p>
    <p style="margin:4px 0"><span style="color:#ec4899;font-weight:500">[2]</span> Бесплатная доставка при заказе от 100 000 ₽.</p>
</div>

<hr>

<!-- ── 17. МНОГОКОЛОНОЧНЫЙ ТЕКСТ ──────────────────────────── -->
<h2>Многоколоночный текст</h2>
<div style="columns:2;column-gap:2em;font-size:0.9em;line-height:1.7">
    <p style="margin-top:0">Многоколоночная вёрстка позволяет разместить большой объём текста более компактно. Она особенно полезна для длинных описаний брендов, условий сотрудничества или юридических текстов.</p>
    <p>На мобильных устройствах текст отображается в одну колонку, а на планшетах и десктопах — в две. Это обеспечивает комфортное чтение на любом устройстве.</p>
    <p>Рекомендуется использовать колонки для текстов, которые не содержат крупных визуальных элементов — изображений или таблиц. Иначе разрыв колонки может разбить элемент на части.</p>
</div>

<hr>

<!-- ── 18. СРАВНЕНИЕ «ДО / ПОСЛЕ» ────────────────────────── -->
<h2>Сравнение «до / после»</h2>
<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:24px;margin:2em 0">
    <div style="border-radius:12px;border:2px solid #fecaca;padding:24px;background:#fef2f2">
        <div style="font-size:0.75em;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#ef4444;margin-bottom:12px">❌ Было</div>
        <p style="color:#4b5563;font-size:0.875em;line-height:1.6;margin:0">Однообразный контент без структуры, сплошной поток текста, отсутствие визуальных акцентов и навигации.</p>
    </div>
    <div style="border-radius:12px;border:2px solid #bbf7d0;padding:24px;background:#f0fdf4">
        <div style="font-size:0.75em;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#22c55e;margin-bottom:12px">✅ Стало</div>
        <p style="color:#4b5563;font-size:0.875em;line-height:1.6;margin:0">Структурированный контент с заголовками, цитатами, таблицами, изображениями и callout-блоками.</p>
    </div>
</div>

<hr>

<!-- ── 19. TIMELINE ───────────────────────────────────────── -->
<h2>Пошаговый процесс (Timeline)</h2>
<div style="margin:2em 0">
    <div style="display:flex;gap:16px;margin-bottom:0">
        <div style="display:flex;flex-direction:column;align-items:center">
            <div style="width:40px;height:40px;border-radius:50%;background:#ec4899;color:white;display:flex;align-items:center;justify-content:center;font-size:0.875em;font-weight:700;box-shadow:0 4px 6px rgba(236,72,153,0.3);flex-shrink:0">01</div>
            <div style="width:2px;flex:1;background:#fbcfe8;min-height:32px"></div>
        </div>
        <div style="padding-bottom:32px">
            <h4 style="font-weight:600;margin:0 0 4px 0">Регистрация</h4>
            <p style="font-size:0.875em;color:#6b7280;margin:0">Создайте аккаунт и заполните профиль компании.</p>
        </div>
    </div>
    <div style="display:flex;gap:16px;margin-bottom:0">
        <div style="display:flex;flex-direction:column;align-items:center">
            <div style="width:40px;height:40px;border-radius:50%;background:#ec4899;color:white;display:flex;align-items:center;justify-content:center;font-size:0.875em;font-weight:700;box-shadow:0 4px 6px rgba(236,72,153,0.3);flex-shrink:0">02</div>
            <div style="width:2px;flex:1;background:#fbcfe8;min-height:32px"></div>
        </div>
        <div style="padding-bottom:32px">
            <h4 style="font-weight:600;margin:0 0 4px 0">Подтверждение</h4>
            <p style="font-size:0.875em;color:#6b7280;margin:0">Менеджер проверит ваши данные и активирует аккаунт.</p>
        </div>
    </div>
    <div style="display:flex;gap:16px;margin-bottom:0">
        <div style="display:flex;flex-direction:column;align-items:center">
            <div style="width:40px;height:40px;border-radius:50%;background:#ec4899;color:white;display:flex;align-items:center;justify-content:center;font-size:0.875em;font-weight:700;box-shadow:0 4px 6px rgba(236,72,153,0.3);flex-shrink:0">03</div>
            <div style="width:2px;flex:1;background:#fbcfe8;min-height:32px"></div>
        </div>
        <div style="padding-bottom:32px">
            <h4 style="font-weight:600;margin:0 0 4px 0">Каталог</h4>
            <p style="font-size:0.875em;color:#6b7280;margin:0">Получите доступ к полному каталогу с дилерскими ценами.</p>
        </div>
    </div>
    <div style="display:flex;gap:16px">
        <div style="display:flex;flex-direction:column;align-items:center">
            <div style="width:40px;height:40px;border-radius:50%;background:#ec4899;color:white;display:flex;align-items:center;justify-content:center;font-size:0.875em;font-weight:700;box-shadow:0 4px 6px rgba(236,72,153,0.3);flex-shrink:0">04</div>
        </div>
        <div>
            <h4 style="font-weight:600;margin:0 0 4px 0">Заказ</h4>
            <p style="font-size:0.875em;color:#6b7280;margin:0">Оформляйте заказы и отслеживайте доставку в личном кабинете.</p>
        </div>
    </div>
</div>

`;
