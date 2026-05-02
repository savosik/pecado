import { useState, useCallback } from 'react';
import { Box, Flex, Text } from '@chakra-ui/react';
import { LuChevronDown } from 'react-icons/lu';

/**
 * CollapsibleFilterCard — карточка-спойлер для секции фильтров каталога.
 *
 * Шапка с uppercase-заголовком и шевроном, кликабельная для разворота/сворачивания.
 * Состояние раскрытия сохраняется в localStorage по ключу storageKey.
 *
 * @param {{
 *   title: string,
 *   storageKey: string,
 *   defaultOpen?: boolean,
 *   bodyPx?: string,
 *   bodyPy?: string,
 *   children: React.ReactNode,
 * }} props
 */
export default function CollapsibleFilterCard({
    title,
    storageKey,
    defaultOpen = false,
    bodyPx = '2',
    bodyPy = '3',
    children,
}) {
    const [open, setOpen] = useState(() => {
        try {
            const stored = localStorage.getItem(storageKey);
            if (stored === '1') return true;
            if (stored === '0') return false;
            return defaultOpen;
        } catch {
            return defaultOpen;
        }
    });

    const toggle = useCallback(() => {
        setOpen((prev) => {
            const next = !prev;
            try {
                localStorage.setItem(storageKey, next ? '1' : '0');
            } catch {
                // localStorage недоступен
            }
            return next;
        });
    }, [storageKey]);

    return (
        <Box
            bg="bg"
            borderRadius="md"
            border="1px solid"
            borderColor="gray.200"
            _dark={{ borderColor: 'gray.700' }}
            overflow="hidden"
        >
            <Flex
                as="button"
                type="button"
                onClick={toggle}
                align="center"
                justify="space-between"
                w="100%"
                px="3"
                py="3"
                bg="gray.100"
                borderBottom={open ? '1px solid' : 'none'}
                borderColor="border.muted"
                cursor="pointer"
                transition="background 0.15s"
                _hover={{ bg: 'gray.200' }}
                _dark={{ bg: 'gray.700', _hover: { bg: 'gray.600' } }}
                aria-expanded={open}
            >
                <Text
                    fontSize="xs"
                    fontWeight="500"
                    color="gray.600"
                    _dark={{ color: 'gray.300' }}
                    textTransform="uppercase"
                    letterSpacing="0.03em"
                >
                    {title}
                </Text>
                <Box
                    transition="transform 0.2s"
                    transform={open ? 'rotate(180deg)' : 'rotate(0deg)'}
                    color="gray.400"
                    display="flex"
                    alignItems="center"
                >
                    <LuChevronDown size={16} />
                </Box>
            </Flex>
            {open && (
                <Box px={bodyPx} py={bodyPy}>
                    {children}
                </Box>
            )}
        </Box>
    );
}
