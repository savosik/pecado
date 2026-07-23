import React from 'react';
import { Box, Heading, Text, Button, Stack, VStack } from '@chakra-ui/react';
import { Link } from '@inertiajs/react';
import { LuPlus } from 'react-icons/lu';
import { usePermission } from '@/Admin/hooks/usePermission';

/**
 * PageHeader - заголовок страницы с описанием и кнопками действий
 * 
 * @param {string} title - Заголовок страницы
 * @param {string} description - Описание страницы
 * @param {Function} onCreate - Callback для кнопки "Создать"
 * @param {string} createHref - Ссылка для кнопки "Создать" (Inertia Link)
 * @param {string} createLabel - Текст кнопки создания
 * @param {string} createPermission - Право для показа кнопки (напр. 'products.create')
 * @param {ReactNode} actions - Дополнительные действия (кнопки, меню и т.д.)
 */
export const PageHeader = ({
    title,
    description,
    onCreate,
    createHref,
    createLabel = 'Создать',
    createPermission,
    actions,
}) => {
    const { can } = usePermission();

    const showCreate = (onCreate || createHref) && (!createPermission || can(createPermission));

    return (
        <Box mb={{ base: 4, md: 6 }}>
            {/* На телефоне заголовок и действия идут колонкой: в строку они не
                помещаются и заголовок переносится по буквам. */}
            <Stack
                direction={{ base: 'column', md: 'row' }}
                justifyContent="space-between"
                alignItems={{ base: 'stretch', md: 'flex-start' }}
                gap={3}
            >
                <VStack align="flex-start" gap={2} minW={0}>
                    <Heading size={{ base: 'md', md: 'lg' }}>{title}</Heading>
                    {description && (
                        <Text color="fg.muted" fontSize="sm">
                            {description}
                        </Text>
                    )}
                </VStack>

                <Stack
                    direction={{ base: 'column', sm: 'row' }}
                    gap={3}
                    flexShrink={0}
                    align={{ base: 'stretch', sm: 'center' }}
                >
                    {actions}

                    {showCreate && (
                        createHref ? (
                            <Button
                                as={Link}
                                href={createHref}
                                colorPalette="blue"
                            >
                                <LuPlus />
                                {createLabel}
                            </Button>
                        ) : (
                            <Button
                                onClick={onCreate}
                                colorPalette="blue"
                            >
                                <LuPlus />
                                {createLabel}
                            </Button>
                        )
                    )}
                </Stack>
            </Stack>
        </Box>
    );
};
