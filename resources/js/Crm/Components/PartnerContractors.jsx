import { Badge, Box, Card, HStack, SimpleGrid, Text, VStack } from '@chakra-ui/react';
import { formatPrice } from '@/utils/formatPrice';
import RowActions from '@/shared/Panel/RowActions';

/**
 * Юрлица партнёра во вкладке его карточки.
 *
 * Список, а не таблица: у большинства партнёров юрлицо одно, и таблица из одной
 * строки с шапкой в карточке выглядит тяжелее, чем сама информация.
 *
 * Плана и факта здесь нет намеренно — они считаются по партнёру. Строка показывает
 * то, что у юрлица своё: реквизиты, долг из 1С и объём переписки.
 */
export default function PartnerContractors({ contractors = [] }) {
    if (!contractors.length) {
        return (
            <Text fontSize="sm" color="fg.muted" py={4}>
                У партнёра нет контрагентов. Юрлица приезжают из 1С вместе с документами.
            </Text>
        );
    }

    return (
        <VStack align="stretch" gap={2} py={2}>
            {contractors.map((contractor) => (
                <Card.Root key={contractor.id} size="sm" variant="outline">
                    <Card.Body>
                        <SimpleGrid columns={{ base: 1, md: 4 }} gap={3} alignItems="center">
                            <Box>
                                <HStack gap={2}>
                                    <Text fontSize="sm" fontWeight="600">
                                        {contractor.name}
                                    </Text>
                                    {contractor.is_default && (
                                        <Badge colorPalette="blue" variant="subtle" size="sm">основной</Badge>
                                    )}
                                </HStack>
                                {contractor.legal_name && contractor.legal_name !== contractor.name && (
                                    <Text fontSize="xs" color="fg.muted">{contractor.legal_name}</Text>
                                )}
                            </Box>

                            <Box>
                                <Text fontSize="xs" color="gray.500">ИНН / КПП</Text>
                                <Text fontSize="sm" fontFamily="mono">
                                    {contractor.tax_id || '—'}
                                    {contractor.tax_code ? ` / ${contractor.tax_code}` : ''}
                                </Text>
                            </Box>

                            <Box>
                                <Text fontSize="xs" color="gray.500">Баланс по данным 1С</Text>
                                {contractor.balance === null
                                    ? <Text fontSize="sm" color="fg.muted">—</Text>
                                    : (
                                        <HStack gap={2} wrap="wrap">
                                            <Text
                                                fontSize="sm"
                                                color={contractor.balance < 0 ? 'red.500' : undefined}
                                            >
                                                {formatPrice(contractor.balance)}
                                            </Text>
                                            {contractor.overdue_debt > 0 && (
                                                <Badge colorPalette="red" variant="subtle" size="sm">
                                                    просрочка {formatPrice(contractor.overdue_debt)}
                                                </Badge>
                                            )}
                                        </HStack>
                                    )}
                            </Box>

                            <HStack gap={3} justify={{ base: 'start', md: 'end' }}>
                                {contractor.open_tasks_count > 0 && (
                                    <Badge colorPalette="purple" variant="subtle">
                                        задач: {contractor.open_tasks_count}
                                    </Badge>
                                )}
                                {contractor.comments_count > 0 && (
                                    <Text fontSize="xs" color="fg.muted">
                                        комментариев: {contractor.comments_count}
                                    </Text>
                                )}
                                <RowActions size="xs" view={{ href: route('crm.contractors.show', contractor.id) }} />
                            </HStack>
                        </SimpleGrid>
                    </Card.Body>
                </Card.Root>
            ))}
        </VStack>
    );
}
