import { useMemo, useState } from 'react';
import { Badge, Box, HStack, Spinner, Table, Text, VStack } from '@chakra-ui/react';
import { LuExternalLink } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import { Select } from '@/components/ui/select';
import { useCommentFeed } from '@/Crm/Components/useCommentFeed';

/**
 * Заказы или реализации партнёра отдельной вкладкой.
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
 * @param {Array<{id: number, name: string}>} organizations — наши юрлица для фильтра
 * @param {boolean} organizationsEnabled — показ организаций включён флагом
 */
export default function ClientDocuments({ clientId, type, organizations = [], organizationsEnabled = false }) {
    // Фильтр по нашей организации: у партнёра бывают документы на разные юрлица,
    // и сверять с ним конкретную накладную удобнее по одному из них.
    const [organizationId, setOrganizationId] = useState('');

    const params = useMemo(
        () => (organizationId ? { types: [type], organization_id: organizationId } : { types: [type] }),
        [type, organizationId],
    );

    const feed = useCommentFeed(`/crm/partners/${clientId}/timeline`, params);

    const isOrder = type === 'order';

    const filter = organizationsEnabled ? (
        <HStack gap={2} align="center">
            <Text fontSize="xs" color="fg.muted" flexShrink="0">Организация</Text>
            <Box maxW="260px" w="full">
                <Select.Root
                    size="sm"
                    value={organizationId ? [organizationId] : []}
                    onValueChange={(e) => setOrganizationId(e.value[0] || '')}
                >
                    <Select.Trigger>
                        <Select.ValueText placeholder="Все организации" />
                    </Select.Trigger>
                    <Select.Content>
                        <Select.Item item="">Все организации</Select.Item>
                        <Select.Item item="none">Не указана</Select.Item>
                        {organizations?.map((organization) => (
                            <Select.Item key={organization.id} item={String(organization.id)}>
                                {organization.name}
                            </Select.Item>
                        ))}
                    </Select.Content>
                </Select.Root>
            </Box>
        </HStack>
    ) : null;

    if (feed.loading && feed.entries.length === 0) {
        return (
            <VStack align="stretch" gap={3}>
                {filter}
                <HStack justify="center" py={8}><Spinner size="sm" /></HStack>
            </VStack>
        );
    }

    if (feed.entries.length === 0) {
        return (
            <VStack align="stretch" gap={3}>
                {filter}
                <Box py={6}>
                    <Text fontSize="sm" color="fg.muted">
                        {feed.failed
                            ? 'Список недоступен.'
                            : organizationId
                                ? 'По этой организации документов нет.'
                                : isOrder ? 'Заказов пока нет.' : 'Реализаций пока нет.'}
                    </Text>
                </Box>
            </VStack>
        );
    }

    return (
        <VStack align="stretch" gap={3}>
            {filter}

            <Text fontSize="xs" color="fg.muted">
                {isOrder ? 'Заказов' : 'Реализаций'}: {feed.total}
            </Text>

            <Box borderWidth="1px" borderColor="border.muted" borderRadius="md" overflowX="auto">
                <Table.Root size="sm" variant="line">
                    <Table.Header>
                        <Table.Row>
                            <Table.ColumnHeader>Документ</Table.ColumnHeader>
                            <Table.ColumnHeader>Дата</Table.ColumnHeader>
                            {organizationsEnabled && (
                                <Table.ColumnHeader>Организация</Table.ColumnHeader>
                            )}
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
                                {organizationsEnabled && (
                                    <Table.Cell>
                                        {entry.organization ? (
                                            <HStack gap={1}>
                                                <Text fontSize="sm">{entry.organization.name}</Text>
                                                {entry.organization.is_stub && (
                                                    <Badge colorPalette="orange" variant="subtle" size="sm">
                                                        не заведена
                                                    </Badge>
                                                )}
                                            </HStack>
                                        ) : (
                                            <Text fontSize="sm" color="fg.muted">—</Text>
                                        )}
                                        {entry.warehouse && (
                                            <Text fontSize="xs" color="fg.muted">склад: {entry.warehouse}</Text>
                                        )}
                                    </Table.Cell>
                                )}
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
