import { Head, Link } from '@inertiajs/react';
import { Badge, Box, Card, HStack, SimpleGrid, Table, Tabs, Text, VStack } from '@chakra-ui/react';
import {
    AccordionItem,
    AccordionItemContent,
    AccordionItemTrigger,
    AccordionRoot,
} from '@/components/ui/accordion';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { usePermission } from '@/shared/Panel/usePermission';
import CommentThread from '@/Crm/Components/CommentThread';
import TaskPanel from '@/Crm/Components/TaskPanel';
import AttachmentPanel from '@/Crm/Components/AttachmentPanel';
import { formatPrice } from '@/utils/formatPrice';

function InfoRow({ label, value }) {
    return (
        <Box>
            <Text fontSize="xs" color="gray.500" mb="0.5">{label}</Text>
            <Text fontSize="sm" fontWeight="500">{value || '—'}</Text>
        </Box>
    );
}

/**
 * Таблица последних документов юрлица. Заказы и реализации устроены одинаково,
 * поэтому компонент один.
 */
function DocumentsTable({ rows, emptyMessage }) {
    if (!rows.length) {
        return <Text fontSize="sm" color="fg.muted" py={4}>{emptyMessage}</Text>;
    }

    return (
        <Box overflowX="auto">
            <Table.Root size="sm" variant="line">
                <Table.Header>
                    <Table.Row>
                        <Table.ColumnHeader>Номер</Table.ColumnHeader>
                        <Table.ColumnHeader>Дата</Table.ColumnHeader>
                        <Table.ColumnHeader>Статус</Table.ColumnHeader>
                        <Table.ColumnHeader textAlign="end">Сумма</Table.ColumnHeader>
                    </Table.Row>
                </Table.Header>
                <Table.Body>
                    {rows.map((row) => (
                        <Table.Row key={row.id}>
                            <Table.Cell>
                                <Link href={row.url}>
                                    <Text fontSize="sm" color="blue.500" _hover={{ textDecoration: 'underline' }}>
                                        № {row.number}
                                    </Text>
                                </Link>
                                {row.erp_number && (
                                    <Text fontSize="xs" color="fg.muted">1С: {row.erp_number}</Text>
                                )}
                            </Table.Cell>
                            <Table.Cell>{row.date || '—'}</Table.Cell>
                            <Table.Cell>{row.status_label || '—'}</Table.Cell>
                            <Table.Cell textAlign="end">{formatPrice(row.total)}</Table.Cell>
                        </Table.Row>
                    ))}
                </Table.Body>
            </Table.Root>
        </Box>
    );
}

export default function Show({ contractor, documents, canSeeDocuments = false }) {
    const { can } = usePermission();

    const canViewComments = can('crm-comments.view');
    const canViewTasks = can('crm-tasks.view');
    const canViewFiles = can('crm-attachments.view');

    const defaultTab = canViewComments ? 'comments' : (canViewTasks ? 'tasks' : 'files');
    const balance = contractor.balance;

    return (
        <>
            <Head title={`CRM — ${contractor.name}`} />
            <PageHeader
                title={contractor.name}
                description="Карточка контрагента — юрлица партнёра"
            />

            <VStack gap={3} align="stretch">
                <Card.Root>
                    <Card.Body>
                        <SimpleGrid columns={{ base: 2, md: 4 }} gap={4}>
                            <Box>
                                <Text fontSize="xs" color="gray.500" mb="0.5">Партнёр</Text>
                                {contractor.partner
                                    ? (
                                        <Link href={route('crm.clients.show', contractor.partner.id)}>
                                            <Text fontSize="sm" fontWeight="600" color="blue.500">
                                                {contractor.partner.name}
                                            </Text>
                                        </Link>
                                    )
                                    : (
                                        <Badge colorPalette="orange" variant="subtle">
                                            Не привязан к партнёру
                                        </Badge>
                                    )}
                            </Box>
                            <InfoRow label="ИНН" value={contractor.tax_id} />
                            <InfoRow label="КПП" value={contractor.tax_code} />
                            <Box>
                                <Text fontSize="xs" color="gray.500" mb="0.5">Баланс по данным 1С</Text>
                                {balance
                                    ? (
                                        <HStack gap={2} wrap="wrap">
                                            <Text
                                                fontSize="sm"
                                                fontWeight="600"
                                                color={balance.current < 0 ? 'red.500' : undefined}
                                            >
                                                {formatPrice(balance.current)}
                                            </Text>
                                            {balance.overdue > 0 && (
                                                <Badge colorPalette="red" variant="subtle">
                                                    просрочка {formatPrice(balance.overdue)}
                                                </Badge>
                                            )}
                                        </HStack>
                                    )
                                    : <Text fontSize="sm" color="fg.muted">—</Text>}
                                {balance?.updated_at && (
                                    <Text fontSize="xs" color="fg.muted">обновлено {balance.updated_at}</Text>
                                )}
                            </Box>
                        </SimpleGrid>
                    </Card.Body>
                </Card.Root>

                {/* Реквизиты закрыты по умолчанию: менеджер приходит сюда за
                    перепиской и задачами, а в договор заглядывает изредка. */}
                <AccordionRoot collapsible size="sm" variant="outline">
                    <AccordionItem value="details">
                        <AccordionItemTrigger>
                            <Text fontSize="sm" fontWeight="600">Реквизиты</Text>
                        </AccordionItemTrigger>
                        <AccordionItemContent>
                            <SimpleGrid columns={{ base: 1, md: 3 }} gap={4} pb={2}>
                                <InfoRow label="Юридическое наименование" value={contractor.legal_name} />
                                <InfoRow label="ОГРН" value={contractor.registration_number} />
                                <InfoRow label="ОКПО" value={contractor.okpo_code} />
                                <InfoRow label="Юридический адрес" value={contractor.legal_address} />
                                <InfoRow label="Фактический адрес" value={contractor.actual_address} />
                                <InfoRow label="Страна" value={contractor.country} />
                                <InfoRow label="Телефон" value={contractor.phone} />
                                <InfoRow label="Email" value={contractor.email} />
                                <InfoRow label="Создан" value={contractor.created_at} />
                            </SimpleGrid>

                            {contractor.bank_accounts.length > 0 && (
                                <Box pt={3} borderTopWidth="1px">
                                    <Text fontSize="sm" fontWeight="600" mb={2}>Банковские счета</Text>
                                    <VStack align="stretch" gap={2}>
                                        {contractor.bank_accounts.map((account) => (
                                            <HStack key={account.id} gap={3} wrap="wrap">
                                                <Text fontSize="sm">{account.bank_name || '—'}</Text>
                                                <Text fontSize="sm" fontFamily="mono">{account.account_number}</Text>
                                                {account.bik && (
                                                    <Text fontSize="xs" color="fg.muted">БИК {account.bik}</Text>
                                                )}
                                                {account.is_primary && (
                                                    <Badge colorPalette="blue" variant="subtle" size="sm">основной</Badge>
                                                )}
                                            </HStack>
                                        ))}
                                    </VStack>
                                </Box>
                            )}
                        </AccordionItemContent>
                    </AccordionItem>
                </AccordionRoot>

                {(canViewComments || canViewTasks || canViewFiles || canSeeDocuments) && (
                    <Card.Root>
                        <Card.Body>
                            <Tabs.Root defaultValue={defaultTab} lazyMount>
                                <Tabs.List>
                                    {canViewComments && <Tabs.Trigger value="comments">Комментарии</Tabs.Trigger>}
                                    {canViewTasks && <Tabs.Trigger value="tasks">Задачи</Tabs.Trigger>}
                                    {canSeeDocuments && <Tabs.Trigger value="orders">Заказы</Tabs.Trigger>}
                                    {canSeeDocuments && <Tabs.Trigger value="shipments">Реализации</Tabs.Trigger>}
                                    {canViewFiles && <Tabs.Trigger value="files">Файлы</Tabs.Trigger>}
                                </Tabs.List>

                                {canViewComments && (
                                    <Tabs.Content value="comments">
                                        <Text fontSize="xs" color="fg.muted" mb={3}>
                                            Комментарии по этому юрлицу. Они же попадают в ленту партнёра.
                                        </Text>
                                        <CommentThread
                                            entityType="contractor"
                                            entityId={contractor.id}
                                            canCreate={can('crm-comments.create')}
                                        />
                                    </Tabs.Content>
                                )}

                                {canViewTasks && (
                                    <Tabs.Content value="tasks">
                                        <TaskPanel entityType="contractor" entityId={contractor.id} />
                                    </Tabs.Content>
                                )}

                                {canSeeDocuments && (
                                    <Tabs.Content value="orders">
                                        <DocumentsTable
                                            rows={documents.orders}
                                            emptyMessage="Заказов по этому юрлицу нет"
                                        />
                                    </Tabs.Content>
                                )}

                                {canSeeDocuments && (
                                    <Tabs.Content value="shipments">
                                        <DocumentsTable
                                            rows={documents.shipments}
                                            emptyMessage="Реализаций по этому юрлицу нет"
                                        />
                                    </Tabs.Content>
                                )}

                                {canViewFiles && (
                                    <Tabs.Content value="files">
                                        <AttachmentPanel
                                            entityType="contractor"
                                            entityId={contractor.id}
                                            canUpload={can('crm-attachments.create')}
                                            label="Файлы по контрагенту"
                                        />
                                    </Tabs.Content>
                                )}

                            </Tabs.Root>
                        </Card.Body>
                    </Card.Root>
                )}
            </VStack>
        </>
    );
}

Show.layout = (page) => <CrmLayout>{page}</CrmLayout>;
