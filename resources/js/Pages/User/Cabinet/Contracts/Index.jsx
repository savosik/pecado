import { Head } from '@inertiajs/react';
import { Badge, Box, Card, HStack, SimpleGrid, Text, VStack } from '@chakra-ui/react';
import CabinetLayout from '@/Pages/User/Cabinet/CabinetLayout';
import { LuDownload, LuFilePen } from 'react-icons/lu';

function InfoRow({ label, value }) {
    return (
        <Box>
            <Text fontSize="xs" color="gray.500" mb="0.5">{label}</Text>
            <Text fontSize="sm" fontWeight="500">{value || '—'}</Text>
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
                    <Card.Root>
                        <Card.Body>
                            <HStack gap={3} color="fg.muted">
                                <LuFilePen />
                                <Text fontSize="sm">Договоров пока нет. Когда менеджер заведёт договор, он появится здесь.</Text>
                            </HStack>
                        </Card.Body>
                    </Card.Root>
                )}

                {contracts.map((contract) => (
                    <Card.Root key={contract.id}>
                        <Card.Body>
                            <HStack justify="space-between" align="start" mb={3} flexWrap="wrap" gap={2}>
                                <Box>
                                    <Text fontWeight="600">Договор {contract.number}</Text>
                                    {contract.date && <Text fontSize="xs" color="fg.muted">от {contract.date}</Text>}
                                </Box>
                                <HStack gap={1} flexWrap="wrap">
                                    <Badge colorPalette={contract.status_color}>{contract.status_label}</Badge>
                                    {contract.payment_terms_label && <Badge variant="subtle">{contract.payment_terms_label}</Badge>}
                                    {contract.form_label && <Badge variant="outline">{contract.form_label}</Badge>}
                                    {contract.is_expired && <Badge colorPalette="red">срок истёк</Badge>}
                                </HStack>
                            </HStack>

                            <SimpleGrid columns={{ base: 1, md: 2, lg: 5 }} gap={3}>
                                <Box>
                                    <Text fontSize="xs" color="gray.500" mb="0.5">Ваша организация</Text>
                                    <Text fontSize="sm" fontWeight="500">{contract.company?.name || '—'}</Text>
                                    {contract.company?.tax_id && <Text fontSize="xs" color="fg.muted">ИНН {contract.company.tax_id}</Text>}
                                </Box>
                                <Box>
                                    <Text fontSize="xs" color="gray.500" mb="0.5">Наша организация</Text>
                                    <Text fontSize="sm" fontWeight="500">{contract.organization?.name || '—'}</Text>
                                    {contract.organization?.tax_id && <Text fontSize="xs" color="fg.muted">ИНН {contract.organization.tax_id}</Text>}
                                </Box>
                                <InfoRow label="Дата подписания" value={contract.signed_at} />
                                <InfoRow
                                    label="Срок действия"
                                    value={contract.valid_until ? `до ${contract.valid_until}` : 'бессрочный'}
                                />
                                <Box>
                                    <Text fontSize="xs" color="gray.500" mb="0.5">Ваш менеджер</Text>
                                    <Text fontSize="sm" fontWeight="500">{contract.manager?.name || '—'}</Text>
                                    {contract.manager?.phone && <Text fontSize="xs" color="fg.muted">{contract.manager.phone}</Text>}
                                    {contract.manager?.email && <Text fontSize="xs" color="fg.muted">{contract.manager.email}</Text>}
                                </Box>
                            </SimpleGrid>

                            {contract.files.length > 0 && (
                                <Box mt={4}>
                                    <Text fontSize="xs" color="gray.500" mb={1}>Файлы</Text>
                                    <VStack align="start" gap={1}>
                                        {contract.files.map((file) => (
                                            <a key={file.id} href={file.url}>
                                                <HStack gap={2} fontSize="sm" color="blue.600">
                                                    <LuDownload size={14} />
                                                    <Text>{file.name}</Text>
                                                    <Text fontSize="xs" color="fg.muted">{file.size_label}</Text>
                                                </HStack>
                                            </a>
                                        ))}
                                    </VStack>
                                </Box>
                            )}
                        </Card.Body>
                    </Card.Root>
                ))}
            </VStack>
        </CabinetLayout>
    );
}
