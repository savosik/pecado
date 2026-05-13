import { useState, useCallback } from 'react';
import { SimpleGrid } from '@chakra-ui/react';
import { LuNewspaper } from 'react-icons/lu';
import { router } from '@inertiajs/react';
import UserLayout from '../UserLayout';
import SeoHead from '@/components/common/SeoHead';
import Breadcrumbs from '@/components/common/Breadcrumbs';
import PageHeader from '@/components/common/PageHeader';
import ContentCard from '@/components/common/ContentCard';
import Pagination from '@/components/common/Pagination';
import EmptyState from '@/components/common/EmptyState';
import ContentSwitcher from '@/components/common/ContentSwitcher';
import ContentTagFilter from '@/components/common/ContentTagFilter';
import usePagination from '@/hooks/usePagination';

/**
 * Страница списка новостей с пагинацией.
 */
export default function NewsIndex({ news: paginationData, availableTags = [], selectedTags: initialSelectedTags = [], seo, breadcrumbs }) {
    const pagination = usePagination(paginationData, {
        only: 'news',
        preserveScroll: false,
    });

    const [selectedTags, setSelectedTags] = useState(initialSelectedTags);

    const handleTagToggle = useCallback((tag) => {
        const newTags = selectedTags.includes(tag)
            ? selectedTags.filter((t) => t !== tag)
            : [...selectedTags, tag];

        setSelectedTags(newTags);

        router.get('/news', { tags: newTags }, {
            preserveState: true,
            preserveScroll: true,
            only: ['news', 'selectedTags'],
        });
    }, [selectedTags]);

    const handleReset = useCallback(() => {
        setSelectedTags([]);
        router.get('/news', {}, {
            preserveState: true,
            preserveScroll: true,
            only: ['news', 'selectedTags'],
        });
    }, []);

    return (
        <UserLayout>
            <SeoHead seo={seo} />
            <Breadcrumbs items={breadcrumbs} />
            <PageHeader
                title="Новости"
                subtitle="Последние новости и обновления"
                /* actions={<ContentSwitcher />} */
            />
            <ContentTagFilter
                tags={availableTags}
                selectedTags={selectedTags}
                onToggle={handleTagToggle}
                onReset={handleReset}
            />

            {pagination.items.length === 0 ? (
                <EmptyState
                    icon={LuNewspaper}
                    title="Новостей пока нет"
                    description="Мы скоро опубликуем интересные новости"
                    action={{ label: 'На главную', href: '/' }}
                />
            ) : (
                <>
                    <SimpleGrid columns={{ base: 1, sm: 2, lg: 4 }} gap="6">
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
