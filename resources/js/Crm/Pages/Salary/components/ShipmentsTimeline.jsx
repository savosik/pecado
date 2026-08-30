import { Badge, Box, HStack, Text, VStack } from '@chakra-ui/react';
import { fmtDay, fmtRub0, plural } from './format';

/**
 * Лента отгрузок месяца: что именно легло в выручку.
 *
 * Суммы на фронте не складываются — итог считает сервер тем же движком,
 * что «Планы продаж». Лента — чтобы было видно, из каких документов
 * сложилось число в плитке, и на какой отгрузке появился новый клиент.
 */
export default function ShipmentsTimeline({ timeline }) {
    const rows = timeline?.rows ?? [];

    if (rows.length === 0) {
        return null;
    }

    const byDay = rows.reduce((acc, row) => {
        (acc[row.date] ||= []).push(row);
        return acc;
    }, {});
    const days = Object.keys(byDay).sort((a, b) => (a < b ? 1 : -1));

    return (
        <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={4}>
            <HStack justify="space-between" mb={3} flexWrap="wrap" gap={2}>
                <Text fontSize="xs" color="fg.muted" fontWeight="500">Отгрузки месяца</Text>
                <Text fontSize="xs" color="fg.muted">
                    {timeline.total_count} {plural(timeline.total_count, 'реализация', 'реализации', 'реализаций')}
                    {timeline.truncated ? ` · показаны последние ${rows.length}` : ''}
                </Text>
            </HStack>
            <VStack align="stretch" gap={3} maxH="420px" overflowY="auto">
                {days.map((day) => (
                    <Box key={day}>
                        <Text fontSize="xs" color="fg.subtle" mb={1}>{fmtDay(day)}</Text>
                        <VStack align="stretch" gap={1}>
                            {byDay[day].map((row) => (
                                <HStack key={row.id} justify="space-between" gap={3} fontSize="sm">
                                    <HStack gap={2} minW={0}>
                                        <Text color="fg.muted" whiteSpace="nowrap">{row.number ?? '—'}</Text>
                                        <Text lineClamp={1}>{row.partner_name}</Text>
                                        {row.is_planned && <Badge size="xs" variant="subtle" colorPalette="blue">плановый</Badge>}
                                    </HStack>
                                    <Text fontVariantNumeric="tabular-nums" whiteSpace="nowrap">{fmtRub0(row.amount)}</Text>
                                </HStack>
                            ))}
                        </VStack>
                    </Box>
                ))}
            </VStack>
        </Box>
    );
}
