import { useEffect, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Badge, Box, Card, HStack, SimpleGrid, Text, VStack } from '@chakra-ui/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Button } from '@/components/ui/button';
import ContractForm from '@/Crm/Components/ContractForm';
import EntityCrmPanel from '@/Crm/Components/EntityCrmPanel';
import { useConfirmDelete } from '@/shared/Panel/useConfirmDelete';
import { ConfirmDialog } from '@/shared/Panel/ConfirmDialog';
import { LuArrowLeft, LuPencil, LuTrash2 } from 'react-icons/lu';

function InfoRow({ label, value, color }) {
    return (
        <Box>
            <Text fontSize="xs" color="gray.500" mb="0.5">{label}</Text>
            <Text fontSize="sm" fontWeight="500" color={color}>{value || '—'}</Text>
        </Box>
    );
}

/**
 * Карточка договора: реквизиты, сканы, задачи, комментарии.
 *
 * Правка — инлайн, той же формой, что и создание. `?edit=1` открывает её сразу:
 * так работает карандаш в списке.
 */
export default function Show({
    contract,
    categories = [],
    statuses = [],
    paymentTerms = [],
    forms = [],
    managers = [],
    can = {},
}) {
    const { url } = usePage();
    const [editing, setEditing] = useState(false);

    useEffect(() => {
        if (url.includes('edit=1') && can.edit) {
            setEditing(true);
        }
    }, [url, can.edit]);

    const del = useConfirmDelete({
        title: 'Удалить договор?',
        description: () => `Договор ${contract.number} с «${contract.counterparty_name}» будет удалён вместе со сканами.`,
        onConfirm: () => router.delete(route('crm.contracts.destroy', contract.id), {
            onSuccess: () => router.visit(route('crm.contracts.index')),
        }),
    });

    const validity = contract.valid_until
        ? `${contract.valid_from ? `с ${contract.valid_from} ` : ''}до ${contract.valid_until}`
        : (contract.valid_from ? `с ${contract.valid_from}, бессрочный` : 'бессрочный');

    return (
        <>
            <Head title={`CRM — Договор ${contract.number}`} />
            <PageHeader
                title={`Договор ${contract.number}`}
                description={contract.counterparty_name}
                actions={(
                    <HStack gap={2}>
                        <Link href={route('crm.contracts.index', contract.category ? { category_id: contract.category.id } : {})}>
                            <Button size="sm" variant="ghost"><LuArrowLeft /> К реестру</Button>
                        </Link>
                        {can.edit && !editing && (
                            <Button size="sm" variant="outline" onClick={() => setEditing(true)}><LuPencil /> Изменить</Button>
                        )}
                        {can.delete && (
                            <Button size="sm" variant="outline" colorPalette="red" onClick={() => del.request(contract)}><LuTrash2 /> Удалить</Button>
                        )}
                    </HStack>
                )}
            />

            <VStack align="stretch" gap={4}>
                {editing ? (
                    <Card.Root>
                        <Card.Body>
                            <ContractForm
                                contract={contract}
                                categories={categories}
                                statuses={statuses}
                                paymentTerms={paymentTerms}
                                forms={forms}
                                managers={managers}
                                onSaved={() => { setEditing(false); router.reload(); }}
                                onCancel={() => setEditing(false)}
                            />
                        </Card.Body>
                    </Card.Root>
                ) : (
                    <Card.Root>
                        <Card.Body>
                            <HStack gap={2} mb={4} flexWrap="wrap">
                                <Badge colorPalette={contract.status_color}>{contract.status_label}</Badge>
                                {contract.payment_terms_label && <Badge variant="subtle" colorPalette={contract.payment_terms_color}>{contract.payment_terms_label}</Badge>}
                                {contract.form_label && <Badge variant="outline" colorPalette={contract.form_color}>{contract.form_label}</Badge>}
                                {contract.is_expired && <Badge colorPalette="red">срок истёк</Badge>}
                                {!contract.is_expired && contract.is_expiring && <Badge colorPalette="orange">срок истекает</Badge>}
                                {!contract.is_visible_in_cabinet && <Badge colorPalette="gray">скрыт от партнёра</Badge>}
                            </HStack>

                            <SimpleGrid columns={{ base: 1, md: 3 }} gap={4}>
                                <InfoRow label="Категория" value={contract.category?.name} />
                                <InfoRow label="Дата договора" value={contract.date} />
                                <InfoRow label="Дата подписания" value={contract.signed_at} />
                                <InfoRow
                                    label="Срок действия"
                                    value={validity}
                                    color={contract.is_expired ? 'red.500' : (contract.is_expiring ? 'orange.500' : undefined)}
                                />
                                <Box>
                                    <Text fontSize="xs" color="gray.500" mb="0.5">Контрагент</Text>
                                    {contract.company_details
                                        ? (
                                            <Link href={contract.company_details.url}>
                                                <Text fontSize="sm" fontWeight="500" color="blue.600">{contract.company_details.name}</Text>
                                            </Link>
                                        )
                                        : <Text fontSize="sm" fontWeight="500">{contract.counterparty_name}</Text>}
                                    {contract.company_details?.tax_id && (
                                        <Text fontSize="xs" color="fg.muted">
                                            ИНН {contract.company_details.tax_id}
                                            {contract.company_details.tax_code ? ` / КПП ${contract.company_details.tax_code}` : ''}
                                        </Text>
                                    )}
                                </Box>
                                <Box>
                                    <Text fontSize="xs" color="gray.500" mb="0.5">Партнёр</Text>
                                    {contract.partner_details
                                        ? (
                                            <Link href={contract.partner_details.url}>
                                                <Text fontSize="sm" fontWeight="500" color="blue.600">{contract.partner_details.name}</Text>
                                            </Link>
                                        )
                                        : <Text fontSize="sm" color="fg.muted">не привязан</Text>}
                                </Box>
                                <InfoRow label="Ответственный" value={contract.manager?.name} />
                                <InfoRow label="Заведён" value={contract.created_at ? `${contract.created_at}${contract.created_by ? `, ${contract.created_by}` : ''}` : null} />
                                <InfoRow label="Изменён" value={contract.updated_at} />
                            </SimpleGrid>

                            {contract.comment && (
                                <Box mt={4} p={3} bg="bg.subtle" borderRadius="md">
                                    <Text fontSize="xs" color="gray.500" mb="0.5">Комментарий</Text>
                                    <Text fontSize="sm" whiteSpace="pre-wrap">{contract.comment}</Text>
                                </Box>
                            )}
                        </Card.Body>
                    </Card.Root>
                )}

                {/* Сканы, задачи и комментарии — общие панели CRM: договор в CrmEntityMap. */}
                <EntityCrmPanel entityType="contract" entityId={contract.id} title="Сканы, задачи и комментарии" />
            </VStack>

            <ConfirmDialog {...del.dialogProps} />
        </>
    );
}

Show.layout = (page) => <CrmLayout>{page}</CrmLayout>;
