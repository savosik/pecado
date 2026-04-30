import { Head } from '@inertiajs/react';
import { Box, Text, Flex, Image, HStack, Tag, Badge } from '@chakra-ui/react';
import UserLayout from './User/UserLayout';
import Breadcrumbs from '@/components/common/Breadcrumbs';
import PageHeader from '@/components/common/PageHeader';
import { Prose } from '@/components/ui/prose';

/**
 * Blueprint-страница контентной типографики.
 *
 * Рендерится в том же layout'е, что и реальные статьи (ArticleShow).
 * Демонстрирует все возможные блоки: карточки товаров, цитаты,
 * отзывы, маркерные выделения, буквицы, декоративные зарисовки и т.д.
 */
export default function ContentBlueprint() {
    const breadcrumbs = [
        { label: 'Главная', url: '/' },
        { label: 'Статьи', url: '/articles' },
        { label: 'Content Blueprint' },
    ];

    return (
        <UserLayout>
            <Head title="Content Blueprint · Типографика статей" />

            {/* ── Inline CSS для blueprint-элементов ── */}
            <style>{`
                @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;0,900;1,400;1,700;1,900&family=Source+Serif+4:ital,wght@0,300;0,400;0,600;1,300;1,400;1,600&family=Caveat:wght@400;500;600;700&display=swap');

                /* ─── DROP CAP (Буквица) ─── */
                .bp-drop-cap::first-letter {
                    font-family: 'Playfair Display', serif;
                    font-size: 4.5em;
                    font-weight: 900;
                    float: left;
                    line-height: 0.75;
                    margin-right: 12px;
                    margin-top: 8px;
                    color: #1a1a1a;
                }
                [data-theme="dark"] .bp-drop-cap::first-letter,
                .dark .bp-drop-cap::first-letter {
                    color: #f0f0f0;
                }

                /* ─── MARKER HIGHLIGHT (выделение маркером) ─── */
                .bp-marker-yellow {
                    background: linear-gradient(104deg, rgba(255,220,0,0) 0.9%, rgba(255,220,0,0.35) 2.4%, rgba(255,220,0,0.45) 5.8%, rgba(255,220,0,0.35) 93%, rgba(255,220,0,0.15) 96%, rgba(255,220,0,0) 98%);
                    padding: 2px 6px;
                    border-radius: 4px;
                    -webkit-box-decoration-break: clone;
                    box-decoration-break: clone;
                }
                .bp-marker-pink {
                    background: linear-gradient(104deg, rgba(255,100,130,0) 0.9%, rgba(255,100,130,0.25) 2.4%, rgba(255,100,130,0.35) 5.8%, rgba(255,100,130,0.25) 93%, rgba(255,100,130,0.1) 96%, rgba(255,100,130,0) 98%);
                    padding: 2px 6px;
                    border-radius: 4px;
                    -webkit-box-decoration-break: clone;
                    box-decoration-break: clone;
                }
                .bp-marker-green {
                    background: linear-gradient(104deg, rgba(0,200,130,0) 0.9%, rgba(0,200,130,0.2) 2.4%, rgba(0,200,130,0.3) 5.8%, rgba(0,200,130,0.2) 93%, rgba(0,200,130,0.08) 96%, rgba(0,200,130,0) 98%);
                    padding: 2px 6px;
                    border-radius: 4px;
                    -webkit-box-decoration-break: clone;
                    box-decoration-break: clone;
                }

                /* ─── PULL QUOTE (Врезки-цитаты) ─── */
                .bp-pull-quote {
                    font-family: 'Playfair Display', serif;
                    font-style: italic;
                    font-size: 1.6rem;
                    line-height: 1.3;
                    text-align: center;
                    padding: 32px 24px;
                    margin: 32px 0;
                    border-top: 3px solid #1a1a1a;
                    border-bottom: 3px solid #1a1a1a;
                    position: relative;
                    color: #1a1a1a;
                }
                [data-theme="dark"] .bp-pull-quote,
                .dark .bp-pull-quote {
                    border-color: #666;
                    color: #e0e0e0;
                }
                .bp-pull-quote::before {
                    content: '«';
                    font-size: 4rem;
                    line-height: 0;
                    position: absolute;
                    top: 40px;
                    left: 16px;
                    color: rgba(0,0,0,0.08);
                    font-family: 'Playfair Display', serif;
                }

                /* ─── SIDE PULL (Боковая врезка) ─── */
                .bp-side-pull {
                    font-family: 'Playfair Display', serif;
                    font-style: italic;
                    font-weight: 700;
                    font-size: 1.35rem;
                    line-height: 1.25;
                    color: #1a1a1a;
                    border-left: 4px solid #e8364f;
                    padding-left: 20px;
                    margin: 28px 0;
                }
                [data-theme="dark"] .bp-side-pull,
                .dark .bp-side-pull {
                    color: #e0e0e0;
                    border-color: #e8364f;
                }

                /* ─── REVIEW CARD (Карточка отзыва) ─── */
                .bp-review-card {
                    background: linear-gradient(135deg, #fafafa 0%, #f5f2ee 100%);
                    border: 1px solid #e8e4df;
                    border-radius: 8px;
                    padding: 28px;
                    margin: 32px 0;
                    position: relative;
                    overflow: hidden;
                }
                [data-theme="dark"] .bp-review-card,
                .dark .bp-review-card {
                    background: linear-gradient(135deg, #2d2d2d 0%, #252525 100%);
                    border-color: #444;
                }
                .bp-review-card::before {
                    content: '';
                    position: absolute;
                    top: 0;
                    left: 0;
                    width: 5px;
                    height: 100%;
                    background: linear-gradient(to bottom, #e8364f, #ff6b81);
                }
                .bp-review-stars {
                    color: #fbbf24;
                    letter-spacing: 3px;
                    font-size: 1.1rem;
                    margin-bottom: 8px;
                }
                .bp-review-text {
                    font-family: 'Source Serif 4', Georgia, serif;
                    font-style: italic;
                    font-size: 0.95rem;
                    line-height: 1.7;
                    color: #444;
                    margin-bottom: 12px;
                }
                [data-theme="dark"] .bp-review-text,
                .dark .bp-review-text {
                    color: #bbb;
                }
                .bp-review-author {
                    font-family: 'Inter', sans-serif;
                    font-size: 0.75rem;
                    font-weight: 600;
                    color: #999;
                    text-transform: uppercase;
                    letter-spacing: 0.08em;
                }

                /* ─── PRODUCT CARD INSERT (Врезка товаров) ─── */
                .bp-product-insert {
                    margin: 40px -16px;
                    padding: 32px 16px;
                    background: linear-gradient(135deg, #1a1a1a 0%, #2d2024 100%);
                    position: relative;
                    overflow: hidden;
                }
                .bp-product-insert::before {
                    content: '';
                    position: absolute;
                    top: -50%;
                    right: -20%;
                    width: 400px;
                    height: 400px;
                    background: radial-gradient(circle, rgba(232,54,79,0.15) 0%, transparent 70%);
                    pointer-events: none;
                }
                .bp-product-insert-title {
                    font-family: 'Inter', sans-serif;
                    font-size: 0.7rem;
                    font-weight: 700;
                    text-transform: uppercase;
                    letter-spacing: 0.15em;
                    color: rgba(255,255,255,0.5);
                    margin-bottom: 20px;
                    text-align: center;
                }
                .bp-product-grid {
                    display: grid;
                    grid-template-columns: repeat(3, 1fr);
                    gap: 16px;
                    max-width: 700px;
                    margin: 0 auto;
                }
                @media (max-width: 640px) {
                    .bp-product-grid {
                        grid-template-columns: 1fr 1fr;
                    }
                }
                .bp-product-card {
                    background: #fff;
                    border-radius: 8px;
                    overflow: hidden;
                    transition: transform 0.25s ease, box-shadow 0.25s ease;
                    cursor: pointer;
                }
                .bp-product-card:hover {
                    transform: translateY(-4px);
                    box-shadow: 0 12px 30px rgba(0,0,0,0.3);
                }
                .bp-product-card img {
                    width: 100%;
                    aspect-ratio: 3/4;
                    object-fit: cover;
                    display: block;
                }
                .bp-product-card-info {
                    padding: 12px;
                }
                .bp-product-card-brand {
                    font-family: 'Inter', sans-serif;
                    font-size: 0.6rem;
                    text-transform: uppercase;
                    letter-spacing: 0.1em;
                    color: #999;
                    margin-bottom: 4px;
                }
                .bp-product-card-name {
                    font-size: 0.8rem;
                    font-weight: 500;
                    color: #1a1a1a;
                    line-height: 1.3;
                    margin-bottom: 8px;
                    display: -webkit-box;
                    -webkit-line-clamp: 2;
                    -webkit-box-orient: vertical;
                    overflow: hidden;
                }
                .bp-product-card-price {
                    font-family: 'Inter', sans-serif;
                    font-weight: 700;
                    font-size: 0.85rem;
                    color: #e8364f;
                }

                /* ─── DOODLE / MARGIN DECORATION (Зарисовки на полях) ─── */
                .bp-doodle-container {
                    position: relative;
                }
                .bp-doodle {
                    position: absolute;
                    pointer-events: none;
                    opacity: 0.12;
                    z-index: 0;
                }
                [data-theme="dark"] .bp-doodle,
                .dark .bp-doodle {
                    opacity: 0.08;
                }
                .bp-doodle-left {
                    left: -60px;
                    top: 20px;
                }
                .bp-doodle-right {
                    right: -50px;
                    bottom: 40px;
                }
                @media (max-width: 900px) {
                    .bp-doodle { display: none; }
                }

                /* ─── OPINION BOX (Интересное мнение) ─── */
                .bp-opinion-box {
                    background: #fffdf5;
                    border: 2px dashed #e8c97a;
                    border-radius: 10px;
                    padding: 24px 28px;
                    margin: 32px 0;
                    position: relative;
                }
                [data-theme="dark"] .bp-opinion-box,
                .dark .bp-opinion-box {
                    background: #2a2720;
                    border-color: #6b5d3e;
                }
                .bp-opinion-tag {
                    position: absolute;
                    top: -12px;
                    left: 20px;
                    background: #e8c97a;
                    color: #5a4a1e;
                    font-family: 'Inter', sans-serif;
                    font-size: 0.65rem;
                    font-weight: 700;
                    text-transform: uppercase;
                    letter-spacing: 0.1em;
                    padding: 4px 14px;
                    border-radius: 4px;
                }
                .bp-opinion-title {
                    font-family: 'Playfair Display', serif;
                    font-weight: 700;
                    font-size: 1.15rem;
                    color: #1a1a1a;
                    margin-bottom: 12px;
                    margin-top: 8px;
                }
                [data-theme="dark"] .bp-opinion-title,
                .dark .bp-opinion-title {
                    color: #e8d4a0;
                }

                /* ─── HANDWRITTEN ANNOTATION (Caveat font) ─── */
                .bp-handwritten {
                    font-family: 'Caveat', cursive;
                    font-size: 1.3rem;
                    color: #e8364f;
                    transform: rotate(-2deg);
                    display: inline-block;
                    margin: 8px 0;
                }

                /* ─── NUMBERED LIST (красивые нумерованные списки) ─── */
                .bp-numbered-list {
                    counter-reset: bp-counter;
                    list-style: none;
                    padding: 0;
                    margin: 20px 0;
                }
                .bp-numbered-list li {
                    counter-increment: bp-counter;
                    display: flex;
                    align-items: flex-start;
                    gap: 16px;
                    margin-bottom: 16px;
                    font-size: 0.9rem;
                    line-height: 1.6;
                }
                .bp-numbered-list li::before {
                    content: counter(bp-counter, decimal-leading-zero);
                    font-family: 'Playfair Display', serif;
                    font-weight: 900;
                    font-size: 1.8rem;
                    line-height: 1;
                    color: #e8364f;
                    flex-shrink: 0;
                    min-width: 40px;
                }

                /* ─── MAGAZINE COVER HEADER ─── */
                .bp-cover-section {
                    position: relative;
                    min-height: 460px;
                    display: flex;
                    align-items: flex-end;
                    border-radius: 8px;
                    overflow: hidden;
                    margin-bottom: 32px;
                }
                .bp-cover-section img {
                    position: absolute;
                    inset: 0;
                    width: 100%;
                    height: 100%;
                    object-fit: cover;
                }
                .bp-cover-overlay {
                    position: relative;
                    z-index: 1;
                    padding: 48px 40px;
                    width: 100%;
                    background: linear-gradient(to top, rgba(0,0,0,0.8) 0%, rgba(0,0,0,0.2) 50%, transparent 100%);
                }
                .bp-cover-label {
                    font-family: 'Inter', sans-serif;
                    font-size: 0.6rem;
                    font-weight: 700;
                    text-transform: uppercase;
                    letter-spacing: 0.2em;
                    color: #fff;
                    background: #e8364f;
                    display: inline-block;
                    padding: 5px 14px;
                    margin-bottom: 16px;
                }
                .bp-cover-title {
                    font-family: 'Playfair Display', serif;
                    font-weight: 900;
                    font-size: 2.8rem;
                    line-height: 1.05;
                    color: #fff;
                    margin: 0 0 12px;
                    max-width: 550px;
                }
                .bp-cover-sub {
                    font-family: 'Source Serif 4', serif;
                    font-size: 1rem;
                    color: rgba(255,255,255,0.75);
                    line-height: 1.5;
                    max-width: 420px;
                }
                @media (max-width: 640px) {
                    .bp-cover-title { font-size: 1.8rem; }
                    .bp-cover-section { min-height: 340px; }
                    .bp-cover-overlay { padding: 28px 20px; }
                }

                /* ─── PHOTO MOSAIC (Фото-мозаика) ─── */
                .bp-photo-mosaic {
                    display: grid;
                    grid-template-columns: 2fr 1fr;
                    grid-template-rows: 200px 200px;
                    gap: 4px;
                    margin: 32px 0;
                    border-radius: 8px;
                    overflow: hidden;
                }
                .bp-photo-mosaic img {
                    width: 100%;
                    height: 100%;
                    object-fit: cover;
                    display: block;
                }
                .bp-photo-mosaic .span-rows {
                    grid-row: span 2;
                }
                @media (max-width: 640px) {
                    .bp-photo-mosaic {
                        grid-template-columns: 1fr;
                        grid-template-rows: auto;
                    }
                    .bp-photo-mosaic .span-rows { grid-row: auto; }
                }

                /* ─── INFO BOX (Врезка с фактом) ─── */
                .bp-info-box {
                    background: linear-gradient(135deg, #f0f4ff 0%, #e8eeff 100%);
                    border-left: 5px solid #4f6ef7;
                    border-radius: 0 10px 10px 0;
                    padding: 24px 28px;
                    margin: 32px 0;
                }
                [data-theme="dark"] .bp-info-box,
                .dark .bp-info-box {
                    background: linear-gradient(135deg, #1e2235 0%, #1a1f30 100%);
                    border-color: #4f6ef7;
                }
                .bp-info-icon {
                    font-size: 1.6rem;
                    margin-bottom: 8px;
                }
                .bp-info-title {
                    font-family: 'Inter', sans-serif;
                    font-weight: 700;
                    font-size: 0.85rem;
                    text-transform: uppercase;
                    letter-spacing: 0.05em;
                    color: #4f6ef7;
                    margin-bottom: 8px;
                }
                .bp-info-text {
                    font-size: 0.9rem;
                    line-height: 1.7;
                    color: #333;
                }
                [data-theme="dark"] .bp-info-text,
                .dark .bp-info-text {
                    color: #ccc;
                }

                /* ─── COMPARISON TABLE ─── */
                .bp-comparison {
                    margin: 32px 0;
                    overflow-x: auto;
                }
                .bp-comparison table {
                    width: 100%;
                    border-collapse: collapse;
                    font-size: 0.85rem;
                }
                .bp-comparison th {
                    background: #1a1a1a;
                    color: #fff;
                    padding: 12px 16px;
                    text-align: left;
                    font-family: 'Inter', sans-serif;
                    font-weight: 600;
                    font-size: 0.75rem;
                    text-transform: uppercase;
                    letter-spacing: 0.06em;
                }
                [data-theme="dark"] .bp-comparison th,
                .dark .bp-comparison th {
                    background: #333;
                }
                .bp-comparison td {
                    padding: 12px 16px;
                    border-bottom: 1px solid #eee;
                    color: #444;
                }
                [data-theme="dark"] .bp-comparison td,
                .dark .bp-comparison td {
                    border-color: #444;
                    color: #ccc;
                }
                .bp-comparison tr:hover td {
                    background: rgba(232,54,79,0.04);
                }

                /* ─── SIGNATURE / ENDMARK ─── */
                .bp-endmark {
                    text-align: center;
                    margin: 48px 0 24px;
                    font-family: 'Playfair Display', serif;
                    font-style: italic;
                    font-size: 1.2rem;
                    color: #999;
                }
                .bp-endmark::before {
                    content: '— ';
                }
                .bp-endmark::after {
                    content: ' —';
                }

                /* ─── TWO COLUMN TEXT ─── */
                .bp-two-col {
                    column-count: 2;
                    column-gap: 36px;
                    font-size: 0.9rem;
                    line-height: 1.75;
                }
                .bp-two-col p {
                    text-align: justify;
                    margin: 0 0 14px;
                    break-inside: avoid;
                }
                @media (max-width: 640px) {
                    .bp-two-col { column-count: 1; }
                }
            `}</style>

            <Breadcrumbs items={breadcrumbs} />
            <PageHeader
                title="Content Blueprint"
                subtitle="Эталонная страница · Журнальная типографика для статей"
            />

            {/* ═══════════════════════════════════════════════════
                Основной контейнер статьи (идентичен ArticleShow)
               ═══════════════════════════════════════════════════ */}
            <Box
                bg="bg"
                _dark={{ bg: 'gray.800' }}
                border="1px solid"
                borderColor="border.muted"
                borderRadius="sm"
                overflow="hidden"
            >
                {/* ─── COVER IMAGE ─── */}
                <div className="bp-cover-section">
                    <img
                        src="https://images.unsplash.com/photo-1531746020798-e6953c6e8e04?w=1200&h=600&fit=crop&crop=face"
                        alt="Обложка Blueprint"
                    />
                    <div className="bp-cover-overlay">
                        <div className="bp-cover-label">Актуальное</div>
                        <h1 className="bp-cover-title">
                            Как типографика меняет восприятие текста
                        </h1>
                        <p className="bp-cover-sub">
                            От огромных заголовков до изящных буквиц — разбираем анатомию красивой журнальной статьи.
                        </p>
                    </div>
                </div>

                {/* ─── CONTENT AREA (Prose wrapper) ─── */}
                <Box p={{ base: '5', md: '8' }}>
                    {/* Мета: дата + теги (имитация ArticleShow) */}
                    <HStack gap="4" mb="6" flexWrap="wrap">
                        <Text fontSize="sm" color="fg.muted">
                            5 апреля 2026
                        </Text>
                        <HStack gap="2" flexWrap="wrap">
                            {['Типографика', 'Дизайн', 'Blueprint'].map((tag) => (
                                <Tag.Root key={tag} size="sm" variant="subtle" colorPalette="pecado">
                                    <Tag.Label>{tag}</Tag.Label>
                                </Tag.Root>
                            ))}
                        </HStack>
                    </HStack>

                    <Prose size="lg" maxW="none">

                        {/* ════════════════════════════════════
                            СЕКЦИЯ 1 — Буквица + Введение
                           ════════════════════════════════════ */}
                        <div className="bp-doodle-container">
                            {/* Декоративный SVG на левых полях */}
                            <svg className="bp-doodle bp-doodle-left" width="80" height="260" viewBox="0 0 80 260" fill="none">
                                <path d="M40 10 C20 40, 60 80, 30 120 C10 150, 50 180, 40 210 C35 230, 45 250, 40 260" stroke="#e8364f" strokeWidth="2" strokeDasharray="6 4" />
                                <circle cx="40" cy="10" r="5" fill="#e8364f" opacity="0.3" />
                                <circle cx="30" cy="120" r="4" fill="#e8364f" opacity="0.2" />
                                <circle cx="40" cy="210" r="6" fill="#e8364f" opacity="0.15" />
                                <path d="M15 50 Q25 45, 35 55 Q45 65, 55 50" stroke="#e8364f" strokeWidth="1.5" fill="none" />
                                <path d="M20 160 Q30 150, 45 160 Q55 170, 65 155" stroke="#e8364f" strokeWidth="1.5" fill="none" />
                            </svg>

                            <p className="bp-drop-cap">
                                Искусство оформления текста — это незаметный, но решающий элемент коммуникации между брендом и читателем.
                                Когда типографика работает правильно, человек <span className="bp-marker-yellow">не замечает шрифт — он погружается в содержание</span>.
                                От ширины строки до размера буквицы — каждая деталь формирует ритм чтения. Мы создали эту страницу
                                как эталонный образец всех типографических приёмов, которые используются в статьях Pecado.
                            </p>

                            <p>
                                Журнальная верстка в интернете всё чаще отходит от стандартных паттернов «простыни текста». Современные
                                издания активно комбинируют строгую газетную классику с яркими диджитал-акцентами. Массивные рубленые
                                заголовки контрастируют с элегантным текстом для чтения. Фотографии работают в симбиозе с текстом,
                                создавая единый <span className="bp-marker-green">визуальный нарратив</span>.
                            </p>
                        </div>

                        {/* ════════════════════════════════════
                            СЕКЦИЯ 2 — Pull Quote (Цитата-врезка)
                           ════════════════════════════════════ */}
                        <div className="bp-pull-quote">
                            «Типографика — это пространство между строками, в котором рождается смысл.
                            Хорошая вёрстка не кричит — она шепчет.»
                        </div>

                        {/* ════════════════════════════════════
                            СЕКЦИЯ 3 — Двухколоночный текст
                           ════════════════════════════════════ */}
                        <h2>Ритм и дыхание страницы</h2>

                        <div className="bp-two-col">
                            <p>
                                Ширина строки — критический параметр читабельности. Оптимальная длина составляет 60–80 символов.
                                Если строка длиннее, глаз устаёт прыгать с конца одной строки на начало следующей.
                                Поэтому мы ограничиваем ширину контейнера и используем многоколоночную вёрстку для больших объёмов текста.
                            </p>
                            <p>
                                Воздух вокруг заголовков — ещё один важный приём. Отступ перед подзаголовком должен быть больше,
                                чем после него. Это логически привязывает заголовок к следующему за ним тексту,
                                создавая визуальную иерархию и структуру.
                            </p>
                            <p>
                                <span className="bp-marker-pink">Шрифтовые пары — фундаментальный принцип.</span> Санс-сериф для акцентов и
                                заголовков, сериф для основного чтения. Контраст стилей создаёт динамику, разнообразие
                                и ритм, не перегружая при этом читателя.
                            </p>
                            <p>
                                Визуальные паузы — врезки, цитаты, фотографии — создают ритм,
                                который не даёт глазу устать. Искусство редактора — правильно
                                расставить эти паузы по тексту.
                            </p>
                        </div>

                        {/* ════════════════════════════════════
                            СЕКЦИЯ 4 — Боковая врезка-цитата
                           ════════════════════════════════════ */}
                        <div className="bp-side-pull">
                            Когда вёрстка идеальна, человек даже не осознаёт, почему ему так комфортно читать.
                        </div>

                        {/* ════════════════════════════════════
                            СЕКЦИЯ 5 — Фото-мозаика
                           ════════════════════════════════════ */}
                        <div className="bp-photo-mosaic">
                            <img className="span-rows" src="https://images.unsplash.com/photo-1494790108377-be9c29b29330?w=600&h=800&fit=crop&crop=face" alt="Портрет 1" />
                            <img src="https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=400&h=400&fit=crop&crop=face" alt="Портрет 2" />
                            <img src="https://images.unsplash.com/photo-1517841905240-472988babdf9?w=400&h=400&fit=crop&crop=face" alt="Портрет 3" />
                        </div>
                        <p style={{ fontFamily: "'Inter', sans-serif", fontSize: '0.7rem', color: '#aaa', marginTop: -20 }}>
                            Фото: архив редакции Pecado · Стилизованная фото-мозаика для визуальных материалов
                        </p>

                        {/* ════════════════════════════════════
                            СЕКЦИЯ 6 — PRODUCT CARD INSERT (Товары)
                           ════════════════════════════════════ */}
                        <div className="not-prose">
                            <div className="bp-product-insert">
                                <div className="bp-product-insert-title">Товары из статьи</div>
                                <div className="bp-product-grid">
                                    {[
                                        {
                                            img: 'https://images.unsplash.com/photo-1596462502278-27bfdc403348?w=300&h=400&fit=crop',
                                            brand: 'LELO',
                                            name: 'Sona™ 2 Cruise — Sonic Clitoral Massager',
                                            price: '12 490 ₽',
                                        },
                                        {
                                            img: 'https://images.unsplash.com/photo-1571781926291-c477ebfd024b?w=300&h=400&fit=crop',
                                            brand: 'Satisfyer',
                                            name: 'Pro 2 — Next Generation Pressure Wave',
                                            price: '4 990 ₽',
                                        },
                                        {
                                            img: 'https://images.unsplash.com/photo-1556228578-0d85b1a4d571?w=300&h=400&fit=crop',
                                            brand: 'We-Vibe',
                                            name: 'Sync Lite — App Control Couples Vibrator',
                                            price: '8 990 ₽',
                                        },
                                    ].map((p, i) => (
                                        <div key={i} className="bp-product-card">
                                            <img src={p.img} alt={p.name} />
                                            <div className="bp-product-card-info">
                                                <div className="bp-product-card-brand">{p.brand}</div>
                                                <div className="bp-product-card-name">{p.name}</div>
                                                <div className="bp-product-card-price">{p.price}</div>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </div>

                        {/* ════════════════════════════════════
                            СЕКЦИЯ 7 — Info Box (Врезка с фактом)
                           ════════════════════════════════════ */}
                        <div className="bp-info-box not-prose">
                            <div className="bp-info-icon">💡</div>
                            <div className="bp-info-title">Знаете ли вы?</div>
                            <div className="bp-info-text">
                                Первый печатный шрифт был создан Иоганном Гутенбергом в 1440 году. С тех пор типографика прошла
                                путь от свинцовых литер до цифровых variable fonts, которые могут бесконечно варьировать
                                толщину, ширину и наклон прямо в браузере.
                            </div>
                        </div>

                        {/* ════════════════════════════════════
                            СЕКЦИЯ 8 — Маркерные выделения в тексте
                           ════════════════════════════════════ */}
                        <h2>Маркерные выделения и акценты</h2>

                        <div className="bp-doodle-container">
                            {/* Декоративные зарисовки справа */}
                            <svg className="bp-doodle bp-doodle-right" width="90" height="200" viewBox="0 0 90 200" fill="none">
                                <path d="M45 5 L50 20 L55 8 L60 25 L65 12 L70 30" stroke="#e8364f" strokeWidth="1.5" fill="none" strokeLinecap="round" />
                                <rect x="20" y="50" width="50" height="50" rx="8" stroke="#e8364f" strokeWidth="1" strokeDasharray="4 3" fill="none" />
                                <circle cx="45" cy="75" r="10" stroke="#e8364f" strokeWidth="1" fill="none" />
                                <path d="M10 130 Q30 110, 50 130 Q70 150, 85 125" stroke="#e8364f" strokeWidth="1.5" fill="none" />
                                <path d="M20 165 L30 160 L25 175 L40 168 L35 182" stroke="#e8364f" strokeWidth="1" fill="none" strokeLinecap="round" />
                                <circle cx="60" cy="180" r="3" fill="#e8364f" opacity="0.2" />
                                <circle cx="75" cy="170" r="2" fill="#e8364f" opacity="0.15" />
                            </svg>

                            <p>
                                Маркерные выделения — мощный инструмент привлечения внимания. <span className="bp-marker-yellow">Жёлтый маркер
                                имитирует живое выделение текстовыделителем</span> — это самый классический вариант. Он мягкий,
                                привычный и не кричит.
                            </p>
                            <p>
                                <span className="bp-marker-pink">Розовый маркер добавляет эмоциональный акцент</span> — он подчёркивает
                                что-то важное, слегка провокационное.
                                А <span className="bp-marker-green">зелёный маркер используется для позитивных моментов</span>,
                                полезных советов и рекомендаций.
                            </p>
                            <p>
                                Все три варианта несимметричны — края мазка специально размыты, чтобы создать
                                ощущение небрежного, живого выделения карандашом. Это не просто
                                <code>background-color: yellow</code>, а приём из мира скетч-нотов.
                            </p>
                        </div>

                        <div className="bp-handwritten">← обратите внимание на небрежность мазка!</div>

                        {/* ════════════════════════════════════
                            СЕКЦИЯ 9 — Нумерованный список (Перечисление)
                           ════════════════════════════════════ */}
                        <h2>5 правил идеальной типографики</h2>

                        <div className="not-prose">
                            <ol className="bp-numbered-list">
                                <li>
                                    <span>
                                        <strong style={{ color: '#1a1a1a' }}>Контраст шрифтов.</strong>&nbsp;
                                        Используйте максимум 2-3 гарнитуры. Сочетайте сериф для чтения
                                        с гротеском для навигации и акцентов.
                                    </span>
                                </li>
                                <li>
                                    <span>
                                        <strong style={{ color: '#1a1a1a' }}>Ширина строки.</strong>&nbsp;
                                        60–80 символов — оптимальная длина строки. Более широкие строки утомляют,
                                        более узкие рвут ритм чтения.
                                    </span>
                                </li>
                                <li>
                                    <span>
                                        <strong style={{ color: '#1a1a1a' }}>Интерлиньяж.</strong>&nbsp;
                                        Межстрочный интервал не менее 150% от размера шрифта.
                                        Текст должен «дышать».
                                    </span>
                                </li>
                                <li>
                                    <span>
                                        <strong style={{ color: '#1a1a1a' }}>Визуальные паузы.</strong>&nbsp;
                                        Врезки, цитаты, фото — ритм создают не буквы, а пространство между блоками.
                                        Чередуйте плотный текст с воздушными элементами.
                                    </span>
                                </li>
                                <li>
                                    <span>
                                        <strong style={{ color: '#1a1a1a' }}>Единая система.</strong>&nbsp;
                                        Любой стиль работает, если последовательно применяется по всему
                                        изданию. Хаос — враг типографики.
                                    </span>
                                </li>
                            </ol>
                        </div>

                        {/* ════════════════════════════════════
                            СЕКЦИЯ 10 — Opinion Box (Мнение эксперта)
                           ════════════════════════════════════ */}
                        <div className="bp-opinion-box not-prose">
                            <div className="bp-opinion-tag">💬 Мнение эксперта</div>
                            <div className="bp-opinion-title">
                                Почему digital-издания выигрывают у печатных?
                            </div>
                            <p style={{ fontSize: '0.9rem', lineHeight: 1.7, color: '#555', margin: 0 }}>
                                Цифровая среда позволяет использовать приёмы, недоступные на бумаге:
                                интерактивные элементы, анимации, адаптивную сетку. Но главное преимущество —
                                <span className="bp-marker-yellow"> возможность персонализировать контент</span> под каждого читателя.
                                Размер шрифта, цветовая тема, ширина колонки — всё это может подстраиваться
                                под предпочтения пользователя.
                            </p>
                        </div>

                        {/* ════════════════════════════════════
                            СЕКЦИЯ 11 — Отзывы (Review Cards)
                           ════════════════════════════════════ */}
                        <h2>Что говорят наши клиенты</h2>

                        <div className="not-prose" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: 16 }}>
                            <div className="bp-review-card">
                                <div className="bp-review-stars">★★★★★</div>
                                <div className="bp-review-text">
                                    «Упаковка и презентация товара — на высшем уровне. Чувствуется внимание к деталям.
                                    Доставка была молниеносной, а ассортимент впечатляет своим разнообразием.»
                                </div>
                                <div className="bp-review-author">Алексей М. · Москва</div>
                            </div>
                            <div className="bp-review-card">
                                <div className="bp-review-stars">★★★★★</div>
                                <div className="bp-review-text">
                                    «Я пересмотрела несколько интернет-магазинов, прежде чем остановиться на Pecado.
                                    Здесь действительно чувствуется вкус и профессиональный подход к оформлению.»
                                </div>
                                <div className="bp-review-author">Елена К. · Санкт-Петербург</div>
                            </div>
                        </div>

                        {/* ════════════════════════════════════
                            СЕКЦИЯ 12 — Comparison Table
                           ════════════════════════════════════ */}
                        <h2>Сравнение моделей</h2>

                        <div className="bp-comparison not-prose">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Модель</th>
                                        <th>Тип</th>
                                        <th>Материал</th>
                                        <th>Рейтинг</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><strong>LELO Sona 2</strong></td>
                                        <td>Звуковой</td>
                                        <td>Силикон ABS</td>
                                        <td><span style={{ color: '#fbbf24' }}>★★★★★</span></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Satisfyer Pro 2</strong></td>
                                        <td>Вакуумно-волновой</td>
                                        <td>Медицинский силикон</td>
                                        <td><span style={{ color: '#fbbf24' }}>★★★★☆</span></td>
                                    </tr>
                                    <tr>
                                        <td><strong>We-Vibe Sync</strong></td>
                                        <td>Парный вибратор</td>
                                        <td>Силикон</td>
                                        <td><span style={{ color: '#fbbf24' }}>★★★★★</span></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        {/* ════════════════════════════════════
                            СЕКЦИЯ 13 — Ещё одна буквица + финальный текст
                           ════════════════════════════════════ */}
                        <h2>Искусство завершения</h2>

                        <div className="bp-doodle-container">
                            <svg className="bp-doodle bp-doodle-left" width="70" height="180" viewBox="0 0 70 180" fill="none">
                                <path d="M35 10 Q50 30, 35 50 Q20 70, 35 90 Q50 110, 35 130 Q20 150, 35 170" stroke="#e8364f" strokeWidth="1.5" fill="none" strokeDasharray="5 5" />
                                <circle cx="35" cy="10" r="4" fill="#e8364f" opacity="0.25" />
                                <circle cx="35" cy="90" r="6" fill="#e8364f" opacity="0.1" />
                                <circle cx="35" cy="170" r="4" fill="#e8364f" opacity="0.2" />
                                <path d="M10 40 C20 35, 30 45, 40 38 C50 31, 60 43, 65 36" stroke="#e8364f" strokeWidth="1" fill="none" />
                            </svg>

                            <p className="bp-drop-cap">
                                Завершение статьи — это не менее важный элемент, чем начало. Хорошая концовка
                                оставляет послевкусие. Она не обрывается, а плавно сворачивается, как лента,
                                давая читателю ощущение завершённости. Используйте эндмарк — короткую декоративную
                                подпись — чтобы визуально обозначить конец материала.
                            </p>

                            <p>
                                Pecado Editorial определяет визуальный стандарт контента для всей платформы.
                                Каждый элемент — от выбора гарнитуры до межбуквенного расстояния — подчинён единой
                                цели: <span className="bp-marker-yellow">читабельности и эстетике</span>.
                                Мы используем ограниченную палитру — два-три шрифта, продуманную цветовую схему
                                и <span className="bp-marker-green">много воздуха</span>. Это создаёт ощущение premium-класса
                                и уважения к читателю.
                            </p>
                        </div>

                        {/* ════════════════════════════════════
                            СЕКЦИЯ 14 — Финальная врезка товаров (горизонтальная)
                           ════════════════════════════════════ */}
                        <div className="not-prose">
                            <div className="bp-product-insert" style={{ borderRadius: 10 }}>
                                <div className="bp-product-insert-title">Рекомендуем к прочтению</div>
                                <div className="bp-product-grid">
                                    {[
                                        {
                                            img: 'https://images.unsplash.com/photo-1529626455594-4ff0802cfb7e?w=300&h=400&fit=crop&crop=face',
                                            brand: 'Fun Factory',
                                            name: 'Stronic Surf — Pulsating Vibrator',
                                            price: '9 490 ₽',
                                        },
                                        {
                                            img: 'https://images.unsplash.com/photo-1524504388940-b1c1722653e1?w=300&h=400&fit=crop&crop=face',
                                            brand: 'Womanizer',
                                            name: 'Premium 2 — Pleasure Air Technology',
                                            price: '14 990 ₽',
                                        },
                                        {
                                            img: 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=300&h=400&fit=crop&crop=face',
                                            brand: 'LELO',
                                            name: 'Hugo™ 2 — Remote Prostate Massager',
                                            price: '18 990 ₽',
                                        },
                                    ].map((p, i) => (
                                        <div key={i} className="bp-product-card">
                                            <img src={p.img} alt={p.name} />
                                            <div className="bp-product-card-info">
                                                <div className="bp-product-card-brand">{p.brand}</div>
                                                <div className="bp-product-card-name">{p.name}</div>
                                                <div className="bp-product-card-price">{p.price}</div>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </div>

                        {/* ════════════════════════════════════
                            ENDMARK
                           ════════════════════════════════════ */}
                        <div className="bp-endmark">
                            Текст подготовлен отделом контента Pecado · Апрель 2026
                        </div>

                        <hr />

                    </Prose>
                </Box>
            </Box>
        </UserLayout>
    );
}
