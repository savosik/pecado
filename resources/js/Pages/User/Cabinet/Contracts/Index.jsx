import { Head } from '@inertiajs/react';
import { Badge, Box, Card, Flex, HStack, SimpleGrid, Text, VStack } from '@chakra-ui/react';
import CabinetLayout from '@/Pages/User/Cabinet/CabinetLayout';
import { Button } from '@/components/ui/button';
import { LuDownload, LuFilePen } from 'react-icons/lu';

// Карточки — как в остальных разделах кабинета («Мои заказы»): белый фон,
// скруглённый xl, тонкая рамка. Без bg карточка сливается с песочной подложкой.
const cardProps = {
    bg: 'bg',
    borderRadius: 'xl',
    border: '1px solid',
    borderColor: 'border.muted',
};

function InfoRow({ label, value, children }) {
    return (
        <Box>
            <Text fontSize="xs" color="fg.muted" mb="0.5">{label}</Text>
            <Text fontSize="sm" fontWeight="600" color="fg">{value || '—'}</Text>
            {children}
        </Box>
    );
}

/**
 * Договоры партнёра в личном кабинете.
 *
 * Только чтение: договор ведёт менеджер. Партнёр видит статус, срок и сканы,
 * которые менеджер приложил к договору.
 */
export default function Index({ contracts = [] }) {
    return (
        <CabinetLayout title="Договоры">
            <Head title="Договоры" />
            <VStack align="stretch" gap={4}>
                <Text fontSize="sm" color="fg.muted">
                    Договоры, заключённые с вашими организациями. По вопросам подписания обращайтесь к своему менеджеру.
                </Text>

                {!contracts.length && (
                    <Card.Root {...cardProps}>
                        <Card.Body p="10" textAlign="center">
                            <VStack gap={3}>
                                <Flex
                                    align="center" justify="center"
                                    w="16" h="16" borderRadius="full"
                                    bg="bg.muted" mx="auto"
                                >
                                    <LuFilePen size={28} color="var(--chakra-colors-gray-400)" />
                                </Flex>
                                <Text fontWeight="600" fontSize="lg">Договоров пока нет</Text>
                                <Text color="gray.500" fontSize="sm">Когда менеджер заведёт договор, он появится здесь</Text>
                            </VStack>
                        </Card.Body>
                    </Card.Root>
                )}

                {contracts.map((contract) => (
                    <Card.Root key={contract.id} {...cardProps}>
                        <Card.Body p="5">
                            <HStack justify="space-between" align="start" mb={4} flexWrap="wrap" gap={2}>
                                <Box>
                                    <Text fontWeight="700" fontSize="lg" color="fg" lineHeight="short">
                                        Договор {contract.number}
                                    </Text>
                                    {contract.date && <Text fontSize="xs" color="fg.muted">от {contract.date}</Text>}
                                </Box>
                                <HStack gap={1} flexWrap="wrap">
                                    <Badge colorPalette={contract.status_color}>{contract.status_label}</Badge>
                                    {contract.payment_terms_label && <Badge variant="subtle">{contract.payment_terms_label}</Badge>}
                                    {contract.form_label && <Badge variant="outline">{contract.form_label}</Badge>}
                                    {contract.is_expired && <Badge colorPalette="red">срок истёк</Badge>}
                                </HStack>
                            </HStack>

                            <SimpleGrid columns={{ base: 1, md: 2, lg: 5 }} gap={4}>
                                <InfoRow label="Ваша организация" value={contract.company?.name}>
                                    {contract.company?.tax_id && <Text fontSize="xs" color="fg.muted">ИНН {contract.company.tax_id}</Text>}
                                </InfoRow>
                                <InfoRow label="Наша организация" value={contract.organization?.name}>
                                    {contract.organization?.tax_id && <Text fontSize="xs" color="fg.muted">ИНН {contract.organization.tax_id}</Text>}
                                </InfoRow>
                                <InfoRow label="Дата подписания" value={contract.signed_at} />
                                <InfoRow
                                    label="Срок действия"
                                    value={contract.valid_until ? `до ${contract.valid_until}` : 'бессрочный'}
                                />
                                <InfoRow label="Ваш менеджер" value={contract.manager?.name}>
                                    {contract.manager?.phone && <Text fontSize="xs" color="fg.muted">{contract.manager.phone}</Text>}
                                    {contract.manager?.email && <Text fontSize="xs" color="fg.muted">{contract.manager.email}</Text>}
                                </InfoRow>
                            </SimpleGrid>

                            {contract.files.length > 0 && (
                                <Box mt={4} pt={4} borderTop="1px solid" borderColor="border.muted">
                                    <Text fontSize="xs" color="fg.muted" mb={2}>Сканы договора</Text>
                                    <HStack gap={2} flexWrap="wrap">
                                        {contract.files.map((file) => (
                                            <Button key={file.id} asChild size="sm" variant="outline" colorPalette="pecado">
                                                <a href={file.url}>
                                                    <LuDownload size={14} />
                                                    {file.name}
                                                    {file.size_label && (
                                                        <Text as="span" fontSize="xs" color="fg.muted" fontWeight="400">
                                                            {file.size_label}
                                                        </Text>
                                                    )}
                                                </a>
                                            </Button>
                                        ))}
                                    </HStack>
                                </Box>
                            )}
                        </Card.Body>
                    </Card.Root>
                ))}
            </VStack>
        </CabinetLayout>
    );
}
