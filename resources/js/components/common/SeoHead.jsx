import { Head } from '@inertiajs/react';

/**
 * SEO-компонент с Open Graph, Twitter Card и JSON-LD structured data.
 *
 * @param {{ seo: { title?: string, description?: string, keywords?: string, image?: string, url?: string, type?: string, structured_data?: object|object[] } }} props
 */
export default function SeoHead({ seo }) {
    if (!seo) return null;

    const { title, description, keywords, image, url, type = 'website', structured_data } = seo;

    return (
        <Head>
            {title && <title>{title}</title>}
            {description && <meta name="description" content={description} />}
            {keywords && <meta name="keywords" content={keywords} />}

            {/* Open Graph */}
            {title && <meta property="og:title" content={title} />}
            {description && <meta property="og:description" content={description} />}
            {type && <meta property="og:type" content={type} />}
            {url && <meta property="og:url" content={url} />}
            {image && <meta property="og:image" content={image} />}

            {/* Twitter Card */}
            <meta name="twitter:card" content={image ? 'summary_large_image' : 'summary'} />
            {title && <meta name="twitter:title" content={title} />}
            {description && <meta name="twitter:description" content={description} />}
            {image && <meta name="twitter:image" content={image} />}

            {/* JSON-LD Structured Data */}
            {structured_data && (
                <script type="application/ld+json">
                    {JSON.stringify(
                        Array.isArray(structured_data) ? structured_data : [structured_data]
                    )}
                </script>
            )}
        </Head>
    );
}
