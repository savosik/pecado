import React from 'react';
import { Box, Flex, Text, Menu, Portal } from '@chakra-ui/react';
import { Link } from '@inertiajs/react';
import { LuChevronRight, LuChevronDown } from 'react-icons/lu';

/**
 * Хлебные крошки с JSON-LD (BreadcrumbList schema) и поддержкой siblings dropdown.
 *
 * items: Array<{ label: string, url?: string, siblings?: Array<{ label: string, href: string }> }>
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
            <Box
                as="nav"
                fontSize="sm"
                color="gray.500"
                _dark={{ color: 'gray.400' }}
                mb="4"
                overflowX="auto"
                css={{ scrollbarWidth: 'none', '&::-webkit-scrollbar': { display: 'none' } }}
                mx="-2" px="2"
            >
                <Flex align="center" gap="1" minW="max-content">
                    {items.map((item, index) => {
                        const isLast = index === items.length - 1;

                        return (
                            <Flex key={index} align="center" flexShrink={0}>
                                {index > 0 && (
                                    <Box mx={{ base: '0.5', sm: '1' }} flexShrink={0} color="gray.400">
                                        <LuChevronRight size={14} />
                                    </Box>
                                )}
                                <Flex align="center" gap="1" minW="0">
                                    {item.url && !isLast ? (
                                        <Text
                                            as={Link}
                                            href={item.url}
                                            _hover={{ color: 'gray.700', _dark: { color: 'gray.200' } }}
                                            transition="color 0.15s"
                                            truncate
                                            maxW={{ base: '80px', sm: '150px', md: '200px', lg: '250px' }}
                                            whiteSpace="nowrap"
                                            title={item.label}
                                        >
                                            {item.label}
                                        </Text>
                                    ) : (
                                        <Text
                                            color={isLast ? 'gray.700' : undefined}
                                            _dark={{ color: isLast ? 'gray.200' : undefined }}
                                            fontWeight={isLast ? 'medium' : undefined}
                                            truncate
                                            maxW={{ base: '100px', sm: '150px', md: '200px', lg: '300px' }}
                                            whiteSpace="nowrap"
                                            title={item.label}
                                        >
                                            {item.label}
                                        </Text>
                                    )}

                                    {/* Siblings dropdown */}
                                    {item.siblings && item.siblings.length > 0 && (
                                        <Menu.Root positioning={{ placement: 'bottom-start' }}>
                                            <Menu.Trigger asChild>
                                                <Box
                                                    as="button"
                                                    display="inline-flex"
                                                    alignItems="center"
                                                    justifyContent="center"
                                                    borderRadius="sm"
                                                    p="0.5"
                                                    cursor="pointer"
                                                    color="gray.400"
                                                    _hover={{ color: 'gray.600', bg: 'gray.100', _dark: { color: 'gray.300', bg: 'gray.700' } }}
                                                    flexShrink={0}
                                                    transition="all 0.15s"
                                                >
                                                    <LuChevronDown size={12} />
                                                </Box>
                                            </Menu.Trigger>
                                            <Portal>
                                                <Menu.Positioner>
                                                    <Menu.Content
                                                        maxH="300px"
                                                        overflowY="auto"
                                                        minW="160px"
                                                    >
                                                        {item.siblings.map((s, i) => (
                                                            <Menu.Item
                                                                key={i}
                                                                value={s.href}
                                                                asChild
                                                            >
                                                                <Link href={s.href}>
                                                                    {s.label}
                                                                </Link>
                                                            </Menu.Item>
                                                        ))}
                                                    </Menu.Content>
                                                </Menu.Positioner>
                                            </Portal>
                                        </Menu.Root>
                                    )}
                                </Flex>
                            </Flex>
                        );
                    })}
                </Flex>
            </Box>
        </>
    );
}
