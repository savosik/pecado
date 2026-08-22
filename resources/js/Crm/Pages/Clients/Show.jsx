import { useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { LuEye, LuMail, LuUserX } from 'react-icons/lu';
import ConfirmDialog from '@/components/common/ConfirmDialog';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Badge, Box, Card, HStack, SimpleGrid, Tabs, Text, VStack } from '@chakra-ui/react';
import {
    AccordionItem,
    AccordionItemContent,
    AccordionItemTrigger,
    AccordionRoot,
} from '@/components/ui/accordion';
import { usePermission } from '@/shared/Panel/usePermission';
import ClientFeed from '@/Crm/Components/Feed/ClientFeed';
import ClientDocuments from '@/Crm/Components/ClientDocuments';
import CommentThread from '@/Crm/Components/CommentThread';
import AttachmentPanel from '@/Crm/Components/AttachmentPanel';
import ContactsPanel from '@/Crm/Components/ContactsPanel';
import VoiceNotes from '@/Crm/Components/VoiceNotes';
import ClientProfileForm from '@/Crm/Components/ClientProfileForm';
import ClientLifecyclePanel from '@/Crm/Components/ClientLifecyclePanel';
import TaskPanel from '@/Crm/Components/TaskPanel';
import EmailComposeDialog from '@/Crm/Components/EmailComposeDialog';
import ClientKindDialog from '@/Crm/Components/ClientKindDialog';
import PartnerContractors from '@/Crm/Components/PartnerContractors';
import PartnerPurchases from '@/Crm/Components/PartnerPurchases';
import ClientSummaryBar from './components/ClientSummaryBar';
import StockBufferPanel from './components/StockBufferPanel';

function InfoRow({ label, value }) {
    return (
        <Box>
            <Text fontSize="xs" color="gray.500" mb="0.5">{label}</Text>
            <Text fontSize="sm" fontWeight="500">{value || '—'}</Text>
        </Box>
    );
}

export default function Show() {
    const {
        client,
        profile,
        profileOptions,
        passportSections,
        lifecycle,
        organizations,
        organizationsEnabled,
        contractors = [],
        canSeeContractors = false,
    } = usePage().props;
    const { can } = usePermission();

    const canViewProfile = can('crm-profile.view') && !!profile;
    const canViewComments = can('crm-comments.view');
    const canViewFiles = can('crm-attachments.view');
    const canViewContacts = can('crm-contacts.view');
    const canViewTasks = can('crm-tasks.view');
    const [composeOpen, setComposeOpen] = useState(false);
    const [kindOpen, setKindOpen] = useState(false);
    const [impersonateOpen, setImpersonateOpen] = useState(false);
    // Раскрытые спойлеры держим в состоянии: блок закупок ходит за данными
    // сам и монтируется только тогда, когда менеджер его открыл.
    const [openSections, setOpenSections] = useState([]);
    // Состав базы партнёров отдела — дело того, кто за отдел отвечает.
    const canManageKind = can('crm-clients-all.edit');

    // Лента — единственная главная вкладка: карточка отвечает на вопрос «что известно»,
    // а работа идёт в хронологии.
    const defaultTab = canViewComments ? 'timeline' : (canViewTasks ? 'tasks' : 'files');

    return (
        <>
            <Head title={`CRM — ${client.name}`} />
            <PageHeader
                title={client.name}
                description={client.personal_name
                    ? `Карточка партнёра · на сайте назвался «${client.personal_name}»`
                    : 'Карточка партнёра'}
                actions={(
                    <HStack gap={2}>
                        {can('crm-impersonate.use') && (
                            <Button size="sm" variant="outline" onClick={() => setImpersonateOpen(true)}>
                                <LuEye /> Войти под партнёром
                            </Button>
                        )}
                        {can('crm-emails.create') && (
                            <Button size="sm" variant="outline" onClick={() => setComposeOpen(true)}>
                                <LuMail /> Написать письмо
                            </Button>
                        )}
                        {canManageKind && (
                            <Button
                                size="sm"
                                variant="outline"
                                colorPalette="red"
                                onClick={() => setKindOpen(true)}
                            >
                                <LuUserX /> Это не партнёр
                            </Button>
                        )}
                    </HStack>
                )}
            />

            <VStack gap={3} align="stretch">
                <ClientSummaryBar
                    client={client}
                    lifecycle={canViewProfile ? lifecycle : null}
                    lifecycleOptions={profileOptions?.lifecycle_status || []}
                    canEditLifecycle={can('crm-profile.edit')}
                />

                {/* Голосовое досье — прямо в шапке, а не в спойлере: надиктовать
                    после разговора нужно за один клик, иначе не надиктуют вовсе.
                    Распознавание речи здесь не участвует: на живой речи с товарными
                    наименованиями оно даёт текст, который дольше править. */}
                {canViewFiles && (
                    <Box>
                        <VoiceNotes
                            entityType="client"
                            entityId={client.id}
                            canCreate={can('crm-attachments.create')}
                        />
                    </Box>
                )}

                {/* Данные о партнёре живут над вкладками, а не среди них: они описывают
                    партнёра, а вкладки — работу с ним. Спойлеры закрыты по умолчанию,
                    чтобы лента начиналась сразу под шапкой. */}
                <AccordionRoot
                    collapsible
                    size="sm"
                    variant="outline"
                    value={openSections}
                    onValueChange={(details) => setOpenSections(details.value)}
                >
                    <AccordionItem value="details">
                        <AccordionItemTrigger>
                            <Text fontSize="sm" fontWeight="600">Реквизиты и статусы</Text>
                        </AccordionItemTrigger>
                        <AccordionItemContent>
                            <SimpleGrid columns={{ base: 2, md: 4 }} gap={4} pb={2}>
                                <InfoRow label="ID" value={client.id?.toString()} />
                                {/* Заголовок карточки — рабочее наименование из 1С,
                                    здесь показываем имя, которое партнёр задал сам. */}
                                {client.personal_name && (
                                    <InfoRow label="Имя на сайте" value={client.personal_name} />
                                )}
                                <InfoRow label="Email" value={client.email} />
                                <InfoRow label="Телефон" value={client.phone} />
                                <InfoRow label="Город" value={client.city} />
                                <InfoRow label="Страна" value={client.country} />
                                <InfoRow label="Статус" value={client.status_label} />
                                <InfoRow label="Персональный менеджер" value={client.manager?.name} />
                                <InfoRow label="Зарегистрирован" value={client.created_at} />
                                <InfoRow
                                    label="Последний визит на сайт"
                                    value={client.last_visit?.state === 'never'
                                        ? 'ни разу не заходил'
                                        : client.last_visit?.at}
                                />
                                {!canViewProfile && (
                                    <Box>
                                        <Text fontSize="xs" color="gray.500" mb="0.5">Статус партнёра</Text>
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

                            <StockBufferPanel
                                clientId={client.id}
                                stockBuffer={client.stock_buffer}
                                canEdit={can('crm-profile.edit')}
                            />
                        </AccordionItemContent>
                    </AccordionItem>

                    {canViewProfile && (
                        <AccordionItem value="profile">
                            <AccordionItemTrigger>
                                <Text fontSize="sm" fontWeight="600">
                                    Профиль партнёра — ЛПР, оплата, интересы, заметки
                                </Text>
                            </AccordionItemTrigger>
                            <AccordionItemContent>
                                <Box pb={2}>
                                    <ClientProfileForm
                                        clientId={client.id}
                                        profile={profile}
                                        options={profileOptions}
                                        passportSections={passportSections}
                                        canEdit={can('crm-profile.edit')}
                                    />
                                </Box>
                            </AccordionItemContent>
                        </AccordionItem>
                    )}

                    {/* Что партнёр берёт на самом деле — рядом с анкетой, где записано,
                        что он о себе рассказывает: расхождение этих двух картин и есть
                        повод для звонка. */}
                    <AccordionItem value="purchases">
                        <AccordionItemTrigger>
                            <Text fontSize="sm" fontWeight="600">
                                Закупки за 12 месяцев — средний чек, бренды, категории
                            </Text>
                        </AccordionItemTrigger>
                        <AccordionItemContent>
                            {openSections.includes('purchases') && (
                                <PartnerPurchases clientId={client.id} />
                            )}
                        </AccordionItemContent>
                    </AccordionItem>
                </AccordionRoot>

                {(canViewComments || canViewFiles || canViewTasks || canSeeContractors || canViewContacts) && (
                    <Card.Root>
                        <Card.Body>
                            {/* lazyMount без unmountOnExit: лента держит позицию скролла
                                и черновик поля ввода, а размонтирование теряло бы их
                                при каждом переключении вкладки. */}
                            <Tabs.Root defaultValue={defaultTab} lazyMount>
                                <Tabs.List>
                                    {canViewComments && <Tabs.Trigger value="timeline">Лента</Tabs.Trigger>}
                                    {canViewComments && <Tabs.Trigger value="comments">Комментарии</Tabs.Trigger>}
                                    {canViewTasks && <Tabs.Trigger value="tasks">Задачи</Tabs.Trigger>}
                                    {canSeeContractors && (
                                        <Tabs.Trigger value="contractors">
                                            Контрагенты
                                            {contractors.length > 0 && ` (${contractors.length})`}
                                        </Tabs.Trigger>
                                    )}
                                    {canViewComments && <Tabs.Trigger value="orders">Заказы</Tabs.Trigger>}
                                    {canViewComments && <Tabs.Trigger value="shipments">Реализации</Tabs.Trigger>}
                                    {canViewFiles && <Tabs.Trigger value="files">Файлы</Tabs.Trigger>}
                                    {canViewContacts && <Tabs.Trigger value="contacts">Контакты</Tabs.Trigger>}
                                </Tabs.List>

                                {canViewComments && (
                                    <Tabs.Content value="timeline">
                                        <ClientFeed
                                            clientId={client.id}
                                            client={client}
                                            clientEmail={client.email}
                                        />
                                    </Tabs.Content>
                                )}

                                {canViewComments && (
                                    <Tabs.Content value="comments">
                                        <Text fontSize="xs" color="fg.muted" mb={3}>
                                            Только комментарии — без задач, писем и документов.
                                        </Text>
                                        <CommentThread
                                            entityType="client"
                                            entityId={client.id}
                                            canCreate={can('crm-comments.create')}
                                        />
                                    </Tabs.Content>
                                )}

                                {canViewTasks && (
                                    <Tabs.Content value="tasks">
                                        <TaskPanel entityType="client" entityId={client.id} />
                                    </Tabs.Content>
                                )}

                                {/* Юрлица партнёра. Документы 1С проведены именно на них,
                                    поэтому вкладка стоит перед «Заказами» и «Реализациями». */}
                                {canSeeContractors && (
                                    <Tabs.Content value="contractors">
                                        <Text fontSize="xs" color="fg.muted" mb={3}>
                                            Юрлица партнёра. План и его выполнение считаются по партнёру целиком,
                                            а не по каждому юрлицу отдельно.
                                        </Text>
                                        <PartnerContractors contractors={contractors} />
                                    </Tabs.Content>
                                )}

                                {/* Документы берутся из эндпоинта ленты, поэтому гейтятся
                                    её правом: отдельного источника для них нет и заводить
                                    второй, со своими правилами видимости, незачем. */}
                                {canViewComments && (
                                    <Tabs.Content value="orders">
                                        <ClientDocuments
                                            clientId={client.id}
                                            type="order"
                                            organizations={organizations}
                                            organizationsEnabled={organizationsEnabled}
                                        />
                                    </Tabs.Content>
                                )}

                                {canViewComments && (
                                    <Tabs.Content value="shipments">
                                        <ClientDocuments
                                            clientId={client.id}
                                            type="shipment"
                                            organizations={organizations}
                                            organizationsEnabled={organizationsEnabled}
                                        />
                                    </Tabs.Content>
                                )}

                                {canViewFiles && (
                                    <Tabs.Content value="files">
                                        <AttachmentPanel
                                            entityType="client"
                                            entityId={client.id}
                                            canUpload={can('crm-attachments.create')}
                                            label="Файлы по партнёру"
                                        />
                                    </Tabs.Content>
                                )}

                                {canViewContacts && (
                                    <Tabs.Content value="contacts">
                                        <ContactsPanel
                                            entityType="client"
                                            entityId={client.id}
                                            canEdit={can('crm-contacts.edit')}
                                            canCreate={can('crm-contacts.create')}
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

            <ClientKindDialog
                open={kindOpen}
                client={client}
                onClose={() => setKindOpen(false)}
            />

            {/* Режим занимает всю сессию браузера: пока идёт просмотр, вкладки CRM
                отвечают редиректом на главную. Об этом честно предупреждаем здесь,
                чтобы менеджер не решил, что CRM сломалась. */}
            <ConfirmDialog
                open={impersonateOpen}
                onClose={() => setImpersonateOpen(false)}
                onConfirm={() => router.post(route('crm.impersonation.start', client.id))}
                title="Войти под партнёром"
                description={`Вы увидите сайт глазами партнёра «${client.name}»: его цены, корзину и кабинет. Оформлять заказы и возвраты за партнёра нельзя. Пока идёт просмотр, CRM в этом браузере будет недоступна — вернуться можно кнопкой на плашке внизу экрана.`}
                confirmLabel="Войти под партнёром"
                colorPalette="orange"
            />
        </>
    );
}

Show.layout = (page) => <CrmLayout>{page}</CrmLayout>;
