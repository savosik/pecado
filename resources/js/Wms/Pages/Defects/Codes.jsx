import { Head, usePage } from '@inertiajs/react';
import { Badge, Box, Card, HStack, Table, Text } from '@chakra-ui/react';
import { LuDownload, LuListOrdered } from 'react-icons/lu';
import WmsLayout from '@/Wms/Layouts/WmsLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Button } from '@/components/ui/button';

/**
 * Read-only справочник кодов дефектов для комплектовщика.
 *
 * В печатном документе 1С дефекты перечислены кодами (id типа дефекта) — эта
 * таблица служит легендой. Редактирование справочника — в админке, здесь только
 * просмотр и выгрузка в Excel.
 */
export default function DefectsCodes() {
    const { codes } = usePage().props;

    return (
        <>
            <Head title="Коды дефектов — Склад" />
            <PageHeader
                title="Коды дефектов"
                description="Расшифровка кодов дефектов из печатного документа 1С. Код — это номер типа дефекта."
                actions={
                    <Button asChild size="sm">
                        <a href="/wms/defects/codes/export">
                            <LuDownload /> Скачать Excel
                        </a>
                    </Button>
                }
            />

            <Card.Root maxW="3xl">
                <Card.Body>
                    {codes.length === 0 ? (
                        <HStack gap={2} color="fg.muted" py={6} justify="center">
                            <LuListOrdered size={18} />
                            <Text fontSize="sm">
                                Справочник дефектов пуст. Его заполняет администратор в разделе «Справочник дефектов».
                            </Text>
                        </HStack>
                    ) : (
                        <Box overflowX="auto">
                            <Table.Root size="sm" variant="line">
                                <Table.Header>
                                    <Table.Row>
                                        <Table.ColumnHeader w="96px">Код</Table.ColumnHeader>
                                        <Table.ColumnHeader>Дефект</Table.ColumnHeader>
                                        <Table.ColumnHeader w="120px" textAlign="center">Активен</Table.ColumnHeader>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {codes.map((code) => (
                                        <Table.Row key={code.id}>
                                            <Table.Cell>
                                                <Text fontSize="sm" fontWeight="semibold" fontVariantNumeric="tabular-nums">
                                                    {code.id}
                                                </Text>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Text fontSize="sm" color={code.is_active ? undefined : 'fg.muted'}>
                                                    {code.name}
                                                </Text>
                                            </Table.Cell>
                                            <Table.Cell textAlign="center">
                                                {code.is_active ? (
                                                    <Badge colorPalette="green" variant="subtle">да</Badge>
                                                ) : (
                                                    <Badge colorPalette="gray" variant="subtle">нет</Badge>
                                                )}
                                            </Table.Cell>
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </Table.Root>
                        </Box>
                    )}

                    <Text fontSize="xs" color="fg.muted" mt={3}>
                        Неактивные коды тоже показаны: старые партии могут на них ссылаться, а документ всё равно нужно расшифровать.
                    </Text>
                </Card.Body>
            </Card.Root>
        </>
    );
}

DefectsCodes.layout = (page) => <WmsLayout>{page}</WmsLayout>;
