import { SimpleGrid } from '@chakra-ui/react';
import { LuNewspaper } from 'react-icons/lu';
import UserLayout from '../UserLayout';
import SeoHead from '@/components/common/SeoHead';
import Breadcrumbs from '@/components/common/Breadcrumbs';
import PageHeader from '@/components/common/PageHeader';
import ContentCard from '@/components/common/ContentCard';
import Pagination from '@/components/common/Pagination';
import EmptyState from '@/components/common/EmptyState';
import ContentSwitcher from '@/components/common/ContentSwitcher';
import usePagination from '@/hooks/usePagination';

/**
 * Страница списка новостей с пагинацией.
 *
 * @param {{ news: object, seo: object, breadcrumbs: Array }} props
 */
export default function NewsIndex({ news: paginationData, seo, breadcrumbs }) {
    const pagination = usePagination(paginationData, {
        only: 'news',
        preserveScroll: false,
    });

    return (
        <UserLayout>
            <SeoHead seo={seo} />
            <Breadcrumbs items={breadcrumbs} />
            <PageHeader
                title="Новости"
                subtitle="Последние новости и обновления"
            />
            <ContentSwitcher />

            {pagination.items.length === 0 ? (
                <EmptyState
                    icon={LuNewspaper}
                    title="Новостей пока нет"
                    description="Мы скоро опубликуем интересные новости"
                    action={{ label: 'На главную', href: '/' }}
                />
            ) : (
                <>
                    <SimpleGrid columns={{ base: 1, sm: 2, lg: 3 }} gap="6">
                        {pagination.items.map((item) => (
                            <ContentCard
                                key={item.id}
                                title={item.title}
                                excerpt={item.excerpt}
                                image={item.image}
                                date={item.published_at}
                                url={`/news/${item.slug}`}
                                tags={item.tags}
                            />
                        ))}
                    </SimpleGrid>

                    <Pagination
                        currentPage={pagination.currentPage}
                        lastPage={pagination.lastPage}
                        pageNumbers={pagination.pageNumbers}
                        onPageChange={pagination.onPageChange}
                        total={pagination.total}
                        perPage={pagination.perPage}
                    />
                </>
            )}
        </UserLayout>
    );
}
