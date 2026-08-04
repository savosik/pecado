import { useState } from 'react';
import { Head, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { LuMail } from 'react-icons/lu';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Badge, Box, Card, SimpleGrid, Tabs, Text, VStack } from '@chakra-ui/react';
import {
    AccordionItem,
    AccordionItemContent,
    AccordionItemTrigger,
    AccordionRoot,
} from '@/components/ui/accordion';
import { usePermission } from '@/shared/Panel/usePermission';
import ClientFeed from '@/Crm/Components/Feed/ClientFeed';
import AttachmentPanel from '@/Crm/Components/AttachmentPanel';
import ClientProfileForm from '@/Crm/Components/ClientProfileForm';
import ClientLifecyclePanel from '@/Crm/Components/ClientLifecyclePanel';
import TaskPanel from '@/Crm/Components/TaskPanel';
import EmailComposeDialog from '@/Crm/Components/EmailComposeDialog';
import ClientSummaryBar from './components/ClientSummaryBar';

function InfoRow({ label, value }) {
    return (
        <Box>
            <Text fontSize="xs" color="gray.500" mb="0.5">{label}</Text>
            <Text fontSize="sm" fontWeight="500">{value || '—'}</Text>
        </Box>
    );
}

export default function Show() {
    const { client, profile, profileOptions, lifecycle } = usePage().props;
    const { can } = usePermission();

    const canViewProfile = can('crm-profile.view') && !!profile;
    const canViewComments = can('crm-comments.view');
    const canViewFiles = can('crm-attachments.view');
    const canViewTasks = can('crm-tasks.view');
    const [composeOpen, setComposeOpen] = useState(false);

    // Лента — главная вкладка: карточка отвечает на вопрос «что известно»,
    // а работа идёт в хронологии.
    const defaultTab = canViewComments ? 'timeline' : (canViewProfile ? 'profile' : 'files');

    return (
        <>
            <Head title={`CRM — ${client.name}`} />
            <PageHeader
                title={client.name}
                description="Карточка клиента"
                actions={can('crm-emails.create')
                    ? (
                        <Button size="sm" variant="outline" onClick={() => setComposeOpen(true)}>
                            <LuMail /> Написать письмо
                        </Button>
                    )
                    : null}
            />

            <VStack gap={3} align="stretch">
                <ClientSummaryBar
                    client={client}
                    lifecycle={canViewProfile ? lifecycle : null}
                    lifecycleOptions={profileOptions?.lifecycle_status || []}
                    canEditLifecycle={can('crm-profile.edit')}
                />

                {/* Подробности — под спойлером: в разговоре с клиентом нужны
                    считаные факты, а не таблица на пол-экрана. */}
                <AccordionRoot collapsible size="sm" variant="outline">
                    <AccordionItem value="details">
                        <AccordionItemTrigger>
                            <Text fontSize="sm" fontWeight="600">Подробно о клиенте</Text>
                        </AccordionItemTrigger>
                        <AccordionItemContent>
                            <SimpleGrid columns={{ base: 2, md: 4 }} gap={4} pb={2}>
                                <InfoRow label="ID" value={client.id?.toString()} />
                                <InfoRow label="Email" value={client.email} />
                                <InfoRow label="Телефон" value={client.phone} />
                                <InfoRow label="Город" value={client.city} />
                                <InfoRow label="Страна" value={client.country} />
                                <InfoRow label="Статус" value={client.status_label} />
                                <InfoRow label="Персональный менеджер" value={client.manager?.name} />
                                <InfoRow label="Зарегистрирован" value={client.created_at} />
                                {!canViewProfile && (
                                    <Box>
                                        <Text fontSize="xs" color="gray.500" mb="0.5">Статус клиента</Text>
                                        {client.client_status
                                            ? <Badge colorPalette="gray" variant="subtle">{client.client_status.name}</Badge>
                                            : <Text fontSize="sm" fontWeight="500">—</Text>}
                                    </Box>
                                )}
                            </SimpleGrid>

                            {canViewProfile && lifecycle && (
                                <Box pt={3} borderTopWidth="1px">
                                    <ClientLifecyclePanel
                                        clientId={client.id}
                                        lifecycle={lifecycle}
                                        options={profileOptions.lifecycle_status}
                                        loyalty={client.client_status}
                                        canEdit={can('crm-profile.edit')}
                                    />
                                </Box>
                            )}
                        </AccordionItemContent>
                    </AccordionItem>
                </AccordionRoot>

                {(canViewProfile || canViewComments || canViewFiles || canViewTasks) && (
                    <Card.Root>
                        <Card.Body>
                            {/* lazyMount без unmountOnExit: лента держит позицию скролла
                                и черновик поля ввода, а размонтирование теряло бы их
                                при каждом переключении вкладки. */}
                            <Tabs.Root defaultValue={defaultTab} lazyMount>
                                <Tabs.List>
                                    {canViewComments && <Tabs.Trigger value="timeline">Лента</Tabs.Trigger>}
                                    {canViewProfile && <Tabs.Trigger value="profile">Профиль</Tabs.Trigger>}
                                    {canViewTasks && <Tabs.Trigger value="tasks">Задачи</Tabs.Trigger>}
                                    {canViewFiles && <Tabs.Trigger value="files">Файлы</Tabs.Trigger>}
                                </Tabs.List>

                                {canViewComments && (
                                    <Tabs.Content value="timeline">
                                        <ClientFeed clientId={client.id} clientEmail={client.email} />
                                    </Tabs.Content>
                                )}

                                {canViewProfile && (
                                    <Tabs.Content value="profile">
                                        <ClientProfileForm
                                            clientId={client.id}
                                            profile={profile}
                                            options={profileOptions}
                                            canEdit={can('crm-profile.edit')}
                                        />
                                    </Tabs.Content>
                                )}

                                {canViewTasks && (
                                    <Tabs.Content value="tasks">
                                        <TaskPanel entityType="client" entityId={client.id} />
                                    </Tabs.Content>
                                )}

                                {canViewFiles && (
                                    <Tabs.Content value="files">
                                        <AttachmentPanel
                                            entityType="client"
                                            entityId={client.id}
                                            canUpload={can('crm-attachments.create')}
                                            label="Файлы по клиенту"
                                        />
                                    </Tabs.Content>
                                )}
                            </Tabs.Root>
                        </Card.Body>
                    </Card.Root>
                )}
            </VStack>

            <EmailComposeDialog
                open={composeOpen}
                entity={{ type: 'client', id: client.id }}
                defaultTo={client.email}
                onClose={() => setComposeOpen(false)}
            />
        </>
    );
}

Show.layout = (page) => <CrmLayout>{page}</CrmLayout>;
