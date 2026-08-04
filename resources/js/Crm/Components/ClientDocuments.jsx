import { Badge, Box, HStack, Spinner, Table, Text, VStack } from '@chakra-ui/react';
import { LuExternalLink } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import { useCommentFeed } from '@/Crm/Components/useCommentFeed';

/**
 * Заказы или реализации клиента отдельной вкладкой.
 *
 * Источник тот же, что у ленты, — эндпоинт хронологии с фильтром по типу.
 * Второй список документов с собственным запросом означал бы вторую выборку
 * с собственными правилами видимости, которая однажды разойдётся с лентой.
 *
 * Здесь таблица, а не карточки ленты: во вкладке документы читают по колонкам
 * («когда, на сколько, в каком статусе»), а не как хронику.
 *
 * @param {number} clientId
 * @param {'order'|'shipment'} type
 */
export default function ClientDocuments({ clientId, type }) {
    const feed = useCommentFeed(`/crm/clients/${clientId}/timeline`, { types: [type] });

    const isOrder = type === 'order';

    if (feed.loading && feed.entries.length === 0) {
        return <HStack justify="center" py={8}><Spinner size="sm" /></HStack>;
    }

    if (feed.entries.length === 0) {
        return (
            <Box py={6}>
                <Text fontSize="sm" color="fg.muted">
                    {feed.failed
                        ? 'Список недоступен.'
                        : isOrder ? 'Заказов пока нет.' : 'Реализаций пока нет.'}
                </Text>
            </Box>
        );
    }

    return (
        <VStack align="stretch" gap={3}>
            <Text fontSize="xs" color="fg.muted">
                {isOrder ? 'Заказов' : 'Реализаций'}: {feed.total}
            </Text>

            <Box borderWidth="1px" borderColor="border.muted" borderRadius="md" overflowX="auto">
                <Table.Root size="sm" variant="line">
                    <Table.Header>
                        <Table.Row>
                            <Table.ColumnHeader>Документ</Table.ColumnHeader>
                            <Table.ColumnHeader>Дата</Table.ColumnHeader>
                            <Table.ColumnHeader>Статус</Table.ColumnHeader>
                            <Table.ColumnHeader textAlign="end">Позиций</Table.ColumnHeader>
                            <Table.ColumnHeader textAlign="end">Сумма</Table.ColumnHeader>
                            <Table.ColumnHeader />
                        </Table.Row>
                    </Table.Header>
                    <Table.Body>
                        {feed.entries.map((entry) => (
                            <Table.Row key={`${entry.type}-${entry.id}`} _hover={{ bg: 'bg.muted' }}>
                                <Table.Cell>
                                    <Text fontSize="sm" fontWeight="600">{entry.title}</Text>
                                </Table.Cell>
                                <Table.Cell>
                                    <Text fontSize="sm" color="fg.muted">{entry.happened_at_label}</Text>
                                </Table.Cell>
                                <Table.Cell>
                                    {entry.status_label && (
                                        <Badge colorPalette={entry.status_color || 'gray'} variant="subtle" size="sm">
                                            {entry.status_label}
                                        </Badge>
                                    )}
                                </Table.Cell>
                                <Table.Cell textAlign="end">
                                    <Text fontSize="sm" color="fg.muted">{entry.items_count || '—'}</Text>
                                </Table.Cell>
                                <Table.Cell textAlign="end">
                                    <Text fontSize="sm" fontWeight="600" whiteSpace="nowrap">
                                        {entry.amount_label}
                                    </Text>
                                </Table.Cell>
                                <Table.Cell>
                                    {entry.entity?.url && (
                                        <Button size="xs" variant="ghost" asChild title="Открыть документ">
                                            <a href={entry.entity.url}><LuExternalLink /></a>
                                        </Button>
                                    )}
                                </Table.Cell>
                            </Table.Row>
                        ))}
                    </Table.Body>
                </Table.Root>
            </Box>

            {feed.hasMore && (
                <Button size="sm" variant="outline" onClick={feed.loadMore} loading={feed.loading}>
                    Показать более ранние
                </Button>
            )}
        </VStack>
    );
}
