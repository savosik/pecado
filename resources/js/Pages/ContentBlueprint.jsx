import { Head } from '@inertiajs/react';
import UserLayout from './User/UserLayout';

/**
 * Blueprint-страница контентной типографики.
 *
 * Главная цель — заставить Tailwind CSS скомпилировать все prose-классы,
 * а также служить визуальным справочником оформления текстового контента
 * для статей, новостей, страниц брендов и прочего.
 */
export default function ContentBlueprint() {
    return (
        <UserLayout>
            <Head title="Blueprint типографики" />

            {/* ── Шапка страницы ─────────────────────────────────── */}
            <div className="mb-8">
                <h1 className="text-3xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-4xl">
                    Blueprint типографики контента
                </h1>
                <p className="mt-2 text-base text-gray-500 dark:text-gray-400">
                    Эталонная страница для просмотра всех блоков оформления текста.
                    Также используется для компиляции Tailwind-классов.
                </p>
            </div>

            {/* ══════════════════════════════════════════════════════
                PROSE-КОНТЕЙНЕР — основная типографика
                ══════════════════════════════════════════════════════ */}
            <article className="
                prose prose-lg prose-xl
                dark:prose-invert
                prose-headings:tracking-tight
                prose-a:text-pink-600 prose-a:decoration-pink-600/30 hover:prose-a:decoration-pink-600
                prose-blockquote:border-pink-500
                prose-img:rounded-xl prose-img:shadow-lg
                prose-pre:bg-gray-900 prose-pre:text-gray-100
                prose-code:text-pink-600 prose-code:before:content-none prose-code:after:content-none
                prose-table:overflow-hidden prose-table:rounded-lg
                prose-th:bg-gray-100 dark:prose-th:bg-gray-800
                prose-td:border-t prose-td:border-gray-200 dark:prose-td:border-gray-700
                prose-hr:border-gray-300 dark:prose-hr:border-gray-600
                prose-figure:my-8
                prose-figcaption:text-center prose-figcaption:italic
                prose-li:marker:text-pink-500
                prose-ol:list-decimal prose-ul:list-disc
                prose-strong:text-gray-900 dark:prose-strong:text-white
                prose-em:text-gray-700 dark:prose-em:text-gray-300
                max-w-none
                bg-white dark:bg-gray-800
                rounded-2xl shadow-sm
                border border-gray-200 dark:border-gray-700
                px-6 py-10 sm:px-10 sm:py-14 md:px-16 lg:px-20
            ">

                {/* ── 1. Заголовки ────────────────────────────────── */}
                <section>
                    <h1>Заголовок первого уровня (H1)</h1>
                    <p>
                        Используется как главный заголовок страницы или статьи.
                        Обычно один на всю страницу.
                    </p>

                    <h2>Заголовок второго уровня (H2)</h2>
                    <p>
                        Используется для основных разделов внутри статьи.
                        Каждый раздел логически разделяет контент.
                    </p>

                    <h3>Заголовок третьего уровня (H3)</h3>
                    <p>
                        Подразделы внутри секции. Помогает структурировать
                        длинные тексты и упрощает навигацию.
                    </p>

                    <h4>Заголовок четвёртого уровня (H4)</h4>
                    <p>Подпункт. Используется реже, но важен для сложного контента.</p>

                    <h5>Заголовок пятого уровня (H5)</h5>
                    <p>Мелкий подзаголовок — пояснения, примечания.</p>

                    <h6>Заголовок шестого уровня (H6)</h6>
                    <p>Самый мелкий заголовок. Для деталей и уточнений.</p>
                </section>

                <hr />

                {/* ── 2. Lead-параграф ────────────────────────────── */}
                <section>
                    <h2>Lead-параграф</h2>
                    <p className="lead text-xl text-gray-600 dark:text-gray-300 font-light leading-relaxed">
                        Вводный абзац статьи, который привлекает внимание читателя
                        и кратко описывает о чём пойдёт речь. Обычно выделяется
                        увеличенным размером шрифта и более светлым цветом.
                    </p>
                    <p>
                        Обычный параграф, который следует за вводным. Здесь начинается
                        основное повествование. Текст должен легко читаться, с комфортной
                        шириной строки и достаточным межстрочным интервалом.
                    </p>
                </section>

                <hr />

                {/* ── 3. Инлайн-выделения ─────────────────────────── */}
                <section>
                    <h2>Инлайн-выделения текста</h2>
                    <p>
                        В тексте можно использовать <strong>жирное выделение</strong> для
                        ключевых понятий, <em>курсив</em> для акцентов,{' '}
                        <mark className="bg-yellow-200 dark:bg-yellow-500/30 px-1 rounded">маркер для подсветки</mark>,{' '}
                        а также <del>зачёркнутый текст</del> и <ins>вставленный текст</ins>.
                    </p>
                    <p>
                        Химическая формула воды — H<sub>2</sub>O.
                        Площадь комнаты — 15 м<sup>2</sup>.{' '}
                        <small>Мелкий текст для примечаний.</small>{' '}
                        Аббревиатура <abbr title="Hypertext Markup Language" className="underline decoration-dotted cursor-help">HTML</abbr> расшифровывается
                        как «Hypertext Markup Language».
                    </p>
                    <p>
                        Комбинация: <strong><em>жирный курсив</em></strong> для максимального
                        акцента, а также <code>инлайн-код</code> для технических терминов
                        и переменных.
                    </p>
                    <p>
                        Клавиша <kbd className="px-2 py-0.5 text-sm font-mono bg-gray-100 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded shadow-sm">Ctrl</kbd> +{' '}
                        <kbd className="px-2 py-0.5 text-sm font-mono bg-gray-100 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded shadow-sm">S</kbd> — сохранить документ.
                    </p>
                </section>

                <hr />

                {/* ── 4. Ссылки ───────────────────────────────────── */}
                <section>
                    <h2>Ссылки</h2>
                    <p>
                        Обычная <a href="#">внутренняя ссылка</a> в тексте
                        выделяется цветом бренда. Внешняя{' '}
                        <a href="https://example.com" target="_blank" rel="noopener noreferrer">
                            ссылка на внешний ресурс ↗
                        </a>{' '}
                        может иметь иконку для обозначения нового окна.
                    </p>
                </section>

                <hr />

                {/* ── 5. Цитаты ──────────────────────────────────── */}
                <section>
                    <h2>Цитаты</h2>

                    <h3>Обычная цитата</h3>
                    <blockquote>
                        <p>
                            «Красота — это обещание счастья.» Хорошо оформленная цитата
                            привлекает внимание и придаёт тексту авторитетность.
                        </p>
                    </blockquote>

                    <h3>Цитата с указанием автора</h3>
                    <figure>
                        <blockquote>
                            <p>
                                Простота — это высшая степень утончённости. Каждый продукт,
                                который мы создаём, должен быть интуитивно понятным.
                            </p>
                        </blockquote>
                        <figcaption>
                            — Леонардо да Винчи
                        </figcaption>
                    </figure>

                    <h3>Pull-цитата (акцентная)</h3>
                    <div className="not-prose my-10 border-l-4 border-pink-500 bg-pink-50 dark:bg-pink-950/30 rounded-r-xl px-6 py-5 sm:px-8">
                        <p className="text-xl sm:text-2xl font-semibold text-gray-800 dark:text-gray-100 italic leading-snug">
                            «Дизайн — это не то, как вещь выглядит, а то, как она работает.»
                        </p>
                        <p className="mt-3 text-sm text-gray-500 dark:text-gray-400">
                            — Стив Джобс
                        </p>
                    </div>
                </section>

                <hr />

                {/* ── 6. Списки ──────────────────────────────────── */}
                <section>
                    <h2>Списки</h2>

                    <h3>Маркированный список</h3>
                    <ul>
                        <li>Первый элемент списка</li>
                        <li>Второй элемент с более длинным описанием, которое может занимать несколько строк текста</li>
                        <li>Третий элемент
                            <ul>
                                <li>Вложенный элемент первого уровня</li>
                                <li>Вложенный элемент второго уровня
                                    <ul>
                                        <li>Ещё глубже — третий уровень</li>
                                    </ul>
                                </li>
                            </ul>
                        </li>
                        <li>Четвёртый элемент</li>
                    </ul>

                    <h3>Нумерованный список</h3>
                    <ol>
                        <li>Зарегистрируйтесь в системе</li>
                        <li>Заполните профиль компании</li>
                        <li>Выберите интересующие бренды</li>
                        <li>Отправьте заявку на подтверждение
                            <ol>
                                <li>Заполните реквизиты</li>
                                <li>Прикрепите документы</li>
                                <li>Нажмите «Отправить»</li>
                            </ol>
                        </li>
                        <li>Дождитесь подтверждения от менеджера</li>
                    </ol>

                    <h3>Список определений</h3>
                    <dl className="space-y-4">
                        <div>
                            <dt className="font-semibold text-gray-900 dark:text-white">SKU</dt>
                            <dd className="mt-1 text-gray-600 dark:text-gray-400">Уникальный идентификатор товара в каталоге (Stock Keeping Unit).</dd>
                        </div>
                        <div>
                            <dt className="font-semibold text-gray-900 dark:text-white">РРЦ</dt>
                            <dd className="mt-1 text-gray-600 dark:text-gray-400">Рекомендованная розничная цена — цена, установленная производителем.</dd>
                        </div>
                        <div>
                            <dt className="font-semibold text-gray-900 dark:text-white">MOQ</dt>
                            <dd className="mt-1 text-gray-600 dark:text-gray-400">Минимальный объём заказа (Minimum Order Quantity).</dd>
                        </div>
                    </dl>

                    <h3>Чеклист</h3>
                    <ul className="space-y-2 list-none pl-0">
                        <li className="flex items-start gap-2">
                            <span className="mt-0.5 text-green-500">✓</span>
                            <span>Создать аккаунт</span>
                        </li>
                        <li className="flex items-start gap-2">
                            <span className="mt-0.5 text-green-500">✓</span>
                            <span>Заполнить профиль</span>
                        </li>
                        <li className="flex items-start gap-2">
                            <span className="mt-0.5 text-gray-400">○</span>
                            <span>Добавить реквизиты</span>
                        </li>
                        <li className="flex items-start gap-2">
                            <span className="mt-0.5 text-gray-400">○</span>
                            <span>Дождаться активации</span>
                        </li>
                    </ul>
                </section>

                <hr />

                {/* ── 7. Изображения ─────────────────────────────── */}
                <section>
                    <h2>Изображения</h2>

                    <h3>Полноширинное изображение</h3>
                    <img
                        src="https://placehold.co/1200x500/1a1a2e/e94560?text=Полноширинное+изображение&font=inter"
                        alt="Полноширинное изображение"
                        className="w-full"
                    />

                    <h3>Изображение с подписью</h3>
                    <figure>
                        <img
                            src="https://placehold.co/800x400/16213e/0f3460?text=Фото+с+подписью&font=inter"
                            alt="Пример фотографии с подписью"
                        />
                        <figcaption>
                            Рис. 1 — Пример изображения с подписью (figcaption).
                            Подпись помогает объяснить контекст фотографии.
                        </figcaption>
                    </figure>

                    <h3>Два изображения рядом</h3>
                    <div className="not-prose grid grid-cols-1 sm:grid-cols-2 gap-4 my-8">
                        <figure>
                            <img
                                src="https://placehold.co/600x400/533483/e94560?text=Фото+1&font=inter"
                                alt="Фото 1"
                                className="w-full rounded-xl shadow-lg"
                            />
                            <figcaption className="mt-2 text-sm text-center text-gray-500 dark:text-gray-400 italic">
                                Фото 1 — Левый элемент
                            </figcaption>
                        </figure>
                        <figure>
                            <img
                                src="https://placehold.co/600x400/0f3460/e94560?text=Фото+2&font=inter"
                                alt="Фото 2"
                                className="w-full rounded-xl shadow-lg"
                            />
                            <figcaption className="mt-2 text-sm text-center text-gray-500 dark:text-gray-400 italic">
                                Фото 2 — Правый элемент
                            </figcaption>
                        </figure>
                    </div>

                    <h3>Изображение с обтеканием текстом</h3>
                    <div className="sm:flex sm:gap-6 items-start">
                        <img
                            src="https://placehold.co/300x200/1a1a2e/53bbf4?text=Float&font=inter"
                            alt="Обтекание"
                            className="sm:w-1/3 w-full rounded-xl shadow-lg mb-4 sm:mb-0"
                        />
                        <div>
                            <p className="mt-0">
                                Здесь текст обтекает изображение. Этот приём полезен,
                                когда нужно показать небольшую иллюстрацию рядом с описанием.
                                На мобильных устройствах изображение занимает всю ширину и текст
                                идёт под ним, а на десктопе — рядом.
                            </p>
                            <p>
                                Дополнительный параграф продолжает обтекание. Такой макет
                                повсеместно используется в журнальной и редакторской вёрстке
                                для более динамичного расположения визуальных элементов.
                            </p>
                        </div>
                    </div>
                </section>

                <hr />

                {/* ── 8. Таблицы ─────────────────────────────────── */}
                <section>
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
                            <tr>
                                <td>PECADO</td>
                                <td>Аксессуары</td>
                                <td>342</td>
                                <td>Активен</td>
                            </tr>
                            <tr>
                                <td>Satisfyer</td>
                                <td>Электроника</td>
                                <td>156</td>
                                <td>Активен</td>
                            </tr>
                            <tr>
                                <td>Womanizer</td>
                                <td>Электроника</td>
                                <td>89</td>
                                <td>Активен</td>
                            </tr>
                            <tr>
                                <td>Lelo</td>
                                <td>Премиум</td>
                                <td>210</td>
                                <td>В обработке</td>
                            </tr>
                        </tbody>
                    </table>

                    <h3>Адаптивная таблица</h3>
                    <div className="not-prose overflow-x-auto my-8 rounded-xl border border-gray-200 dark:border-gray-700">
                        <table className="min-w-full text-sm">
                            <thead className="bg-gray-50 dark:bg-gray-800/50">
                                <tr>
                                    <th className="px-4 py-3 text-left font-semibold text-gray-900 dark:text-white">Артикул</th>
                                    <th className="px-4 py-3 text-left font-semibold text-gray-900 dark:text-white">Наименование</th>
                                    <th className="px-4 py-3 text-right font-semibold text-gray-900 dark:text-white">РРЦ</th>
                                    <th className="px-4 py-3 text-right font-semibold text-gray-900 dark:text-white">Остаток</th>
                                    <th className="px-4 py-3 text-center font-semibold text-gray-900 dark:text-white">Статус</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                                <tr className="hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors">
                                    <td className="px-4 py-3 font-mono text-gray-600 dark:text-gray-400">PCD-001</td>
                                    <td className="px-4 py-3 text-gray-900 dark:text-gray-100">Набор для массажа «Элегант»</td>
                                    <td className="px-4 py-3 text-right text-gray-900 dark:text-gray-100">4 590 ₽</td>
                                    <td className="px-4 py-3 text-right text-gray-900 dark:text-gray-100">48</td>
                                    <td className="px-4 py-3 text-center">
                                        <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-400">В наличии</span>
                                    </td>
                                </tr>
                                <tr className="hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors">
                                    <td className="px-4 py-3 font-mono text-gray-600 dark:text-gray-400">PCD-042</td>
                                    <td className="px-4 py-3 text-gray-900 dark:text-gray-100">Комплект белья «Нуар»</td>
                                    <td className="px-4 py-3 text-right text-gray-900 dark:text-gray-100">7 290 ₽</td>
                                    <td className="px-4 py-3 text-right text-gray-900 dark:text-gray-100">12</td>
                                    <td className="px-4 py-3 text-center">
                                        <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-400">Мало</span>
                                    </td>
                                </tr>
                                <tr className="hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors">
                                    <td className="px-4 py-3 font-mono text-gray-600 dark:text-gray-400">PCD-099</td>
                                    <td className="px-4 py-3 text-gray-900 dark:text-gray-100">Масло для тела «Тропикана»</td>
                                    <td className="px-4 py-3 text-right text-gray-900 dark:text-gray-100">1 890 ₽</td>
                                    <td className="px-4 py-3 text-right text-gray-900 dark:text-gray-100">0</td>
                                    <td className="px-4 py-3 text-center">
                                        <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-400">Нет в наличии</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <hr />

                {/* ── 9. Код ─────────────────────────────────────── */}
                <section>
                    <h2>Блоки кода</h2>

                    <h3>Инлайн-код</h3>
                    <p>
                       Для получения списка товаров используйте метод <code>Product::query()</code>,
                       а фильтрацию по бренду — через <code>-&gt;whereBrand($brandId)</code>.
                    </p>

                    <h3>Блок кода</h3>
                    <pre><code>{`// Пример запроса к каталогу
$products = Product::query()
    ->where('is_active', true)
    ->whereBetween('price', [1000, 5000])
    ->with(['brand', 'category', 'media'])
    ->orderBy('created_at', 'desc')
    ->paginate(24);

return Inertia::render('Catalog/Index', [
    'products' => ProductResource::collection($products),
]);`}</code></pre>

                    <h3>Многострочный блок с подсветкой</h3>
                    <pre><code>{`{
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
}`}</code></pre>
                </section>

                <hr />

                {/* ── 10. Callout-блоки / Алерты ─────────────────── */}
                <section>
                    <h2>Информационные блоки (Callouts)</h2>

                    {/* Info */}
                    <div className="not-prose my-6 flex gap-4 rounded-xl border border-blue-200 bg-blue-50 p-5 dark:border-blue-800 dark:bg-blue-950/40">
                        <div className="shrink-0 text-2xl">ℹ️</div>
                        <div>
                            <p className="font-semibold text-blue-900 dark:text-blue-200">Информация</p>
                            <p className="mt-1 text-sm text-blue-700 dark:text-blue-300">
                                Вы можете изменить настройки уведомлений в разделе «Профиль» → «Настройки».
                                Изменения вступят в силу немедленно.
                            </p>
                        </div>
                    </div>

                    {/* Success */}
                    <div className="not-prose my-6 flex gap-4 rounded-xl border border-green-200 bg-green-50 p-5 dark:border-green-800 dark:bg-green-950/40">
                        <div className="shrink-0 text-2xl">✅</div>
                        <div>
                            <p className="font-semibold text-green-900 dark:text-green-200">Успешно</p>
                            <p className="mt-1 text-sm text-green-700 dark:text-green-300">
                                Ваш заказ №12847 успешно оформлен! Менеджер свяжется с вами
                                в течение рабочего дня для подтверждения.
                            </p>
                        </div>
                    </div>

                    {/* Warning */}
                    <div className="not-prose my-6 flex gap-4 rounded-xl border border-yellow-200 bg-yellow-50 p-5 dark:border-yellow-800 dark:bg-yellow-950/40">
                        <div className="shrink-0 text-2xl">⚠️</div>
                        <div>
                            <p className="font-semibold text-yellow-900 dark:text-yellow-200">Внимание</p>
                            <p className="mt-1 text-sm text-yellow-700 dark:text-yellow-300">
                                Товары из вашей корзины будут зарезервированы только после
                                подтверждения заказа менеджером. До этого момента наличие может измениться.
                            </p>
                        </div>
                    </div>

                    {/* Danger */}
                    <div className="not-prose my-6 flex gap-4 rounded-xl border border-red-200 bg-red-50 p-5 dark:border-red-800 dark:bg-red-950/40">
                        <div className="shrink-0 text-2xl">🚫</div>
                        <div>
                            <p className="font-semibold text-red-900 dark:text-red-200">Важно</p>
                            <p className="mt-1 text-sm text-red-700 dark:text-red-300">
                                Возврат товара возможен только в течение 14 дней с момента
                                получения. Товар должен быть в оригинальной упаковке.
                            </p>
                        </div>
                    </div>
                </section>

                <hr />

                {/* ── 11. Разделители ────────────────────────────── */}
                <section>
                    <h2>Разделители</h2>
                    <p>Стандартный разделитель (hr) отображается выше и между каждой секцией.</p>

                    <h3>Декоративный разделитель</h3>
                    <div className="not-prose flex items-center gap-4 my-8">
                        <div className="flex-1 h-px bg-gradient-to-r from-transparent via-gray-300 to-transparent dark:via-gray-600"></div>
                        <span className="text-gray-400 dark:text-gray-500 text-sm">✦ ✦ ✦</span>
                        <div className="flex-1 h-px bg-gradient-to-r from-transparent via-gray-300 to-transparent dark:via-gray-600"></div>
                    </div>
                </section>

                <hr />

                {/* ── 12. Details/Summary ────────────────────────── */}
                <section>
                    <h2>Раскрывающиеся блоки</h2>

                    <details className="not-prose group my-4 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                        <summary className="cursor-pointer select-none px-5 py-4 font-semibold text-gray-900 dark:text-white bg-gray-50 dark:bg-gray-800/50 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors">
                            Как оформить заказ? (нажмите, чтобы раскрыть)
                        </summary>
                        <div className="px-5 py-4 text-gray-600 dark:text-gray-300 text-sm space-y-2">
                            <p>1. Добавьте нужные товары в корзину.</p>
                            <p>2. Перейдите в корзину и проверьте состав заказа.</p>
                            <p>3. Нажмите «Оформить заказ» и выберите способ доставки.</p>
                            <p>4. Подтвердите заказ. Менеджер свяжется с вами для уточнения деталей.</p>
                        </div>
                    </details>

                    <details className="not-prose group my-4 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                        <summary className="cursor-pointer select-none px-5 py-4 font-semibold text-gray-900 dark:text-white bg-gray-50 dark:bg-gray-800/50 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors">
                            Какие условия возврата?
                        </summary>
                        <div className="px-5 py-4 text-gray-600 dark:text-gray-300 text-sm space-y-2">
                            <p>Возврат товара осуществляется в течение 14 дней с момента получения.</p>
                            <p>Товар должен быть в оригинальной упаковке, без следов использования.</p>
                            <p>Для оформления возврата свяжитесь с менеджером или создайте заявку в личном кабинете.</p>
                        </div>
                    </details>
                </section>

                <hr />

                {/* ── 13. Видео / iframe embed ───────────────────── */}
                <section>
                    <h2>Встроенное видео</h2>
                    <div className="not-prose my-8">
                        <div className="aspect-video rounded-xl overflow-hidden bg-gray-100 dark:bg-gray-800 shadow-lg">
                            <div className="w-full h-full flex items-center justify-center text-gray-400 dark:text-gray-500">
                                <div className="text-center">
                                    <div className="text-5xl mb-3">▶</div>
                                    <p className="text-sm">Здесь будет видео (responsive iframe)</p>
                                    <p className="text-xs mt-1 text-gray-300 dark:text-gray-600">Соотношение сторон 16:9</p>
                                </div>
                            </div>
                        </div>
                        <p className="mt-3 text-sm text-center text-gray-500 dark:text-gray-400 italic">
                            Видео: обзор коллекции «Весна-Лето 2026»
                        </p>
                    </div>
                </section>

                <hr />

                {/* ── 14. Выделенные карточки / блоки с фоном ──── */}
                <section>
                    <h2>Карточки и акцентные блоки</h2>

                    <h3>Карточка с градиентом</h3>
                    <div className="not-prose my-8 rounded-2xl bg-gradient-to-br from-pink-500 to-purple-600 p-8 sm:p-10 text-white shadow-xl">
                        <h3 className="text-2xl font-bold mb-3">Специальное предложение</h3>
                        <p className="text-pink-100 text-lg leading-relaxed">
                            Скидка 15% на весь ассортимент бренда PECADO при заказе от 50 000 ₽.
                            Предложение действует до конца месяца.
                        </p>
                        <div className="mt-6">
                            <span className="inline-block px-6 py-3 bg-white text-pink-600 font-semibold rounded-xl shadow-lg hover:shadow-xl transition-shadow cursor-pointer">
                                Подробнее →
                            </span>
                        </div>
                    </div>

                    <h3>Серая карточка-заметка</h3>
                    <div className="not-prose my-8 rounded-xl bg-gray-100 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700 p-6 sm:p-8">
                        <h4 className="text-lg font-semibold text-gray-900 dark:text-white mb-2">📝 На заметку</h4>
                        <p className="text-sm text-gray-600 dark:text-gray-300 leading-relaxed">
                            Все цены на сайте указаны без учёта НДС. Итоговая стоимость
                            с НДС рассчитывается при оформлении заказа в зависимости
                            от вашей системы налогообложения.
                        </p>
                    </div>

                    <h3>Статистика в карточках</h3>
                    <div className="not-prose my-8 grid grid-cols-2 sm:grid-cols-4 gap-4">
                        {[
                            { value: '1 200+', label: 'Товаров' },
                            { value: '45', label: 'Брендов' },
                            { value: '98%', label: 'Довольных клиентов' },
                            { value: '24ч', label: 'Отгрузка' },
                        ].map((stat) => (
                            <div key={stat.label} className="text-center rounded-xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5 shadow-sm">
                                <div className="text-2xl sm:text-3xl font-bold text-pink-600 dark:text-pink-400">{stat.value}</div>
                                <div className="mt-1 text-sm text-gray-500 dark:text-gray-400">{stat.label}</div>
                            </div>
                        ))}
                    </div>
                </section>

                <hr />

                {/* ── 15. Badges / метки ─────────────────────────── */}
                <section>
                    <h2>Метки и бейджи</h2>
                    <div className="not-prose flex flex-wrap gap-2 my-4">
                        <span className="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200">Обычный</span>
                        <span className="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-pink-100 text-pink-800 dark:bg-pink-900/40 dark:text-pink-300">Акция</span>
                        <span className="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300">Новинка</span>
                        <span className="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300">Популярный</span>
                        <span className="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-300">Ожидается</span>
                        <span className="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300">Снят с продажи</span>
                        <span className="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-purple-100 text-purple-800 dark:bg-purple-900/40 dark:text-purple-300">Эксклюзив</span>
                    </div>
                </section>

                <hr />

                {/* ── 16. Сноски / footnotes ─────────────────────── */}
                <section>
                    <h2>Сноски</h2>
                    <p>
                        Минимальная партия заказа зависит от бренда<sup className="text-pink-500 cursor-pointer">[1]</sup>.
                        Стоимость доставки рассчитывается индивидуально<sup className="text-pink-500 cursor-pointer">[2]</sup>.
                    </p>
                    <div className="not-prose mt-8 pt-6 border-t border-gray-200 dark:border-gray-700 text-sm text-gray-500 dark:text-gray-400 space-y-1">
                        <p><span className="text-pink-500 font-medium">[1]</span> Для брендов Satisfyer и Womanizer MOQ составляет 30 000 ₽.</p>
                        <p><span className="text-pink-500 font-medium">[2]</span> Бесплатная доставка при заказе от 100 000 ₽.</p>
                    </div>
                </section>

                <hr />

                {/* ── 17. Breadcrumbs в тексте ───────────────────── */}
                <section>
                    <h2>Навигационные хлебные крошки</h2>
                    <nav className="not-prose my-4">
                        <ol className="flex flex-wrap items-center gap-1.5 text-sm text-gray-500 dark:text-gray-400">
                            <li><a href="#" className="hover:text-pink-600 transition-colors">Главная</a></li>
                            <li className="text-gray-300 dark:text-gray-600">/</li>
                            <li><a href="#" className="hover:text-pink-600 transition-colors">Каталог</a></li>
                            <li className="text-gray-300 dark:text-gray-600">/</li>
                            <li><a href="#" className="hover:text-pink-600 transition-colors">Бренды</a></li>
                            <li className="text-gray-300 dark:text-gray-600">/</li>
                            <li className="text-gray-900 dark:text-white font-medium">PECADO</li>
                        </ol>
                    </nav>
                </section>

                <hr />

                {/* ── 18. Текстовые колонки ──────────────────────── */}
                <section>
                    <h2>Многоколоночный текст</h2>
                    <div className="columns-1 sm:columns-2 gap-8 text-sm leading-relaxed">
                        <p>
                            Многоколоночная вёрстка позволяет разместить большой объём текста
                            более компактно. Она особенно полезна для длинных описаний брендов,
                            условий сотрудничества или юридических текстов.
                        </p>
                        <p>
                            На мобильных устройствах текст отображается в одну колонку, а на
                            планшетах и десктопах — в две. Это обеспечивает комфортное чтение
                            на любом устройстве и экономит вертикальное пространство.
                        </p>
                        <p>
                            Рекомендуется использовать колонки для текстов, которые не содержат
                            крупных визуальных элементов (изображений, таблиц). Иначе
                            разрыв колонки может разбить элемент на две части.
                        </p>
                    </div>
                </section>

                <hr />

                {/* ── 19. Стилизация «до/после» ─────────────────── */}
                <section>
                    <h2>Сравнение «до / после»</h2>
                    <div className="not-prose grid grid-cols-1 sm:grid-cols-2 gap-6 my-8">
                        <div className="rounded-xl border-2 border-red-200 dark:border-red-800 p-6 bg-red-50/50 dark:bg-red-950/20">
                            <div className="text-xs font-bold uppercase tracking-wider text-red-500 mb-3">❌ Было</div>
                            <p className="text-gray-600 dark:text-gray-300 text-sm">
                                Однообразный контент без структуры, сплошной поток текста,
                                отсутствие визуальных акцентов и навигации.
                            </p>
                        </div>
                        <div className="rounded-xl border-2 border-green-200 dark:border-green-800 p-6 bg-green-50/50 dark:bg-green-950/20">
                            <div className="text-xs font-bold uppercase tracking-wider text-green-500 mb-3">✅ Стало</div>
                            <p className="text-gray-600 dark:text-gray-300 text-sm">
                                Структурированный контент с заголовками, цитатами, таблицами,
                                изображениями и callout-блоками для удобного восприятия.
                            </p>
                        </div>
                    </div>
                </section>

                <hr />

                {/* ── 20. Timeline / шаги ────────────────────────── */}
                <section>
                    <h2>Пошаговый процесс (Timeline)</h2>
                    <div className="not-prose my-8 space-y-0">
                        {[
                            { step: '01', title: 'Регистрация', desc: 'Создайте аккаунт и заполните профиль компании.' },
                            { step: '02', title: 'Подтверждение', desc: 'Менеджер проверит ваши данные и активирует аккаунт.' },
                            { step: '03', title: 'Каталог', desc: 'Получите доступ к полному каталогу с дилерскими ценами.' },
                            { step: '04', title: 'Заказ', desc: 'Оформляйте заказы и отслеживайте доставку в личном кабинете.' },
                        ].map((item, i, arr) => (
                            <div key={item.step} className="flex gap-4">
                                <div className="flex flex-col items-center">
                                    <div className="w-10 h-10 rounded-full bg-pink-500 text-white flex items-center justify-center text-sm font-bold shadow-md">
                                        {item.step}
                                    </div>
                                    {i < arr.length - 1 && (
                                        <div className="w-0.5 h-full min-h-[3rem] bg-pink-200 dark:bg-pink-800"></div>
                                    )}
                                </div>
                                <div className="pb-8">
                                    <h4 className="font-semibold text-gray-900 dark:text-white">{item.title}</h4>
                                    <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">{item.desc}</p>
                                </div>
                            </div>
                        ))}
                    </div>
                </section>

            </article>

            {/* ── Дополнительные Tailwind-классы для компиляции ── */}
            <div className="hidden">
                {/* prose size variants */}
                <div className="prose-sm prose-base prose-lg prose-xl prose-2xl" />
                {/* prose color variants */}
                <div className="prose-stone prose-gray prose-zinc prose-neutral prose-slate" />
                {/* responsive prose */}
                <div className="sm:prose-base md:prose-lg lg:prose-xl" />
                {/* dark mode */}
                <div className="dark:prose-invert" />
                {/* element modifiers */}
                <div className="
                    prose-headings:font-bold prose-headings:text-gray-900
                    prose-p:text-gray-700 prose-p:leading-relaxed
                    prose-a:text-pink-600 prose-a:no-underline prose-a:underline
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
                {/* dark mode element modifiers */}
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
