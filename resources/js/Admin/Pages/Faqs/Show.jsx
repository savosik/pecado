import { Head, usePage, router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader } from '@/Admin/Components';
import { Box, Badge, Card, HStack, SimpleGrid, Text, VStack } from '@chakra-ui/react';
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
    const { faq } = usePage().props;
    const { can } = usePermission();

    return (
        <>
            <Head title={`FAQ: ${faq.title}`} />
            <PageHeader
                title={faq.title}
                backUrl={route('admin.faqs.index')}
                backLabel="К списку FAQ"
                actions={
                    can('faqs.edit') && (
                        <Button size="sm" onClick={() => router.visit(route('admin.faqs.edit', faq.id))}>
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
                            <InfoRow label="ID" value={faq.id?.toString()} />
                            <InfoRow label="Заголовок" value={faq.title} />
                            <InfoRow label="Порядок" value={faq.sort_order?.toString()} />
                            <Box>
                                <Text fontSize="xs" color="gray.500" mb="0.5">Опубликован</Text>
                                <Badge colorPalette={faq.is_published ? 'green' : 'gray'} variant="subtle">
                                    {faq.is_published ? 'Да' : 'Нет'}
                                </Badge>
                            </Box>
                            <InfoRow label="Создан" value={faq.created_at} />
                            <InfoRow label="Обновлён" value={faq.updated_at} />
                        </SimpleGrid>
                    </Card.Body>
                </Card.Root>

                {faq.content && (
                    <Card.Root>
                        <Card.Header><Text fontWeight="semibold" fontSize="lg">Содержимое</Text></Card.Header>
                        <Card.Body>
                            <Text fontSize="sm" whiteSpace="pre-wrap">{faq.content}</Text>
                        </Card.Body>
                    </Card.Root>
                )}

                {faq.regions?.length > 0 && (
                    <Card.Root>
                        <Card.Header><Text fontWeight="semibold" fontSize="lg">Регионы</Text></Card.Header>
                        <Card.Body>
                            <HStack gap={2} flexWrap="wrap">
                                {faq.regions.map((r) => (
                                    <Badge key={r.id} variant="outline">{r.name}</Badge>
                                ))}
                            </HStack>
                        </Card.Body>
                    </Card.Root>
                )}
            </VStack>
        </>
    );
}

Show.layout = (page) => <AdminLayout>{page}</AdminLayout>;
