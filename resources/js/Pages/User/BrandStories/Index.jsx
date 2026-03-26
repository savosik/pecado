import { useState, useCallback } from 'react';
import { SimpleGrid } from '@chakra-ui/react';
import { LuBadge } from 'react-icons/lu';
import { router } from '@inertiajs/react';
import UserLayout from '../UserLayout';
import SeoHead from '@/components/common/SeoHead';
import Breadcrumbs from '@/components/common/Breadcrumbs';
import PageHeader from '@/components/common/PageHeader';
import BrandStoryCard from './Components/BrandStoryCard';
import Pagination from '@/components/common/Pagination';
import EmptyState from '@/components/common/EmptyState';
import ContentSwitcher from '@/components/common/ContentSwitcher';
import ContentTagFilter from '@/components/common/ContentTagFilter';
import usePagination from '@/hooks/usePagination';

/**
 * Страница списка статей о брендах с пагинацией.
 */
export default function BrandStoriesIndex({ brandStories: paginationData, availableTags = [], selectedTags: initialSelectedTags = [], seo, breadcrumbs }) {
    const pagination = usePagination(paginationData, {
        only: 'brandStories',
        preserveScroll: false,
    });

    const [selectedTags, setSelectedTags] = useState(initialSelectedTags);

    const handleTagToggle = useCallback((tag) => {
        const newTags = selectedTags.includes(tag)
            ? selectedTags.filter((t) => t !== tag)
            : [...selectedTags, tag];

        setSelectedTags(newTags);

        router.get('/brand-stories', { tags: newTags }, {
            preserveState: true,
            preserveScroll: true,
            only: ['brandStories', 'selectedTags'],
        });
    }, [selectedTags]);

    const handleReset = useCallback(() => {
        setSelectedTags([]);
        router.get('/brand-stories', {}, {
            preserveState: true,
            preserveScroll: true,
            only: ['brandStories', 'selectedTags'],
        });
    }, []);

    return (
        <UserLayout>
            <SeoHead seo={seo} />
            <Breadcrumbs items={breadcrumbs} />
            <PageHeader
                title="О брендах"
                subtitle="Описания и истории брендов"
                actions={<ContentSwitcher />}
            />
            <ContentTagFilter
                tags={availableTags}
                selectedTags={selectedTags}
                onToggle={handleTagToggle}
                onReset={handleReset}
            />

            {pagination.items.length === 0 ? (
                <EmptyState
                    icon={LuBadge}
                    title="Статей о брендах пока нет"
                    description="Мы скоро опубликуем интересные материалы о брендах"
                    action={{ label: 'На главную', href: '/' }}
                />
            ) : (
                <>
                    <SimpleGrid columns={{ base: 1, sm: 2, lg: 4 }} gap="6">
                        {pagination.items.map((item) => (
                            <BrandStoryCard key={item.id} item={item} />
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
