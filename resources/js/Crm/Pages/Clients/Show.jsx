import { Head, usePage } from '@inertiajs/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Box, Card, SimpleGrid, Tabs, Text, VStack, Badge } from '@chakra-ui/react';
import { usePermission } from '@/shared/Panel/usePermission';
import ClientTimeline from '@/Crm/Components/ClientTimeline';
import CommentThread from '@/Crm/Components/CommentThread';
import AttachmentPanel from '@/Crm/Components/AttachmentPanel';
import ClientProfileForm from '@/Crm/Components/ClientProfileForm';
import ClientLifecyclePanel from '@/Crm/Components/ClientLifecyclePanel';

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

    const defaultTab = canViewProfile ? 'profile' : (canViewComments ? 'timeline' : 'files');
    const showStatuses = canViewProfile && !!lifecycle;

    return (
        <>
            <Head title={`CRM — ${client.name}`} />
            <PageHeader
                title={client.name}
                description="Карточка клиента"
            />

            <VStack gap={4} align="stretch">
                <Card.Root>
                    <Card.Header>
                        <Text fontWeight="semibold" fontSize="lg">Основная информация</Text>
                    </Card.Header>
                    <Card.Body>
                        <SimpleGrid columns={{ base: 2, md: 3 }} gap={4}>
                            <InfoRow label="ID" value={client.id?.toString()} />
                            <InfoRow label="Email" value={client.email} />
                            <InfoRow label="Телефон" value={client.phone} />
                            <InfoRow label="Город" value={client.city} />
                            <InfoRow label="Страна" value={client.country} />
                            <InfoRow label="Статус" value={client.status_label} />
                            <InfoRow label="Персональный менеджер" value={client.manager?.name} />
                            {/* Лояльность из 1С. Когда виден блок «Статусы», она показана там
                                вместе с жизненным статусом — здесь была бы повтором. */}
                            {!showStatuses && (
                                <Box>
                                    <Text fontSize="xs" color="gray.500" mb="0.5">Статус клиента</Text>
                                    {client.client_status
                                        ? <Badge colorPalette="gray" variant="subtle">{client.client_status.name}</Badge>
                                        : <Text fontSize="sm" fontWeight="500">—</Text>}
                                </Box>
                            )}
                            <InfoRow label="Зарегистрирован" value={client.created_at} />
                        </SimpleGrid>
                    </Card.Body>
                </Card.Root>

                {showStatuses && (
                    <Card.Root>
                        <Card.Header>
                            <Text fontWeight="semibold" fontSize="lg">Статусы</Text>
                        </Card.Header>
                        <Card.Body>
                            <ClientLifecyclePanel
                                clientId={client.id}
                                lifecycle={lifecycle}
                                options={profileOptions.lifecycle_status}
                                loyalty={client.client_status}
                                canEdit={can('crm-profile.edit')}
                            />
                        </Card.Body>
                    </Card.Root>
                )}

                {(canViewProfile || canViewComments || canViewFiles) && (
                    <Card.Root>
                        <Card.Body>
                            <Tabs.Root defaultValue={defaultTab} lazyMount unmountOnExit>
                                <Tabs.List>
                                    {canViewProfile && <Tabs.Trigger value="profile">Профиль</Tabs.Trigger>}
                                    {canViewComments && <Tabs.Trigger value="timeline">Лента</Tabs.Trigger>}
                                    {canViewComments && <Tabs.Trigger value="comments">Комментарии по клиенту</Tabs.Trigger>}
                                    {canViewFiles && <Tabs.Trigger value="files">Файлы</Tabs.Trigger>}
                                </Tabs.List>

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

                                {canViewComments && (
                                    <Tabs.Content value="timeline">
                                        <Text fontSize="xs" color="fg.muted" mb={3}>
                                            Комментарии по клиенту, его заказам и реализациям — в одной хронологии.
                                        </Text>
                                        <ClientTimeline clientId={client.id} />
                                    </Tabs.Content>
                                )}

                                {canViewComments && (
                                    <Tabs.Content value="comments">
                                        <CommentThread
                                            entityType="client"
                                            entityId={client.id}
                                            canCreate={can('crm-comments.create')}
                                        />
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
        </>
    );
}

Show.layout = (page) => <CrmLayout>{page}</CrmLayout>;
