import { useState, useEffect, useRef, useCallback } from 'react';
import {
    Accordion, Box, Input, Span, Spinner, Text, VStack,
} from '@chakra-ui/react';
import { router } from '@inertiajs/react';
import UserLayout from '../UserLayout';
import SeoHead from '@/components/common/SeoHead';
import Breadcrumbs from '@/components/common/Breadcrumbs';
import PageHeader from '@/components/common/PageHeader';
import EmptyState from '@/components/common/EmptyState';
import { LuCircleHelp, LuSearch } from 'react-icons/lu';

/**
 * Страница FAQ — аккордеон с поиском.
 */
export default function FaqIndex({ faqs: initialFaqs, q: initialQ, seo, breadcrumbs }) {
    const faqs = Array.isArray(initialFaqs) ? initialFaqs : [];
    const [q, setQ] = useState(initialQ ?? '');
    const [isSearching, setIsSearching] = useState(false);
    const isInitialMount = useRef(true);

    // Debounced server-side search
    useEffect(() => {
        if (isInitialMount.current) {
            isInitialMount.current = false;
            return;
        }

        if (q === (initialQ ?? '')) {
            setIsSearching(false);
            return;
        }

        setIsSearching(true);
        const id = setTimeout(() => {
            router.get('/faq', { q: q || '' }, {
                preserveScroll: true,
                preserveState: true,
                only: ['faqs', 'q'],
                onFinish: () => setIsSearching(false),
                onError: () => setIsSearching(false),
            });
        }, 300);
        return () => clearTimeout(id);
    }, [q, initialQ]);

    const handleClearSearch = useCallback(() => {
        setQ('');
        router.get('/faq', {}, {
            preserveScroll: true,
            preserveState: true,
            only: ['faqs', 'q'],
        });
    }, []);

    return (
        <UserLayout>
            <SeoHead seo={seo} />
            <Breadcrumbs items={breadcrumbs} />
            <PageHeader
                title="FAQ"
                subtitle="Часто задаваемые вопросы и ответы"
            />

            <Box maxW="800px">
                {/* Поиск */}
                <Box mb="6" position="relative">
                    <Input
                        placeholder="Поиск по вопросам и ответам..."
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                        size="lg"
                        bg="white"
                        _dark={{ bg: 'gray.800' }}
                        borderColor="gray.200"
                        borderRadius="sm"
                        pl="12"
                    />
                    <Box
                        position="absolute"
                        left="4"
                        top="50%"
                        transform="translateY(-50%)"
                        color="gray.400"
                        zIndex="1"
                    >
                        <LuSearch size={18} />
                    </Box>
                </Box>

                {/* Контент */}
                {isSearching ? (
                    <Box
                        bg="white"
                        _dark={{ bg: 'gray.800' }}
                        border="1px solid"
                        borderColor="gray.100"
                        borderRadius="sm"
                        p="8"
                        textAlign="center"
                    >
                        <Spinner size="sm" mr="2" />
                        <Text as="span" color="fg.muted">Поиск...</Text>
                    </Box>
                ) : faqs.length === 0 ? (
                    <EmptyState
                        icon={LuCircleHelp}
                        title="Вопросы не найдены"
                        description={
                            q
                                ? 'Попробуйте изменить поисковый запрос'
                                : 'Пока нет добавленных вопросов'
                        }
                        action={
                            q
                                ? { label: 'Сбросить поиск', onClick: handleClearSearch }
                                : { label: 'На главную', href: '/' }
                        }
                    />
                ) : (
                    <Box
                        bg="white"
                        _dark={{ bg: 'gray.800' }}
                        border="1px solid"
                        borderColor="gray.100"
                        borderRadius="sm"
                        overflow="hidden"
                    >
                        <Accordion.Root collapsible variant="plain">
                            {faqs.map((faq) => (
                                <Accordion.Item
                                    key={faq.id}
                                    value={String(faq.id)}
                                    borderBottom="1px solid"
                                    borderColor="gray.100"
                                    _dark={{ borderColor: 'gray.700' }}
                                    _last={{ borderBottom: 'none' }}
                                >
                                    <Accordion.ItemTrigger
                                        px="6"
                                        py="4"
                                        cursor="pointer"
                                        _hover={{ bg: 'gray.50' }}
                                        _dark={{ _hover: { bg: 'gray.700' } }}
                                        transition="background 0.15s"
                                    >
                                        <Span
                                            flex="1"
                                            fontWeight="semibold"
                                            fontSize="md"
                                            textAlign="start"
                                        >
                                            {faq.title}
                                        </Span>
                                        <Accordion.ItemIndicator />
                                    </Accordion.ItemTrigger>
                                    <Accordion.ItemContent>
                                        <Accordion.ItemBody px="6" pb="5" pt="0">
                                            <Box
                                                color="fg.muted"
                                                fontSize="sm"
                                                lineHeight="tall"
                                                css={{
                                                    '& p': { marginBottom: '0.75rem' },
                                                    '& p:last-child': { marginBottom: 0 },
                                                    '& a': { color: 'var(--chakra-colors-pecado-500)', textDecoration: 'underline' },
                                                    '& ul, & ol': { paddingLeft: '1.5rem', marginBottom: '0.75rem' },
                                                    '& li': { marginBottom: '0.25rem' },
                                                }}
                                                dangerouslySetInnerHTML={{ __html: faq.content }}
                                            />
                                        </Accordion.ItemBody>
                                    </Accordion.ItemContent>
                                </Accordion.Item>
                            ))}
                        </Accordion.Root>
                    </Box>
                )}
            </Box>
        </UserLayout>
    );
}
