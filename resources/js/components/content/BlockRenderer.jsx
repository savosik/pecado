import '../../../css/blocks.css';

import { useState, useEffect, useRef } from 'react';
import { Link } from '@inertiajs/react';
import { Box } from '@chakra-ui/react';
import ProductCard from '@/components/product/ProductCard';

/**
 * BlockRenderer — рендерер JSON-блоков контента.
 * Принимает массив блоков из Editor.js и рендерит каждый
 * соответствующим React-компонентом с чистыми CSS-стилями.
 */
export default function BlockRenderer({ blocks = [] }) {
    if (!blocks || !blocks.length) return null;

    return (
        <div className="content-blocks">
            {blocks.map((block, i) => (
                <Block key={`${block.type}-${i}`} block={block} />
            ))}
        </div>
    );
}

function Block({ block }) {
    switch (block.type) {
        case 'header':       return <HeaderBlock data={block.data} />;
        case 'paragraph':    return <ParagraphBlock data={block.data} />;
        case 'image':        return <ImageBlock data={block.data} />;
        case 'list':         return <ListBlock data={block.data} />;
        case 'quote':        return <QuoteBlock data={block.data} />;
        case 'pullQuote':    return <PullQuoteBlock data={block.data} />;
        case 'delimiter':    return <DelimiterBlock />;
        case 'table':        return <TableBlock data={block.data} />;
        case 'warning':
        case 'infoBox':      return <InfoBoxBlock data={block.data} />;
        case 'embed':        return <EmbedBlock data={block.data} />;
        case 'imageText':    return <ImageTextBlock data={block.data} />;
        case 'columns':      return <ColumnsBlock data={block.data} />;
        case 'twoColumns':   return <ColumnsBlock data={{...block.data, layout: '2'}} />;
        case 'threeColumns': return <ColumnsBlock data={{...block.data, layout: '3'}} />;
        case 'productCarousel': return <ProductCarouselBlock data={block.data} />;
        case 'gallery':      return <GalleryBlock data={block.data} />;
        case 'faq':          return <FaqBlock data={block.data} />;
        case 'callToAction': return <CallToActionBlock data={block.data} />;
        case 'reviews':      return <ReviewsBlock data={block.data} />;
        case 'coverImage':   return <CoverImageBlock data={block.data} />;
        case 'numberedList': return <NumberedListBlock data={block.data} />;
        case 'opinionBox':   return <OpinionBoxBlock data={block.data} />;
        case 'endmark':      return <EndmarkBlock data={block.data} />;
        case 'photoMosaic':  return <PhotoMosaicBlock data={block.data} />;
        case 'twoColumnText': return <TwoColumnTextBlock data={block.data} />;
        case 'iconFeature':  return <IconFeatureBlock data={block.data} />;
        case 'countdown':    return <CountdownBlock data={block.data} />;
        case 'tabs':         return <TabsBlock data={block.data} />;
        case 'imageCarousel': return <ImageCarouselBlock data={block.data} />;
        case 'stats':        return <StatsBlock data={block.data} />;
        case 'logoWall':     return <LogoWallBlock data={block.data} />;
        case 'alertBanner':  return <AlertBannerBlock data={block.data} />;
        case 'spacer':       return <SpacerBlock data={block.data} />;
        case 'button':       return <ButtonBlock data={block.data} />;
        case 'steps':        return <StepsBlock data={block.data} />;
        case 'timeline':     return <TimelineBlock data={block.data} />;
        case 'beforeAfter':  return <BeforeAfterBlock data={block.data} />;
        case 'comparison':   return <ComparisonBlock data={block.data} />;
        case 'pricingTable': return <PricingTableBlock data={block.data} />;
        case 'map':          return <MapBlock data={block.data} />;
        default:             return null;
    }
}

/* ─────────────────────────────────────────
   Базовые блоки
   ───────────────────────────────────────── */

function HeaderBlock({ data }) {
    const Tag = `h${data.level || 2}`;
    return <Tag className={`cb-header cb-header--${data.level || 2}`}>{data.text}</Tag>;
}

function ParagraphBlock({ data }) {
    return (
        <p
            className={`cb-paragraph${data.dropCap ? ' cb-paragraph--drop-cap' : ''}`}
            dangerouslySetInnerHTML={{ __html: data.text }}
        />
    );
}

function ImageBlock({ data }) {
    const cls = [
        'cb-image',
        data.stretched && 'cb-image--stretched',
        data.withBorder && 'cb-image--bordered',
    ].filter(Boolean).join(' ');

    return (
        <figure className={cls}>
            <img
                className="cb-image__picture"
                src={data.url || data.file?.url}
                alt={data.alt || data.caption || ''}
                loading="lazy"
            />
            {data.caption && (
                <figcaption className="cb-image__caption">{data.caption}</figcaption>
            )}
        </figure>
    );
}

function ListBlock({ data }) {
    const Tag = data.style === 'ordered' ? 'ol' : 'ul';
    return (
        <Tag className={`cb-list cb-list--${data.style || 'unordered'}`}>
            {(data.items || []).map((item, i) => (
                <li key={i} dangerouslySetInnerHTML={{ __html: typeof item === 'string' ? item : item.content || '' }} />
            ))}
        </Tag>
    );
}

function QuoteBlock({ data }) {
    return (
        <blockquote className="cb-quote">
            <div className="cb-quote__text" dangerouslySetInnerHTML={{ __html: data.text }} />
            {data.caption && <cite className="cb-quote__caption">{data.caption}</cite>}
        </blockquote>
    );
}

function PullQuoteBlock({ data }) {
    return (
        <div className="cb-pull-quote">
            <div className="cb-pull-quote__text">{data.text}</div>
            {data.caption && <div className="cb-pull-quote__caption">{data.caption}</div>}
        </div>
    );
}

function DelimiterBlock() {
    return <div className="cb-delimiter">***</div>;
}

function TableBlock({ data }) {
    const rows = data.content || [];
    if (!rows.length) return null;

    return (
        <table className="cb-table">
            {data.withHeadings && rows.length > 0 && (
                <thead>
                    <tr>
                        {rows[0].map((cell, ci) => (
                            <th key={ci} dangerouslySetInnerHTML={{ __html: cell }} />
                        ))}
                    </tr>
                </thead>
            )}
            <tbody>
                {rows.slice(data.withHeadings ? 1 : 0).map((row, ri) => (
                    <tr key={ri}>
                        {row.map((cell, ci) => (
                            <td key={ci} dangerouslySetInnerHTML={{ __html: cell }} />
                        ))}
                    </tr>
                ))}
            </tbody>
        </table>
    );
}

function InfoBoxBlock({ data }) {
    return (
        <div className="cb-info-box">
            {data.title && <div className="cb-info-box__title">{data.title}</div>}
            <div className="cb-info-box__message" dangerouslySetInnerHTML={{ __html: data.message || data.text || '' }} />
        </div>
    );
}

function EmbedBlock({ data }) {
    let src = data.source || data.embed || '';
    // YouTube embed URL
    if (data.service === 'youtube' && src.includes('watch')) {
        const id = new URL(src).searchParams.get('v');
        if (id) src = `https://www.youtube.com/embed/${id}`;
    }

    return (
        <div className="cb-embed">
            <div className="cb-embed__wrapper">
                <iframe src={src} allowFullScreen loading="lazy" title={data.caption || 'Видео'} />
            </div>
            {data.caption && <div className="cb-embed__caption">{data.caption}</div>}
        </div>
    );
}

/* ─────────────────────────────────────────
   Кастомные блоки
   ───────────────────────────────────────── */

function ImageTextBlock({ data }) {
    const isRight = data.imagePosition === 'right';
    const imgSrc = data.imageUrl || data.image;
    return (
        <div className={`cb-image-text${isRight ? ' cb-image-text--reverse' : ''}`}>
            <img className="cb-image-text__image" src={imgSrc} alt={data.imageAlt || ''} loading="lazy" />
            <div className="cb-image-text__content">
                {data.title && <div className="cb-image-text__title">{data.title}</div>}
                <div className="cb-image-text__text" dangerouslySetInnerHTML={{ __html: data.text }} />
            </div>
        </div>
    );
}

function ColumnsBlock({ data }) {
    const count = data.layout || '2';
    return (
        <div className={`cb-columns cb-columns--${count}`}>
            {(data.columns || []).map((col, i) => {
                // Поддержка как строк (из Tool), так и объектов (из AI)
                const isString = typeof col === 'string';
                return (
                    <div key={i} className="cb-columns__item">
                        {!isString && col.icon && <div className="cb-columns__icon">{col.icon}</div>}
                        {!isString && col.title && <div className="cb-columns__title">{col.title}</div>}
                        <div
                            className="cb-columns__text"
                            dangerouslySetInnerHTML={{ __html: isString ? col : (col.text || '') }}
                        />
                    </div>
                );
            })}
        </div>
    );
}

function ProductCarouselBlock({ data }) {
    const [products, setProducts] = useState([]);

    useEffect(() => {
        if (!data.productIds?.length && !data.selectionSlug) return;

        const params = data.productIds?.length
            ? `?ids=${data.productIds.join(',')}`
            : `?selection=${data.selectionSlug}`;

        fetch(`/api/products/by-ids${params}`)
            .then(r => r.json())
            .then(res => setProducts(Array.isArray(res) ? res : res.data || []))
            .catch(() => {});
    }, [data.productIds, data.selectionSlug]);

    if (!products.length && !data.productIds?.length) return null;

    return (
        <div className="cb-products">
            {data.title && <div className="cb-products__title">{data.title}</div>}
            <div className="cb-products__grid">
                {products.map(product => (
                    <ProductCard key={product.id} product={product} />
                ))}
            </div>
        </div>
    );
}

function GalleryBlock({ data }) {
    const cols = data.columns || 3;
    return (
        <div className={`cb-gallery cb-gallery--${cols}`}>
            {(data.images || []).map((img, i) => (
                <div key={i} className="cb-gallery__item">
                    <img className="cb-gallery__image" src={img.url} alt={img.alt || ''} loading="lazy" />
                    {img.caption && <div className="cb-gallery__caption">{img.caption}</div>}
                </div>
            ))}
        </div>
    );
}

function FaqBlock({ data }) {
    const [openIndex, setOpenIndex] = useState(null);

    return (
        <div className="cb-faq">
            {(data.items || []).map((item, i) => (
                <div key={i} className={`cb-faq__item${openIndex === i ? ' cb-faq__item--open' : ''}`}>
                    <button
                        className="cb-faq__question"
                        onClick={() => setOpenIndex(openIndex === i ? null : i)}
                    >
                        <span>{item.question}</span>
                        <span className="cb-faq__chevron">▼</span>
                    </button>
                    {openIndex === i && (
                        <div className="cb-faq__answer" dangerouslySetInnerHTML={{ __html: item.answer }} />
                    )}
                </div>
            ))}
        </div>
    );
}

function CallToActionBlock({ data }) {
    return (
        <div className={`cb-cta${data.style === 'gradient' ? ' cb-cta--gradient' : ''}`}>
            {data.title && <div className="cb-cta__title">{data.title}</div>}
            {data.text && <div className="cb-cta__text">{data.text}</div>}
            {data.buttonText && (
                <Link href={data.buttonUrl || '#'} className="cb-cta__button">
                    {data.buttonText}
                </Link>
            )}
        </div>
    );
}

function ReviewsBlock({ data }) {
    return (
        <div className="cb-reviews">
            {(data.items || []).map((review, i) => {
                const stars = review.rating || review.stars || 5;
                return (
                    <div key={i} className="cb-review">
                        <div className="cb-review__stars">
                            {'★'.repeat(stars)}{'☆'.repeat(5 - stars)}
                        </div>
                        <div className="cb-review__text" dangerouslySetInnerHTML={{ __html: review.text }} />
                        <div className="cb-review__author">
                            {review.author}{review.city ? ` · ${review.city}` : ''}
                        </div>
                    </div>
                );
            })}
        </div>
    );
}

function CoverImageBlock({ data }) {
    const imgSrc = data.url || data.image;
    return (
        <div className="cb-cover">
            <img className="cb-cover__image" src={imgSrc} alt={data.title || ''} />
            <div className="cb-cover__overlay" style={data.overlayColor ? {backgroundColor: data.overlayColor} : {}}>
                {data.label && <div className="cb-cover__label">{data.label}</div>}
                {data.title && <h1 className="cb-cover__title">{data.title}</h1>}
                {data.subtitle && <p className="cb-cover__subtitle">{data.subtitle}</p>}
            </div>
        </div>
    );
}

function NumberedListBlock({ data }) {
    return (
        <ol className="cb-numbered-list">
            {(data.items || []).map((item, i) => (
                <li key={i} className="cb-numbered-list__item">
                    <div>
                        {item.title && <div className="cb-numbered-list__title">{item.title}</div>}
                        <div className="cb-numbered-list__text">{item.text}</div>
                    </div>
                </li>
            ))}
        </ol>
    );
}

function OpinionBoxBlock({ data }) {
    return (
        <div className="cb-opinion">
            <div className="cb-opinion__header">
                {data.photo && <img className="cb-opinion__photo" src={data.photo} alt={data.name || ''} />}
                <div>
                    {data.name && <div className="cb-opinion__name">{data.name}</div>}
                    {data.title && <div className="cb-opinion__title">{data.title}</div>}
                </div>
            </div>
            <div className="cb-opinion__text" dangerouslySetInnerHTML={{ __html: data.text }} />
        </div>
    );
}

function EndmarkBlock({ data }) {
    return <div className="cb-endmark">— {data.text} —</div>;
}

function PhotoMosaicBlock({ data }) {
    const images = data.images || [];
    return (
        <div className="cb-mosaic">
            {images.map((img, i) => (
                <div key={i} className={`cb-mosaic__item${img.span === 'rows' ? ' cb-mosaic__item--span' : ''}`}>
                    <img className="cb-mosaic__image" src={img.url} alt={img.alt || ''} loading="lazy" />
                </div>
            ))}
        </div>
    );
}

function TwoColumnTextBlock({ data }) {
    return (
        <div className="cb-two-col-text">
            {(data.paragraphs || []).map((p, i) => (
                <p key={i} dangerouslySetInnerHTML={{ __html: p }} />
            ))}
        </div>
    );
}

/* ─────────────────────────────────────────
   Лендинговые блоки
   ───────────────────────────────────────── */

function IconFeatureBlock({ data }) {
    const cols = data.columns || '3';
    return (
        <div className={`cb-icon-features cb-icon-features--${cols}`}>
            {(data.items || []).map((item, i) => (
                <div key={i} className="cb-icon-features__item">
                    <div className="cb-icon-features__icon">{item.icon}</div>
                    {item.title && <div className="cb-icon-features__title">{item.title}</div>}
                    {item.text && <div className="cb-icon-features__text">{item.text}</div>}
                </div>
            ))}
        </div>
    );
}

function CountdownBlock({ data }) {
    const [timeLeft, setTimeLeft] = useState({ days: 0, hours: 0, minutes: 0, seconds: 0 });

    useEffect(() => {
        if (!data.targetDate) return;
        const target = new Date(data.targetDate).getTime();

        const tick = () => {
            const diff = Math.max(0, target - Date.now());
            setTimeLeft({
                days: Math.floor(diff / 86400000),
                hours: Math.floor((diff % 86400000) / 3600000),
                minutes: Math.floor((diff % 3600000) / 60000),
                seconds: Math.floor((diff % 60000) / 1000),
            });
        };

        tick();
        const id = setInterval(tick, 1000);
        return () => clearInterval(id);
    }, [data.targetDate]);

    const pad = n => String(n).padStart(2, '0');
    const style = data.style || 'default';

    return (
        <div className={`cb-countdown cb-countdown--${style}`}>
            {data.title && <div className="cb-countdown__title">{data.title}</div>}
            <div className="cb-countdown__boxes">
                {[
                    [timeLeft.days, 'Дней'],
                    [timeLeft.hours, 'Часов'],
                    [timeLeft.minutes, 'Минут'],
                    [timeLeft.seconds, 'Секунд'],
                ].map(([val, label], i) => (
                    <div key={i} className="cb-countdown__box">
                        <div className="cb-countdown__value">{pad(val)}</div>
                        <div className="cb-countdown__label">{label}</div>
                    </div>
                ))}
            </div>
        </div>
    );
}

function TabsBlock({ data }) {
    const [active, setActive] = useState(0);
    const tabs = data.tabs || [];
    if (!tabs.length) return null;

    return (
        <div className="cb-tabs">
            <div className="cb-tabs__bar" role="tablist">
                {tabs.map((tab, i) => (
                    <button
                        key={i}
                        role="tab"
                        className={`cb-tabs__tab${active === i ? ' cb-tabs__tab--active' : ''}`}
                        onClick={() => setActive(i)}
                    >
                        {tab.title}
                    </button>
                ))}
            </div>
            <div
                className="cb-tabs__content"
                role="tabpanel"
                dangerouslySetInnerHTML={{ __html: tabs[active]?.content || '' }}
            />
        </div>
    );
}

function ImageCarouselBlock({ data }) {
    const slides = data.slides || [];
    const [current, setCurrent] = useState(0);

    useEffect(() => {
        if (!data.autoplay || slides.length <= 1) return;
        const ms = (data.interval || 5) * 1000;
        const id = setInterval(() => {
            setCurrent(prev => (prev + 1) % slides.length);
        }, ms);
        return () => clearInterval(id);
    }, [data.autoplay, data.interval, slides.length]);

    if (!slides.length) return null;

    return (
        <div className="cb-carousel">
            <div className="cb-carousel__viewport">
                <img
                    className="cb-carousel__image"
                    src={slides[current]?.url}
                    alt={slides[current]?.caption || ''}
                    loading="lazy"
                />
                {slides.length > 1 && (
                    <>
                        <button
                            className="cb-carousel__arrow cb-carousel__arrow--prev"
                            onClick={() => setCurrent((current - 1 + slides.length) % slides.length)}
                            aria-label="Назад"
                        >‹</button>
                        <button
                            className="cb-carousel__arrow cb-carousel__arrow--next"
                            onClick={() => setCurrent((current + 1) % slides.length)}
                            aria-label="Вперёд"
                        >›</button>
                    </>
                )}
            </div>
            {slides[current]?.caption && (
                <div className="cb-carousel__caption">{slides[current].caption}</div>
            )}
            {slides.length > 1 && (
                <div className="cb-carousel__dots">
                    {slides.map((_, i) => (
                        <button
                            key={i}
                            className={`cb-carousel__dot${i === current ? ' cb-carousel__dot--active' : ''}`}
                            onClick={() => setCurrent(i)}
                            aria-label={`Слайд ${i + 1}`}
                        />
                    ))}
                </div>
            )}
        </div>
    );
}

function StatsBlock({ data }) {
    if (!data.items || !data.items.length) return null;
    return (
        <div className="cb-stats">
            {data.items.map((item, i) => (
                <div key={i} className="cb-stats__item">
                    <div className="cb-stats__value">{item.value}</div>
                    <div className="cb-stats__label">{item.label}</div>
                </div>
            ))}
        </div>
    );
}

function LogoWallBlock({ data }) {
    if (!data.logos || !data.logos.length) return null;
    const isMarquee = data.marquee !== false;
    
    return (
        <div className="cb-logowall">
            {data.title && <h3 className="cb-logowall__title">{data.title}</h3>}
            <div className={`cb-logowall__track ${isMarquee ? 'cb-logowall__track--marquee' : 'cb-logowall__track--grid'}`}>
                {data.logos.map((logo, i) => (
                    <img key={i} src={logo.url} alt="Logo" className="cb-logowall__img" loading="lazy" />
                ))}
                {isMarquee && data.logos.map((logo, i) => (
                    <img key={`dup-${i}`} src={logo.url} alt="Logo" className="cb-logowall__img" loading="lazy" aria-hidden="true" />
                ))}
            </div>
        </div>
    );
}

function AlertBannerBlock({ data }) {
    const style = data.style || 'promo';
    return (
        <div className={`cb-alert-banner cb-alert-banner--${style}`}>
            <div className="cb-alert-banner__content">
                <span className="cb-alert-banner__text">{data.text}</span>
                {data.btnText && data.btnUrl && (
                    <a href={data.btnUrl} className="cb-alert-banner__btn">{data.btnText}</a>
                )}
            </div>
        </div>
    );
}

function SpacerBlock({ data }) {
    const height = data.height || 40;
    return <div style={{ height: `${height}px` }} aria-hidden="true"></div>;
}

function ButtonBlock({ data }) {
    if (!data.url) return null;
    const align = data.align || 'center';
    const alignMap = { left: 'flex-start', center: 'center', right: 'flex-end' };
    return (
        <div className="cb-button-wrap" style={{ justifyContent: alignMap[align] }}>
            <a href={data.url} className={`cb-btn cb-btn--${data.style || 'solid'}`}>
                {data.text || 'Нажать'}
            </a>
        </div>
    );
}

function StepsBlock({ data }) {
    if (!data.steps || !data.steps.length) return null;
    return (
        <div className="cb-steps">
            {data.steps.map((step, i) => (
                <div key={i} className="cb-steps__item">
                    <div className="cb-steps__num">{i + 1}</div>
                    <div className="cb-steps__content">
                        {step.title && <div className="cb-steps__title">{step.title}</div>}
                        {step.text && <div className="cb-steps__text">{step.text}</div>}
                    </div>
                </div>
            ))}
        </div>
    );
}

function TimelineBlock({ data }) {
    if (!data.events || !data.events.length) return null;
    return (
        <div className="cb-timeline">
            {data.events.map((ev, i) => (
                <div key={i} className="cb-timeline__item">
                    <div className="cb-timeline__date">{ev.date}</div>
                    <div className="cb-timeline__content">
                        {ev.title && <div className="cb-timeline__title">{ev.title}</div>}
                        {ev.text && <div className="cb-timeline__text">{ev.text}</div>}
                    </div>
                </div>
            ))}
        </div>
    );
}

function BeforeAfterBlock({ data }) {
    const [sliderPos, setSliderPos] = useState(50);
    const containerRef = useRef(null);

    const handleMove = (e) => {
        if (!containerRef.current) return;
        const rect = containerRef.current.getBoundingClientRect();
        const clientX = e.clientX ?? (e.touches && e.touches[0].clientX);
        if (clientX === undefined) return;
        
        let pos = ((clientX - rect.left) / rect.width) * 100;
        pos = Math.max(0, Math.min(100, pos));
        setSliderPos(pos);
    };

    const handleDown = () => {
        const up = () => {
            window.removeEventListener('mousemove', handleMove);
            window.removeEventListener('mouseup', up);
        };
        window.addEventListener('mousemove', handleMove);
        window.addEventListener('mouseup', up);
    };

    if (!data.beforeUrl || !data.afterUrl) return null;

    return (
        <div className="cb-before-after" ref={containerRef}>
            <img src={data.afterUrl} alt="After" className="cb-before-after__img" />
            <div className="cb-before-after__label cb-before-after__label--after">{data.afterLabel || 'После'}</div>
            
            <div className="cb-before-after__overlay" style={{ clipPath: `inset(0 ${100 - sliderPos}% 0 0)` }}>
                <img src={data.beforeUrl} alt="Before" className="cb-before-after__img" />
                <div className="cb-before-after__label cb-before-after__label--before">{data.beforeLabel || 'До'}</div>
            </div>

            <div 
                className="cb-before-after__slider" 
                style={{ left: `${sliderPos}%` }}
                onMouseDown={handleDown}
                onTouchMove={handleMove}
            >
                <div className="cb-before-after__handle">↔</div>
            </div>
        </div>
    );
}

function ComparisonBlock({ data }) {
    if (!data.rows || !data.rows.length) return null;
    
    const renderIcon = (type, text) => {
        if (type === 'check') return <span className="cb-comp__icon cb-comp__icon--check">✓</span>;
        if (type === 'cross') return <span className="cb-comp__icon cb-comp__icon--cross">✕</span>;
        return <span className="cb-comp__text">{text}</span>;
    };

    return (
        <div className="cb-comp-wrap">
            <table className="cb-comp">
                <thead>
                    <tr>
                        <th className="cb-comp__feature-th"></th>
                        <th>{data.col1Title}</th>
                        <th className="cb-comp__th--brand">{data.col2Title}</th>
                    </tr>
                </thead>
                <tbody>
                    {data.rows.map((row, i) => (
                        <tr key={i}>
                            <td className="cb-comp__feature">{row.feature}</td>
                            <td>{renderIcon(row.col1Icon, row.col1Text)}</td>
                            <td className="cb-comp__td--brand">{renderIcon(row.col2Icon, row.col2Text)}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function PricingTableBlock({ data }) {
    if (!data.plans || !data.plans.length) return null;
    return (
        <div className="cb-pricing">
            {data.plans.map((plan, i) => (
                <div key={i} className={`cb-pricing__card ${plan.isPopular ? 'cb-pricing__card--popular' : ''}`}>
                    {plan.isPopular && <div className="cb-pricing__badge">Рекомендуем</div>}
                    <h4 className="cb-pricing__title">{plan.title}</h4>
                    <div className="cb-pricing__price">{plan.price}</div>
                    
                    <ul className="cb-pricing__features">
                        {(plan.features || []).map((feat, j) => (
                            <li key={j}>{feat}</li>
                        ))}
                    </ul>
                    
                    <a href={plan.btnUrl || '#'} className="cb-pricing__btn">
                        {plan.btnText || 'Выбрать'}
                    </a>
                </div>
            ))}
        </div>
    );
}

function MapBlock({ data }) {
    if (!data.embedCode) return null;
    return (
        <div 
            className="cb-map" 
            style={{ height: `${data.height || 400}px` }} 
            dangerouslySetInnerHTML={{ __html: data.embedCode }}
        />
    );
}

