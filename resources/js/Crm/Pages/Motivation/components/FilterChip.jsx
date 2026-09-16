import { Box, HStack, Text } from '@chakra-ui/react';
import { LuCheck, LuPlus, LuX } from 'react-icons/lu';

/**
 * Чип-фильтр с явным состоянием: выключен — «+ добавить», включён — галочка
 * и крестик «снять». Без этого включённый и выключенный чип различались
 * только оттенком рамки, и было не понять, что сейчас показано.
 */
export default function FilterChip({ label, count, active, onToggle, palette = 'blue' }) {
    return (
        <Box
            as="button"
            type="button"
            px={3}
            py={1}
            borderRadius="full"
            borderWidth="1px"
            borderColor={active ? `${palette}.solid` : 'border'}
            bg={active ? `${palette}.subtle` : 'bg.panel'}
            color={active ? `${palette}.fg` : 'fg.muted'}
            fontSize="sm"
            cursor="pointer"
            aria-pressed={active}
            onClick={onToggle}
            title={active ? 'Снять фильтр' : 'Включить фильтр'}
        >
            <HStack gap={1.5}>
                {active ? <LuCheck size={13} /> : <LuPlus size={13} />}
                <Text as="span" fontWeight={active ? '600' : '400'}>{label}{count !== undefined ? ` · ${count}` : ''}</Text>
                {active && <LuX size={13} style={{ opacity: 0.7 }} />}
            </HStack>
        </Box>
    );
}
