import { SimpleGrid } from '@chakra-ui/react';
import { LuTicket } from 'react-icons/lu';
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
 * Страница списка акций с пагинацией.
 */
export default function PromotionsIndex({ promotions: paginationData, seo, breadcrumbs }) {
    const pagination = usePagination(paginationData, {
        only: 'promotions',
        preserveScroll: false,
    });

    return (
        <UserLayout>
            <SeoHead seo={seo} />
            <Breadcrumbs items={breadcrumbs} />
            <PageHeader
                title="Акции"
                subtitle="Специальные предложения и скидки"
                /* actions={<ContentSwitcher />} */
            />

            {pagination.items.length === 0 ? (
                <EmptyState
                    icon={LuTicket}
                    title="Акций пока нет"
                    description="Мы скоро добавим интересные акции и предложения"
                    action={{ label: 'На главную', href: '/' }}
                />
            ) : (
                <>
                    <SimpleGrid columns={{ base: 1, sm: 2, lg: 4 }} gap="6">
                        {pagination.items.map((item) => (
                            <ContentCard
                                key={item.id}
                                title={item.name}
                                excerpt={item.excerpt}
                                image={item.image}
                                date={item.created_at}
                                url={`/promotions/${item.slug}`}
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
