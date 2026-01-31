import React from 'react';
import { Box, Heading, Text, Button, HStack, VStack } from '@chakra-ui/react';
import { Link } from '@inertiajs/react';
import { LuPlus } from 'react-icons/lu';

/**
 * PageHeader - заголовок страницы с описанием и кнопками действий
 * 
 * @param {string} title - Заголовок страницы
 * @param {string} description - Описание страницы
 * @param {Function} onCreate - Callback для кнопки "Создать"
 * @param {string} createHref - Ссылка для кнопки "Создать" (Inertia Link)
 * @param {string} createLabel - Текст кнопки создания
 * @param {ReactNode} actions - Дополнительные действия (кнопки, меню и т.д.)
 */
export const PageHeader = ({
    title,
    description,
    onCreate,
    createHref,
    createLabel = 'Создать',
    actions,
}) => {
    return (
        <Box mb={6}>
            <HStack justifyContent="space-between" alignItems="flex-start">
                <VStack align="flex-start" gap={2}>
                    <Heading size="lg">{title}</Heading>
                    {description && (
                        <Text color="fg.muted" fontSize="sm">
                            {description}
                        </Text>
                    )}
                </VStack>

                <HStack gap={3}>
                    {actions}

                    {(onCreate || createHref) && (
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
                </HStack>
            </HStack>
        </Box>
    );
};
