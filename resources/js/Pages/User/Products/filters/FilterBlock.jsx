import { useState } from 'react';
import { Box, Collapsible, Flex, Text } from '@chakra-ui/react';
import { LuChevronDown } from 'react-icons/lu';

/**
 * FilterBlock — обёртка-аккордеон для блока фильтра.
 *
 * Заголовок + стрелка + опциональная кнопка «Очистить».
 * Содержимое раскрывается/скрывается по клику на заголовок.
 *
 * @param {{
 *   title: string,
 *   children: React.ReactNode,
 *   defaultOpen?: boolean,
 *   onClear?: () => void,
 *   showClear?: boolean,
 * }} props
 */
export default function FilterBlock({
    title,
    children,
    defaultOpen = true,
    onClear,
    showClear = false,
}) {
    const [open, setOpen] = useState(defaultOpen);

    return (
        <Collapsible.Root open={open} onOpenChange={(e) => setOpen(e.open)}>
            <Collapsible.Trigger asChild>
                <Flex
                    align="center"
                    justify="space-between"
                    cursor="pointer"
                    py="2"
                    userSelect="none"
                >
                    <Text fontSize="sm" fontWeight="600">
                        {title}
                    </Text>
                    <Flex align="center" gap="1">
                        {showClear && (
                            <Text
                                as="button"
                                type="button"
                                fontSize="xs"
                                color="pink.500"
                                _hover={{ color: 'pink.600' }}
                                onClick={(e) => {
                                    e.stopPropagation();
                                    e.preventDefault();
                                    onClear?.();
                                }}
                            >
                                Очистить
                            </Text>
                        )}
                        <Box
                            transition="transform 0.2s"
                            transform={open ? 'rotate(180deg)' : 'rotate(0deg)'}
                            color="gray.400"
                        >
                            <LuChevronDown size={16} />
                        </Box>
                    </Flex>
                </Flex>
            </Collapsible.Trigger>

            <Collapsible.Content>
                <Box pb="3">
                    {children}
                </Box>
            </Collapsible.Content>
        </Collapsible.Root>
    );
}
