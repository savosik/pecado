import { Breadcrumb, HStack, Text } from '@chakra-ui/react';
import { Link } from '@inertiajs/react';
import { LuChevronRight } from 'react-icons/lu';

/**
 * Хлебные крошки с JSON-LD (BreadcrumbList schema).
 *
 * @param {{ items: Array<{ label: string, url?: string }> }} props
 */
export default function Breadcrumbs({ items }) {
    if (!items || items.length === 0) return null;

    // JSON-LD BreadcrumbList
    const jsonLd = {
        '@context': 'https://schema.org',
        '@type': 'BreadcrumbList',
        itemListElement: items.map((item, index) => ({
            '@type': 'ListItem',
            position: index + 1,
            name: item.label,
            ...(item.url ? { item: item.url } : {}),
        })),
    };

    return (
        <>
            <script
                type="application/ld+json"
                dangerouslySetInnerHTML={{ __html: JSON.stringify(jsonLd) }}
            />
            <Breadcrumb.Root
                mb="4"
                fontSize="sm"
                separator={<LuChevronRight size={14} color="gray" />}
            >
                <Breadcrumb.List>
                    {items.map((item, index) => {
                        const isLast = index === items.length - 1;

                        return (
                            <Breadcrumb.Item key={index}>
                                {isLast ? (
                                    <Breadcrumb.CurrentLink
                                        color="fg.muted"
                                        fontWeight="medium"
                                    >
                                        {item.label}
                                    </Breadcrumb.CurrentLink>
                                ) : (
                                    <>
                                        <Breadcrumb.Link asChild>
                                            <Link href={item.url || '/'}>
                                                {item.label}
                                            </Link>
                                        </Breadcrumb.Link>
                                        <Breadcrumb.Separator />
                                    </>
                                )}
                            </Breadcrumb.Item>
                        );
                    })}
                </Breadcrumb.List>
            </Breadcrumb.Root>
        </>
    );
}
