import { useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { DataTable } from '@/Admin/Components/DataTable';
import { Badge, Box, HStack, Image, Text } from '@chakra-ui/react';
import { ConfirmDialog } from '@/Admin/Components/ConfirmDialog';
import { LuArchive, LuBellRing, LuUndo2 } from 'react-icons/lu';
import RowActions from '@/shared/Panel/RowActions';
import StaffNotificationList from '@/Crm/Components/StaffNotificationList';
import { Button } from '@/components/ui/button';
import {
    DrawerBackdrop,
    DrawerBody,
    DrawerCloseTrigger,
    DrawerContent,
    DrawerFooter,
    DrawerHeader,
    DrawerRoot,
    DrawerTitle,
} from '@/components/ui/drawer';

/**
 * Команда: карточки персональных менеджеров.
 *
 * Скрытые карточки показываются здесь и только здесь — иначе снять пометку
 * было бы негде. Из остальной CRM (сетка планов, выбор скоупа, фильтры отчётов)
 * они уже исчезли.
 */
export default function Index() {
    const { managers, canEdit = false } = usePage().props;
    // Настройки уведомлений сотрудника: открываются из строки, потому что
    // отдельной карточки сотрудника в разделе нет и заводить её ради одного
    // экрана незачем.
    const [notificationsFor, setNotificationsFor] = useState(null);
    const [hideFor, setHideFor] = useState(null);
    const [busy, setBusy] = useState(false);

    const setActive = (manager, isActive) => {
        setBusy(true);
        router.put(route('crm.team.active', manager.id), { is_active: isActive }, {
            preserveScroll: true,
            onFinish: () => {
                setBusy(false);
                setHideFor(null);
            },
        });
    };

    const columns = [
        {
            key: 'name',
            label: 'Менеджер',
            render: (_, row) => (
                <HStack gap={3} opacity={row.is_active ? 1 : 0.55}>
                    {row.photo_url
                        ? <Image src={row.photo_url} alt={row.name} w="32px" h="32px" borderRadius="full" objectFit="cover" />
                        : <Box w="32px" h="32px" borderRadius="full" bg="bg.muted" />}
                    <Text fontWeight="semibold">{row.name}</Text>
                    {!row.is_active && <Badge colorPalette="gray" variant="subtle">Скрыт</Badge>}
                </HStack>
            ),
        },
        {
            key: 'clients_count',
            label: 'Партнёров',
            render: (_, row) => <Text fontSize="sm">{row.clients_count}</Text>,
        },
        {
            key: 'phone',
            label: 'Телефон',
            render: (_, row) => <Text fontSize="sm">{row.phone || '—'}</Text>,
        },
        {
            key: 'email',
            label: 'Email',
            render: (_, row) => <Text fontSize="sm">{row.email || '—'}</Text>,
        },
        {
            key: 'account',
            label: 'Аккаунт в CRM',
            render: (_, row) => (row.account
                ? <Text fontSize="sm">{row.account.email}</Text>
                : <Badge colorPalette="orange" variant="subtle">Нет доступа</Badge>),
        },
        {
            key: 'has_erp_uuid',
            label: 'Источник',
            render: (_, row) => (
                <Badge colorPalette={row.has_erp_uuid ? 'blue' : 'gray'} variant="subtle">
                    {row.has_erp_uuid ? 'Из 1С' : 'Создан на сайте'}
                </Badge>
            ),
        },
        ...(canEdit ? [{
            key: 'actions',
            label: 'Действия',
            render: (_, row) => (
                <RowActions
                    size="xs"
                    extra={[
                        ...(row.account ? [{
                            key: 'notifications',
                            icon: LuBellRing,
                            label: 'Какие письма получает этот сотрудник',
                            onClick: () => setNotificationsFor(row),
                        }] : []),
                        row.is_active
                            ? {
                                key: 'hide',
                                icon: LuArchive,
                                label: 'Скрыть карточку из списков CRM',
                                disabled: busy,
                                onClick: () => setHideFor(row),
                            }
                            : {
                                key: 'restore',
                                icon: LuUndo2,
                                label: 'Вернуть карточку в работу',
                                disabled: busy,
                                onClick: () => setActive(row, true),
                            },
                    ]}
                />
            ),
        }] : []),
    ];

    return (
        <>
            <Head title="CRM — Команда" />
            <PageHeader
                title="Команда"
                description="Персональные менеджеры отдела продаж и их аккаунты"
            />

            <DataTable data={managers} columns={columns} />

            <ConfirmDialog
                open={hideFor !== null}
                onClose={() => setHideFor(null)}
                onConfirm={() => setActive(hideFor, false)}
                title={`Скрыть карточку «${hideFor?.name}»?`}
                description={hideFor?.clients_count > 0
                    ? `Карточка пропадёт из сеток и выборов CRM. За ней числятся партнёры (${hideFor.clients_count}) — их выручка останется в отчётах, но менеджера у них нужно переназначить в 1С.`
                    : 'Карточка пропадёт из сеток и выборов CRM. Сама карточка, её история и привязки останутся — вернуть в работу можно здесь же.'}
                confirmLabel="Скрыть"
                cancelLabel="Отмена"
                isLoading={busy}
                colorPalette="red"
            />

            <DrawerRoot
                open={!!notificationsFor}
                onOpenChange={(e) => !e.open && setNotificationsFor(null)}
                size="md"
            >
                <DrawerBackdrop />
                <DrawerContent>
                    <DrawerHeader>
                        <DrawerTitle>
                            Уведомления: {notificationsFor?.name}
                        </DrawerTitle>
                    </DrawerHeader>
                    <DrawerBody>
                        {notificationsFor && (
                            <StaffNotificationList managerId={notificationsFor.id} />
                        )}
                    </DrawerBody>
                    <DrawerFooter>
                        <DrawerCloseTrigger asChild>
                            <Button variant="outline" size="sm">Закрыть</Button>
                        </DrawerCloseTrigger>
                    </DrawerFooter>
                </DrawerContent>
            </DrawerRoot>
        </>
    );
}

Index.layout = (page) => <CrmLayout>{page}</CrmLayout>;
