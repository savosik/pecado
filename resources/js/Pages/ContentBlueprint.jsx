import { Head } from '@inertiajs/react';
import UserLayout from './User/UserLayout';

/**
 * Blueprint-страница контентной типографики.
 *
 * Стиль: классический печатный журнал (digital magazine).
 * Чёрно-белая палитра, серифная типографика, многоколоночная вёрстка,
 * буквицы, pull-quotes, фото-сетки, нумерация страниц.
 */
export default function ContentBlueprint() {
    return (
        <UserLayout>
            <Head title="Типографика: Журнальный стиль" />

            <style>{`
                @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;0,900;1,400;1,700;1,900&family=Source+Serif+4:ital,wght@0,300;0,400;0,600;1,300;1,400;1,600&family=Inter:wght@300;400;500;600;700&display=swap');

                .mag-wrap {
                    max-width: 900px;
                    margin: 0 auto;
                    font-family: 'Source Serif 4', 'Georgia', serif;
                    color: #1a1a1a;
                }

                /* ─── MAGAZINE PAGE (карточка-страница) ─── */
                .mag-page {
                    background: #fff;
                    margin-bottom: 32px;
                    box-shadow: 0 2px 20px rgba(0,0,0,0.06);
                    overflow: hidden;
                    position: relative;
                }

                /* ─── HEADER / INTRO ─── */
                .mag-service-header {
                    text-align: center;
                    padding: 48px 24px 32px;
                }
                .mag-service-header h1 {
                    font-family: 'Playfair Display', serif;
                    font-size: 2.4rem;
                    font-weight: 900;
                    letter-spacing: -0.02em;
                    text-transform: uppercase;
                    margin: 0 0 8px;
                }
                .mag-service-header p {
                    font-family: 'Inter', sans-serif;
                    font-size: 0.9rem;
                    color: #888;
                    margin: 0;
                }

                /* ─── PAGE FOOTER (номер страницы + линия) ─── */
                .mag-page-footer {
                    display: flex;
                    align-items: center;
                    gap: 12px;
                    padding: 20px 40px 28px;
                    font-family: 'Inter', sans-serif;
                    font-size: 0.8rem;
                    font-weight: 600;
                    color: #1a1a1a;
                }
                .mag-page-footer::after {
                    content: '';
                    flex: 1;
                    height: 1.5px;
                    background: #1a1a1a;
                }
                .mag-page-footer.right {
                    flex-direction: row-reverse;
                }
                .mag-page-footer.right::after {
                    /* same, just reversed */
                }

                /* ─── BYLINE ─── */
                .mag-byline {
                    font-family: 'Inter', sans-serif;
                    font-size: 0.7rem;
                    font-weight: 600;
                    text-transform: uppercase;
                    letter-spacing: 0.08em;
                    color: #888;
                    margin-bottom: 8px;
                }

                /* ─── COVER (Page 1 — обложка) ─── */
                .mag-cover {
                    position: relative;
                    min-height: 520px;
                    display: flex;
                    flex-direction: column;
                }
                .mag-cover-image {
                    position: absolute;
                    inset: 0;
                    z-index: 0;
                }
                .mag-cover-image img {
                    width: 100%;
                    height: 100%;
                    object-fit: cover;
                }
                .mag-cover-content {
                    position: relative;
                    z-index: 1;
                    padding: 48px 40px;
                    display: flex;
                    flex-direction: column;
                    justify-content: flex-end;
                    flex: 1;
                    background: linear-gradient(to top, rgba(0,0,0,0.75) 0%, rgba(0,0,0,0.1) 60%, transparent 100%);
                }
                .mag-cover-label {
                    font-family: 'Inter', sans-serif;
                    font-size: 0.65rem;
                    font-weight: 700;
                    text-transform: uppercase;
                    letter-spacing: 0.15em;
                    color: #fff;
                    background: #1a1a1a;
                    display: inline-block;
                    padding: 6px 14px;
                    margin-bottom: 16px;
                    width: fit-content;
                }
                .mag-cover h1 {
                    font-family: 'Playfair Display', serif;
                    font-weight: 900;
                    font-size: 3rem;
                    line-height: 1.05;
                    color: #fff;
                    margin: 0 0 16px;
                    max-width: 500px;
                }
                .mag-cover .mag-subtitle {
                    font-family: 'Source Serif 4', serif;
                    font-size: 1.05rem;
                    color: rgba(255,255,255,0.8);
                    line-height: 1.5;
                    max-width: 400px;
                    margin: 0;
                }
                .mag-cover .mag-date {
                    font-family: 'Inter', sans-serif;
                    font-size: 0.75rem;
                    color: rgba(255,255,255,0.5);
                    margin-top: 24px;
                }

                /* ─── SPREAD: 2-column hero (Page 2) ─── */
                .mag-spread-hero {
                    display: grid;
                    grid-template-columns: 1fr 1fr;
                }
                @media (max-width: 640px) {
                    .mag-spread-hero {
                        grid-template-columns: 1fr;
                    }
                }
                .mag-spread-hero .hero-img img {
                    width: 100%;
                    height: 100%;
                    object-fit: cover;
                    display: block;
                }
                .mag-spread-hero .hero-text {
                    padding: 40px;
                    display: flex;
                    flex-direction: column;
                    justify-content: center;
                }
                .mag-spread-hero .hero-text h2 {
                    font-family: 'Playfair Display', serif;
                    font-weight: 900;
                    font-style: italic;
                    font-size: 2rem;
                    line-height: 1.15;
                    margin: 0 0 20px;
                    color: #1a1a1a;
                }
                .mag-spread-hero .hero-text p {
                    font-size: 0.88rem;
                    line-height: 1.7;
                    color: #444;
                    margin: 0 0 8px;
                }

                /* ─── TITLE PAGE (digital magazine — Page 3) ─── */
                .mag-title-page {
                    padding: 60px 40px;
                    display: flex;
                    flex-direction: column;
                    justify-content: flex-end;
                    min-height: 400px;
                }
                .mag-title-page h1 {
                    font-family: 'Playfair Display', serif;
                    font-size: 4.5rem;
                    font-weight: 400;
                    font-style: italic;
                    line-height: 1.0;
                    margin: 0;
                    color: #1a1a1a;
                }
                .mag-title-page .mag-title-sub {
                    font-family: 'Source Serif 4', serif;
                    font-size: 0.85rem;
                    color: #888;
                    line-height: 1.6;
                    max-width: 320px;
                    margin-top: 20px;
                }
                .mag-title-page .mag-title-date {
                    font-family: 'Inter', sans-serif;
                    font-size: 0.7rem;
                    color: #bbb;
                    margin-top: 12px;
                    letter-spacing: 0.05em;
                }

                /* ─── ARTICLE BODY (2-col text) ─── */
                .mag-article-body {
                    padding: 40px;
                }
                .mag-two-col {
                    column-count: 2;
                    column-gap: 36px;
                    font-size: 0.88rem;
                    line-height: 1.75;
                    color: #333;
                }
                @media (max-width: 640px) {
                    .mag-two-col {
                        column-count: 1;
                    }
                }
                .mag-two-col p {
                    margin: 0 0 14px;
                    text-align: justify;
                }

                /* ─── DROP CAP ─── */
                .mag-drop-cap::first-letter {
                    font-family: 'Playfair Display', serif;
                    font-size: 4.2em;
                    font-weight: 900;
                    float: left;
                    line-height: 0.78;
                    margin-right: 10px;
                    margin-top: 6px;
                    color: #1a1a1a;
                }

                /* ─── PULL QUOTE ─── */
                .mag-pull-quote {
                    font-family: 'Playfair Display', serif;
                    font-style: italic;
                    font-size: 1.8rem;
                    line-height: 1.25;
                    color: #1a1a1a;
                    text-align: center;
                    padding: 32px 20px;
                    margin: 24px 0;
                    border-top: 2px solid #1a1a1a;
                    border-bottom: 2px solid #1a1a1a;
                }

                /* ─── INLINE PULL (в колонке) ─── */
                .mag-inline-pull {
                    font-family: 'Playfair Display', serif;
                    font-style: italic;
                    font-weight: 700;
                    font-size: 1.6rem;
                    line-height: 1.2;
                    color: #1a1a1a;
                    margin: 20px 0;
                    break-inside: avoid;
                }

                /* ─── NEXT PAGE BUTTON ─── */
                .mag-next-btn {
                    display: inline-block;
                    font-family: 'Inter', sans-serif;
                    font-size: 0.7rem;
                    font-weight: 600;
                    text-transform: lowercase;
                    letter-spacing: 0.05em;
                    padding: 8px 20px;
                    border: 1.5px solid #1a1a1a;
                    color: #1a1a1a;
                    background: transparent;
                    cursor: pointer;
                    transition: all 0.2s;
                    text-decoration: none;
                }
                .mag-next-btn:hover {
                    background: #1a1a1a;
                    color: #fff;
                }

                /* ─── PHOTO GRID ─── */
                .mag-photo-grid {
                    display: grid;
                    grid-template-columns: 1fr 1fr;
                    gap: 4px;
                }
                .mag-photo-grid img {
                    width: 100%;
                    height: 100%;
                    object-fit: cover;
                    display: block;
                }
                .mag-photo-grid .span-2 {
                    grid-column: span 2;
                }

                /* ─── SIDEBAR ARTICLE (text + image side by side) ─── */
                .mag-sidebar-layout {
                    display: grid;
                    grid-template-columns: 1fr 1fr;
                    gap: 0;
                }
                @media (max-width: 640px) {
                    .mag-sidebar-layout {
                        grid-template-columns: 1fr;
                    }
                }
                .mag-sidebar-layout .side-text {
                    padding: 40px;
                    display: flex;
                    flex-direction: column;
                    justify-content: center;
                }
                .mag-sidebar-layout .side-img img {
                    width: 100%;
                    height: 100%;
                    object-fit: cover;
                    display: block;
                }

                /* ─── BIG SERIF HEADLINE (для широких заголовков) ─── */
                .mag-big-headline {
                    font-family: 'Playfair Display', serif;
                    font-weight: 900;
                    font-size: 2.8rem;
                    line-height: 1.05;
                    margin: 0 0 24px;
                    color: #1a1a1a;
                }

                /* ─── CAPTION ─── */
                .mag-caption {
                    font-family: 'Inter', sans-serif;
                    font-size: 0.65rem;
                    color: #aaa;
                    line-height: 1.5;
                    margin-top: 8px;
                }

                /* ─── DIVIDER ─── */
                .mag-divider {
                    width: 60px;
                    height: 1.5px;
                    background: #1a1a1a;
                    margin: 20px 0;
                }

                /* ─── LARGE DROP HEADLINE ─── */
                .mag-drop-headline {
                    font-family: 'Playfair Display', serif;
                    font-weight: 900;
                    font-size: 3.2rem;
                    line-height: 1.0;
                    color: #1a1a1a;
                    margin: 0 0 20px;
                    hyphens: auto;
                }
            `}</style>

            {/* Служебный заголовок */}
            <div className="mag-service-header">
                <h1>Content Blueprint</h1>
                <p>Эталонная страница · Журнальная типографика</p>
            </div>

            <div className="mag-wrap">

                {/* ═══════════════════════════════════════════════
                    PAGE 1 — Обложка
                    ═══════════════════════════════════════════════ */}
                <div className="mag-page">
                    <div className="mag-cover">
                        <div className="mag-cover-image">
                            <img src="https://images.unsplash.com/photo-1531746020798-e6953c6e8e04?w=900&h=600&fit=crop&crop=face" alt="Обложка" />
                        </div>
                        <div className="mag-cover-content">
                            <div className="mag-cover-label">Актуальное</div>
                            <h1>Как типографика меняет восприятие текста</h1>
                            <p className="mag-subtitle">
                                От огромных заголовков до изящных буквиц — разбираем   анатомию красивой журнальной статьи.
                            </p>
                            <div className="mag-date">Апрель 2026</div>
                        </div>
                    </div>
                </div>

                {/* ═══════════════════════════════════════════════
                    PAGE 2 — Hero Spread (фото + заголовок)
                    ═══════════════════════════════════════════════ */}
                <div className="mag-page">
                    <div className="mag-spread-hero">
                        <div className="hero-img">
                            <img src="https://images.unsplash.com/photo-1494790108377-be9c29b29330?w=500&h=620&fit=crop&crop=face" alt="Портрет" />
                        </div>
                        <div className="hero-text">
                            <div className="mag-byline">Статья: Pecado Editorial</div>
                            <h2>Rion plaute nist, incidibus pore eum ratemquam, nate cum apiendit mi—</h2>
                            <p>
                                Журнальная верстка в интернете всё чаще отходит от стандартных паттернов «простыни текста». Современные издания активно комбинируют строгую газетную классику с яркими диджитал-акцентами. Массивные рубленые заголовки контрастируют с элегантным текстом для чтения.
                            </p>
                            <p>
                                Искусство типографики — это не просто выбор шрифта. Это создание ритма, дыхания, пауз. Каждый элемент на странице работает на единую цель: передать смысл максимально точно.
                            </p>
                            <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginTop: 20 }}>
                                <span className="mag-next-btn">следующая</span>
                            </div>
                        </div>
                    </div>
                    <div className="mag-page-footer">01</div>
                </div>

                {/* ═══════════════════════════════════════════════
                    PAGE 3 — Title Page (digital magazine)
                    ═══════════════════════════════════════════════ */}
                <div className="mag-page">
                    <div className="mag-title-page">
                        <h1>digital<br/>magazine</h1>
                        <div className="mag-title-sub">
                            Pecado Editorial — эталонный стиль оформления контента для бренда. Каждая деталь продумана до мелочей.
                        </div>
                        <div className="mag-title-date">Апрель 2026</div>
                    </div>
                </div>

                {/* ═══════════════════════════════════════════════
                    PAGE 4 — Article with Drop Cap + 2 columns
                    ═══════════════════════════════════════════════ */}
                <div className="mag-page">
                    <div className="mag-article-body">
                        <div className="mag-byline">Статья: Pecado Editorial</div>
                        <div className="mag-two-col">
                            <p className="mag-drop-cap">
                                Единственная цель хорошей типографики — сделать чтение комфортным. Всё остальное — визуальные паузы, акценты, буквицы — лишь инструменты, помогающие глазу и мозгу структурировать информацию. Ритм статьи создаётся за счёт чередования параграфов, подзаголовков и фотографий.
                            </p>
                            <p>
                                Ширина строки — критический параметр. Оптимально 60-80 символов. Если строка длиннее, глаз устаёт прыгать с конца одной строки на начало следующей. Поэтому мы ограничиваем ширину контейнера и используем многоколоночную вёрстку для больших объёмов текста.
                            </p>

                            <div className="mag-inline-pull">
                                «Num et verum acides et rese-quas tisquam»
                            </div>

                            <p>
                                Воздух вокруг заголовков — ещё один важный приём. Отступ перед подзаголовком должен быть больше, чем после него. Это логически привязывает заголовок к следующему за ним тексту, создавая визуальную иерархию.
                            </p>
                            <p>
                                Шрифтовые пары — фундаментальный принцип. Санс-сериф для акцентов и заголовков, Сериф для основного чтения на длинных дистанциях. Контраст стилей создаёт динамику, разнообразие и ритм, не перегружая читателя.
                            </p>
                        </div>

                        <div className="mag-divider" style={{ margin: '28px 0' }}></div>
                        <div className="mag-byline">Автор: Pecado Editorial · Фото: архив редакции</div>
                        <div style={{ marginTop: 16 }}>
                            <span className="mag-next-btn">следующая</span>
                        </div>
                    </div>
                    <div className="mag-page-footer">02</div>
                </div>

                {/* ═══════════════════════════════════════════════
                    PAGE 5 — Big Headline + 2-col text + image
                    ═══════════════════════════════════════════════ */}
                <div className="mag-page">
                    <div className="mag-sidebar-layout">
                        <div className="side-text">
                            <div className="mag-byline">Статья: Pecado Editorial</div>
                            <div className="mag-drop-headline">
                                Parum expla-<br/>bo reiumquae nim essita nis dolorum sita-<br/>tet labor vel elest inihill ac-<br/>caborum.
                            </div>

                            <p style={{ fontSize: '0.85rem', lineHeight: 1.7, color: '#444', marginBottom: 16 }}>
                                Фотографии в журнале — не просто заполнение пустоты. Они работают в симбиозе с текстом, создавая единый визуальный нарратив. Мы даём им правильные пропорции и сопровождаем аккуратной подписью.
                            </p>

                            <div style={{ marginTop: 16 }}>
                                <span className="mag-next-btn">следующая</span>
                            </div>
                        </div>
                        <div className="side-img">
                            <img src="https://images.unsplash.com/photo-1524504388940-b1c1722653e1?w=460&h=700&fit=crop&crop=face" alt="Портрет" />
                        </div>
                    </div>

                    <div style={{ padding: '0 40px 20px', display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 24 }}>
                        <div>
                            <p style={{ fontSize: '0.82rem', lineHeight: 1.7, color: '#444', textAlign: 'justify' }}>
                                Для чтения длинных текстов предпочтительны шрифты с засечками. Межстрочный интервал должен составлять не менее 150% от размера шрифта.
                            </p>
                        </div>
                        <div>
                            <p style={{ fontSize: '0.82rem', lineHeight: 1.7, color: '#444', textAlign: 'justify' }}>
                                Типографика — невидимый проводник между автором и читателем. Когда вёрстка идеальна, человек даже не осознаёт, почему ему так комфортно.
                            </p>
                        </div>
                    </div>
                    <div className="mag-page-footer">03</div>
                </div>

                {/* ═══════════════════════════════════════════════
                    PAGE 6 — Right-side article with pull quote
                    ═══════════════════════════════════════════════ */}
                <div className="mag-page">
                    <div className="mag-sidebar-layout">
                        <div className="side-text" style={{ padding: '40px 40px 20px' }}>
                            <div className="mag-byline">Статья: Pecado Editorial</div>
                            <p style={{ fontSize: '0.85rem', lineHeight: 1.75, color: '#444', fontStyle: 'italic' }}>
                                Pecado Editorial определяет визуальный стандарт контента для всей платформы. Каждый элемент — от выбора гарнитуры до inter-word спейсинга — подчинён единой цели: читабельности и эстетике.
                            </p>

                            <div className="mag-pull-quote" style={{ textAlign: 'left', padding: '24px 0', borderTop: 'none', borderBottom: 'none', borderLeft: '3px solid #1a1a1a', paddingLeft: 20 }}>
                                «Num et verum acides et rese-quas tisquam»
                            </div>

                            <p style={{ fontSize: '0.82rem', lineHeight: 1.7, color: '#444' }}>
                                Визуальные паузы необходимы при чтении больших объёмов текста. Врезки, цитаты и фотографии создают ритм, который не даёт глазу устать.
                            </p>
                            <div style={{ marginTop: 16 }}>
                                <span className="mag-next-btn">следующая</span>
                            </div>
                        </div>
                        <div style={{ padding: 40, display: 'flex', flexDirection: 'column', justifyContent: 'center' }}>
                            <div className="mag-two-col" style={{ columnCount: 1 }}>
                                <p style={{ fontSize: '0.82rem', lineHeight: 1.7, color: '#444', textAlign: 'justify' }}>
                                    Современные издания активно экспериментируют с форматами. Длинные лонгриды, интерактивные истории, мультимедийные проекты — всё это требует продуманной типографической системы. Без неё даже самый качественный контент теряет силу.
                                </p>
                                <p style={{ fontSize: '0.82rem', lineHeight: 1.7, color: '#444', textAlign: 'justify' }}>
                                    Мы используем ограниченную палитру: два-три шрифта, монохромную цветовую схему и много воздуха. Это создаёт ощущение premium-класса и уважения к читателю.
                                </p>
                            </div>
                        </div>
                    </div>
                    <div className="mag-page-footer right">04</div>
                </div>

                {/* ═══════════════════════════════════════════════
                    PAGE 7 — Photo Grid + Title
                    ═══════════════════════════════════════════════ */}
                <div className="mag-page">
                    <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 0 }}>
                        <div style={{ padding: '40px 40px 20px', display: 'flex', flexDirection: 'column', justifyContent: 'flex-end' }}>
                            <div style={{ fontFamily: "'Inter', sans-serif", fontSize: '0.7rem', color: '#bbb', marginBottom: 8, letterSpacing: '0.05em' }}>Апрель 2026</div>
                            <div style={{ fontFamily: "'Playfair Display', serif", fontSize: '3.5rem', fontWeight: 400, fontStyle: 'italic', lineHeight: 1.0, color: '#1a1a1a' }}>
                                magazine<br/>name
                            </div>
                        </div>
                        <div>
                            <img
                                src="https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=460&h=500&fit=crop&crop=face"
                                alt="Портрет"
                                style={{ width: '100%', height: '100%', objectFit: 'cover', display: 'block' }}
                            />
                        </div>
                    </div>
                    <div className="mag-photo-grid" style={{ padding: '4px 0 0' }}>
                        <div>
                            <img src="https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=460&h=300&fit=crop&crop=face" alt="Фото 1" />
                        </div>
                        <div>
                            <img src="https://images.unsplash.com/photo-1517841905240-472988babdf9?w=460&h=300&fit=crop&crop=face" alt="Фото 2" />
                        </div>
                    </div>
                    <div style={{ padding: '12px 40px 8px' }}>
                        <div className="mag-caption">
                            Эталонная сетка изображений для визуальных материалов. Соотношение сторон и кадрирование определяются гайдлайном Pecado Editorial.
                        </div>
                    </div>
                    <div className="mag-page-footer right">05</div>
                </div>

                {/* ═══════════════════════════════════════════════
                    PAGE 8 — Резюме / подвал
                    ═══════════════════════════════════════════════ */}
                <div className="mag-page">
                    <div style={{ padding: '60px 40px', textAlign: 'center' }}>
                        <div className="mag-divider" style={{ margin: '0 auto 32px' }}></div>
                        <div style={{ fontFamily: "'Playfair Display', serif", fontSize: '1.6rem', fontStyle: 'italic', color: '#1a1a1a', marginBottom: 16 }}>
                            Текст подготовлен отделом контента Pecado.
                        </div>
                        <div style={{ fontFamily: "'Inter', sans-serif", fontSize: '0.75rem', color: '#aaa', letterSpacing: '0.05em' }}>
                            pecado editorial · апрель 2026
                        </div>
                        <div className="mag-divider" style={{ margin: '32px auto 0' }}></div>
                    </div>
                </div>

            </div>
        </UserLayout>
    );
}
