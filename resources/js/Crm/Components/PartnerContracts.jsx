import { Link } from '@inertiajs/react';
import { Badge, Box, Card, HStack, SimpleGrid, Text, VStack } from '@chakra-ui/react';
import { Button } from '@/components/ui/button';
import RowActions from '@/shared/Panel/RowActions';
import { LuFilePlus } from 'react-icons/lu';

/**
 * Договоры партнёра во вкладке его карточки — по всем его юрлицам.
 *
 * Список, а не таблица: у большинства партнёров договор один-два.
 */
export default function PartnerContracts({ contracts = [], createHref = null }) {
    return (
        <VStack align="stretch" gap={2} py={2}>
            {!contracts.length && (
                <Text fontSize="sm" color="fg.muted" py={2}>
                    Договоров в реестре нет.
                </Text>
            )}

            {contracts.map((contract) => (
                <Card.Root key={contract.id} size="sm" variant="outline">
                    <Card.Body>
                        <SimpleGrid columns={{ base: 1, md: 5 }} gap={3} alignItems="center">
                            <Box>
                                <Text fontSize="sm" fontWeight="600">{contract.number}</Text>
                                <Text fontSize="xs" color="fg.muted">
                                    {contract.date ? `от ${contract.date}` : 'без даты'}
                                    {contract.category ? ` · ${contract.category.name}` : ''}
                                </Text>
                            </Box>
                            <Box>
                                <Text fontSize="xs" color="gray.500">Контрагент</Text>
                                <Text fontSize="sm">{contract.counterparty_name}</Text>
                            </Box>
                            <HStack gap={1} flexWrap="wrap">
                                <Badge size="sm" colorPalette={contract.status_color}>{contract.status_label}</Badge>
                                {contract.payment_terms_label && <Badge size="sm" variant="subtle" colorPalette={contract.payment_terms_color}>{contract.payment_terms_label}</Badge>}
                            </HStack>
                            <Box>
                                <Text fontSize="xs" color="gray.500">Действует</Text>
                                <Text fontSize="sm" color={contract.is_expired ? 'red.500' : undefined}>
                                    {contract.valid_until ? `до ${contract.valid_until}` : 'бессрочно'}
                                </Text>
                            </Box>
                            <HStack justify="flex-end">
                                <RowActions size="xs" view={{ href: route('crm.contracts.show', contract.id) }} />
                            </HStack>
                        </SimpleGrid>
                    </Card.Body>
                </Card.Root>
            ))}

            {createHref && (
                <HStack>
                    <Link href={createHref}>
                        <Button size="xs" variant="outline"><LuFilePlus /> Завести договор</Button>
                    </Link>
                </HStack>
            )}
        </VStack>
    );
}
