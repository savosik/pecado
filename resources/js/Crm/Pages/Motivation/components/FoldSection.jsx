import { useState } from 'react';
import { Box, HStack, Text } from '@chakra-ui/react';
import { LuChevronDown, LuChevronRight } from 'react-icons/lu';

/**
 * Вторичный блок, свёрнутый по умолчанию: на виду остаётся заголовок
 * и короткая сводка, таблица раскрывается по клику.
 */
export default function FoldSection({ title, summary, defaultOpen = false, children }) {
    const [open, setOpen] = useState(defaultOpen);

    return (
        <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" overflow="hidden">
            <HStack
                as="button"
                type="button"
                w="100%"
                px={4}
                py={3}
                gap={2}
                cursor="pointer"
                textAlign="left"
                aria-expanded={open}
                onClick={() => setOpen(!open)}
                _hover={{ bg: 'bg.subtle' }}
            >
                <Box color="fg.subtle">{open ? <LuChevronDown size={16} /> : <LuChevronRight size={16} />}</Box>
                <Text fontWeight="700" fontSize="sm">{title}</Text>
                {summary && <Text fontSize="sm" color="fg.muted" ml="auto">{summary}</Text>}
            </HStack>
            {open && <Box borderTopWidth="1px" borderColor="border">{children}</Box>}
        </Box>
    );
}
