import { useEffect, useState } from 'react';
import axios from 'axios';
import { Box, HStack, SimpleGrid, Spinner, Table, Text, VStack } from '@chakra-ui/react';
import { Alert } from '@/components/ui/alert';

/**
 * Что партнёр закупает: средний чек, объём и разрез по брендам и категориям.
 *
 * Данные грузятся при первом раскрытии блока, а не вместе с карточкой: разрез
 * по товарам нужен не в каждом открытии, а карточку менеджер открывает десятки
 * раз за день. Цифры считает тот же движок, что и «Грядки», — здесь только показ.
 */
const money = (value) => `${Number(value ?? 0).toLocaleString('ru-RU', { maximumFractionDigits: 0 })} ₽`;

function Stat({ label, value, hint }) {
    return (
        <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="lg" p={3}>
            <Text fontSize="xs" color="fg.muted">{label}</Text>
            <Text fontSize="lg" fontWeight="700">{value}</Text>
            {hint && <Text fontSize="xs" color="fg.muted">{hint}</Text>}
        </Box>
    );
}

function Breakdown({ title, rows, empty }) {
    if (!rows || rows.length === 0) {
        return (
            <Box>
                <Text fontSize="sm" fontWeight="600" mb={2}>{title}</Text>
                <Text fontSize="sm" color="fg.muted">{empty}</Text>
            </Box>
        );
    }

    return (
        <Box>
            <Text fontSize="sm" fontWeight="600" mb={2}>{title}</Text>
            <Table.Root size="sm">
                <Table.Body>
                    {rows.slice(0, 8).map((row) => (
                        <Table.Row key={row.key ?? row.label}>
                            <Table.Cell>
                                <Text fontSize="sm">{row.label}</Text>
                            </Table.Cell>
                            <Table.Cell textAlign="right" w="130px">
                                <Text fontSize="sm">{money(row.amount)}</Text>
                            </Table.Cell>
                        </Table.Row>
                    ))}
                </Table.Body>
            </Table.Root>
        </Box>
    );
}

export default function PartnerPurchases({ clientId }) {
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        let cancelled = false;

        setLoading(true);
        setError(null);

        axios.get(`/crm/partners/${clientId}/insights`)
            .then((res) => {
                if (!cancelled) setData(res.data);
            })
            .catch(() => {
                if (!cancelled) setError('Не удалось загрузить закупки партнёра');
            })
            .finally(() => {
                if (!cancelled) setLoading(false);
            });

        return () => { cancelled = true; };
    }, [clientId]);

    if (loading) {
        return (
            <HStack gap={2} py={4}>
                <Spinner size="sm" />
                <Text fontSize="sm" color="fg.muted">Считаем закупки за 12 месяцев…</Text>
            </HStack>
        );
    }

    if (error) {
        return <Alert status="error" title={error} />;
    }

    const metrics = data?.metrics ?? {};

    if (!metrics.shipments_count) {
        return (
            <Text fontSize="sm" color="fg.muted" py={2}>
                За последние 12 месяцев отгрузок не было — считать средний чек и интересы не по чему.
            </Text>
        );
    }

    return (
        <VStack align="stretch" gap={4} pb={2}>
            <SimpleGrid columns={{ base: 2, md: 4 }} gap={3}>
                <Stat label="Средний чек" value={money(metrics.avg_check)} hint="на одну реализацию" />
                <Stat label="Отгружено за 12 мес" value={money(metrics.total_amount)} />
                <Stat label="Реализаций" value={metrics.shipments_count} />
                <Stat label="Позиций отгружено" value={metrics.items_total_qty} />
            </SimpleGrid>

            <SimpleGrid columns={{ base: 1, md: 2 }} gap={6}>
                <Breakdown
                    title="Бренды, которые берёт"
                    rows={data?.brands}
                    empty="Бренд в отгрузках не указан"
                />
                <Breakdown
                    title="Категории, которые берёт"
                    rows={data?.categories}
                    empty="Категория в отгрузках не указана"
                />
            </SimpleGrid>
        </VStack>
    );
}
