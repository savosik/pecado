import { SimpleGrid } from '@chakra-ui/react';
import { LuFileText } from 'react-icons/lu';
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
 * Страница списка статей с пагинацией.
 *
 * @param {{ articles: object, seo: object, breadcrumbs: Array }} props
 */
export default function ArticlesIndex({ articles: paginationData, seo, breadcrumbs }) {
    const pagination = usePagination(paginationData, {
        only: 'articles',
        preserveScroll: false,
    });

    return (
        <UserLayout>
            <SeoHead seo={seo} />
            <Breadcrumbs items={breadcrumbs} />
            <PageHeader
                title="Статьи"
                subtitle="Полезные статьи и материалы"
            />
            <ContentSwitcher />

            {pagination.items.length === 0 ? (
                <EmptyState
                    icon={LuFileText}
                    title="Статей пока нет"
                    description="Мы скоро опубликуем интересные статьи"
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
                                url={`/articles/${item.slug}`}
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
