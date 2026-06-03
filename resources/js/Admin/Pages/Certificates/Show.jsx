import { Head, usePage, router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader } from '@/Admin/Components';
import { Box, Card, HStack, Image, SimpleGrid, Text, VStack } from '@chakra-ui/react';
import { LuPencil } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import { usePermission } from '@/Admin/hooks/usePermission';

function InfoRow({ label, value }) {
    return (
        <Box>
            <Text fontSize="xs" color="gray.500" mb="0.5">{label}</Text>
            <Text fontSize="sm" fontWeight="500">{value || '—'}</Text>
        </Box>
    );
}

export default function Show() {
    const { certificate } = usePage().props;
    const { can } = usePermission();

    return (
        <>
            <Head title={`Сертификат: ${certificate.name}`} />
            <PageHeader
                title={certificate.name}
                backUrl={route('admin.certificates.index')}
                backLabel="К списку сертификатов"
                actions={
                    can('certificates.edit') && (
                        <Button size="sm" onClick={() => router.visit(route('admin.certificates.edit', certificate.id))}>
                            <LuPencil /> Редактировать
                        </Button>
                    )
                }
            />
            <VStack gap={4} align="stretch">
                <Card.Root>
                    <Card.Header>
                        <Text fontWeight="semibold" fontSize="lg">Основная информация</Text>
                    </Card.Header>
                    <Card.Body>
                        <SimpleGrid columns={{ base: 2, md: 3 }} gap={4}>
                            <InfoRow label="ID" value={certificate.id?.toString()} />
                            <InfoRow label="Название" value={certificate.name} />
                            <InfoRow label="Тип" value={certificate.type} />
                            <InfoRow label="Внешний ID" value={certificate.external_id} />
                            <InfoRow label="Дата выдачи" value={certificate.issued_at} />
                            <InfoRow label="Дата истечения" value={certificate.expires_at} />
                            <InfoRow label="Создан" value={certificate.created_at} />
                            <InfoRow label="Обновлён" value={certificate.updated_at} />
                        </SimpleGrid>
                    </Card.Body>
                </Card.Root>

                {certificate.media?.length > 0 && (
                    <Card.Root>
                        <Card.Header>
                            <Text fontWeight="semibold" fontSize="lg">Файлы ({certificate.media.length})</Text>
                        </Card.Header>
                        <Card.Body>
                            <VStack gap={2} align="stretch">
                                {certificate.media.map((m) => (
                                    <HStack key={m.id} gap={3} p={2} borderWidth="1px" borderRadius="md">
                                        <Text fontSize="sm" flex={1}>{m.name}</Text>
                                        <Text fontSize="xs" color="gray.500">{Math.round(m.size / 1024)} КБ</Text>
                                        <Button size="xs" variant="outline" as="a" href={m.url} target="_blank">
                                            Скачать
                                        </Button>
                                    </HStack>
                                ))}
                            </VStack>
                        </Card.Body>
                    </Card.Root>
                )}

                {certificate.products?.length > 0 && (
                    <Card.Root>
                        <Card.Header>
                            <Text fontWeight="semibold" fontSize="lg">Товары ({certificate.products.length})</Text>
                        </Card.Header>
                        <Card.Body>
                            <VStack gap={2} align="stretch">
                                {certificate.products.map((p) => (
                                    <HStack key={p.id} gap={3}>
                                        {p.image_url && (
                                            <Image src={p.image_url} alt={p.name} w="40px" h="40px" objectFit="contain" borderRadius="sm" />
                                        )}
                                        <Text fontSize="sm">{p.name}</Text>
                                        <Text fontSize="xs" color="gray.500">{p.sku}</Text>
                                    </HStack>
                                ))}
                            </VStack>
                        </Card.Body>
                    </Card.Root>
                )}
            </VStack>
        </>
    );
}

Show.layout = (page) => <AdminLayout>{page}</AdminLayout>;
