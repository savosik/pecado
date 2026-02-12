import { Box } from '@chakra-ui/react';
import UserLayout from '../UserLayout';
import SeoHead from '@/components/common/SeoHead';
import PageHeader from '@/components/common/PageHeader';
import { Prose } from '@/components/ui/prose';

/**
 * CMS-страница — рендер произвольного HTML-контента.
 *
 * @param {{ page: { id: number, title: string, slug: string, content: string }, seo: object, breadcrumbs: Array }} props
 */
export default function PageShow({ page, seo }) {
    return (
        <UserLayout>
            <SeoHead seo={seo} />
            <PageHeader title={page.title} />

            <Box
                bg="white"
                _dark={{ bg: 'gray.800' }}
                border="1px solid"
                borderColor="gray.100"
                borderRadius="sm"
                p={{ base: '5', md: '8' }}
            >
                <Prose
                    size="lg"
                    maxW="none"
                    dangerouslySetInnerHTML={{ __html: page.content }}
                />
            </Box>
        </UserLayout>
    );
}
