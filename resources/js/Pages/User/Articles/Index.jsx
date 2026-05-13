import { useState, useCallback } from 'react';
import { SimpleGrid } from '@chakra-ui/react';
import { LuFileText } from 'react-icons/lu';
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
 * Страница списка статей с пагинацией.
 */
export default function ArticlesIndex({ articles: paginationData, availableTags = [], selectedTags: initialSelectedTags = [], seo, breadcrumbs }) {
    const pagination = usePagination(paginationData, {
        only: 'articles',
        preserveScroll: false,
    });

    const [selectedTags, setSelectedTags] = useState(initialSelectedTags);

    const handleTagToggle = useCallback((tag) => {
        const newTags = selectedTags.includes(tag)
            ? selectedTags.filter((t) => t !== tag)
            : [...selectedTags, tag];

        setSelectedTags(newTags);

        router.get('/articles', { tags: newTags }, {
            preserveState: true,
            preserveScroll: true,
            only: ['articles', 'selectedTags'],
        });
    }, [selectedTags]);

    const handleReset = useCallback(() => {
        setSelectedTags([]);
        router.get('/articles', {}, {
            preserveState: true,
            preserveScroll: true,
            only: ['articles', 'selectedTags'],
        });
    }, []);

    return (
        <UserLayout>
            <SeoHead seo={seo} />
            <Breadcrumbs items={breadcrumbs} />
            <PageHeader
                title="Статьи"
                subtitle="Полезные статьи и материалы"
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
                    icon={LuFileText}
                    title="Статей пока нет"
                    description="Мы скоро опубликуем интересные статьи"
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
