import { useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Badge, Box, HStack, Input, Table, Text } from '@chakra-ui/react';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { Alert } from '@/components/ui/alert';
import { LuClock3 } from 'react-icons/lu';

/**
 * Резервы заказов (v16.9.0, res-11): сводка злоупотреблений и рычаг РОПа.
 *
 * Красная зона — доля резервов, снятых по таймауту, выше порога: партнёр
 * «резервирует и бросает». Автоматики нет намеренно: только сигнал и ручное
 * решение — сократить индивидуальное окно или отключить режим на сайте
 * (сужение поверх флага 1С; включить сверх флага 1С сайт не может).
 */
export default function Index() {
    const {
        partners = [], windowDays = 90, defaultHours = 24,
        alertShare = 0.3, reserveEnabled = false, canEdit = false,
    } = usePage().props;

    const [drafts, setDrafts] = useState({});
    const [busyId, setBusyId] = useState(null);

    const draftFor = (row) => drafts[row.user_id] ?? { disabled: row.disabled, hours: row.hours ?? '' };
    const setDraft = (row, patch) => setDrafts((prev) => ({
        ...prev,
        [row.user_id]: { ...draftFor(row), ...patch },
    }));

    const save = (row) => {
        const draft = draftFor(row);
        setBusyId(row.user_id);
        router.put(route('crm.reserves.update', row.user_id), {
            disabled: !!draft.disabled,
            hours: draft.hours === '' ? null : Number(draft.hours),
        }, {
            preserveScroll: true,
            onFinish: () => {
                setBusyId(null);
                setDrafts((prev) => {
                    const next = { ...prev };
                    delete next[row.user_id];
                    return next;
                });
            },
        });
    };

    const pct = (v) => (v === null || v === undefined ? '—' : `${Math.round(v * 100)}%`);

    return (
        <Box>
            <Head title="Резервы заказов — CRM" />
            <PageHeader
                title="Резервы заказов"
                subtitle={`Исходы резервов за ${windowDays} дней. Красная зона — снято по таймауту чаще ${Math.round(alertShare * 100)}% («резервирует и бросает»).`}
                icon={<LuClock3 />}
            />

            {!reserveEnabled && (
                <Alert status="info" title="Режим выключен глобально" mb="4">
                    Рубильник ORDER_RESERVE_ENABLED выключен: клиенты резервов не видят.
                    Настройки ниже применятся после включения режима.
                </Alert>
            )}

            <Box overflowX="auto" bg="bg" borderRadius="xl" border="1px solid" borderColor="border.muted">
                <Table.Root size="sm">
                    <Table.Header>
                        <Table.Row>
                            <Table.ColumnHeader>Партнёр</Table.ColumnHeader>
                            <Table.ColumnHeader textAlign="center">Флаг 1С</Table.ColumnHeader>
                            <Table.ColumnHeader textAlign="center">Активных</Table.ColumnHeader>
                            <Table.ColumnHeader textAlign="center">Подтверждено</Table.ColumnHeader>
                            <Table.ColumnHeader textAlign="center">Отменено</Table.ColumnHeader>
                            <Table.ColumnHeader textAlign="center">Сгорело</Table.ColumnHeader>
                            <Table.ColumnHeader textAlign="center">% сгоревших</Table.ColumnHeader>
                            {canEdit && <Table.ColumnHeader textAlign="center">Окно, ч</Table.ColumnHeader>}
                            {canEdit && <Table.ColumnHeader textAlign="center">Отключён</Table.ColumnHeader>}
                            {canEdit && <Table.ColumnHeader />}
                        </Table.Row>
                    </Table.Header>
                    <Table.Body>
                        {partners.length === 0 && (
                            <Table.Row>
                                <Table.Cell colSpan={canEdit ? 10 : 7}>
                                    <Text py="6" textAlign="center" color="fg.muted">
                                        Участников режима пока нет — 1С не разметила ни одного партнёра
                                        (partner.updated с reserve_allowed).
                                    </Text>
                                </Table.Cell>
                            </Table.Row>
                        )}
                        {partners.map((row) => {
                            const draft = draftFor(row);
                            const alarm = row.expired_share !== null && row.expired_share >= alertShare;
                            return (
                                <Table.Row key={row.user_id} bg={alarm ? 'red.50' : undefined} _dark={alarm ? { bg: 'red.900/20' } : undefined}>
                                    <Table.Cell>
                                        <Text fontWeight="500">{row.name}</Text>
                                    </Table.Cell>
                                    <Table.Cell textAlign="center">
                                        <Badge colorPalette={row.reserve_allowed ? 'green' : 'gray'} variant="subtle">
                                            {row.reserve_allowed ? 'участник' : 'нет'}
                                        </Badge>
                                    </Table.Cell>
                                    <Table.Cell textAlign="center">{row.active || '—'}</Table.Cell>
                                    <Table.Cell textAlign="center">{row.confirmed}</Table.Cell>
                                    <Table.Cell textAlign="center">{row.cancelled}</Table.Cell>
                                    <Table.Cell textAlign="center">{row.expired}</Table.Cell>
                                    <Table.Cell textAlign="center">
                                        <Text as="span" fontWeight={alarm ? '700' : '400'} color={alarm ? 'red.500' : undefined}>
                                            {pct(row.expired_share)}
                                        </Text>
                                    </Table.Cell>
                                    {canEdit && (
                                        <Table.Cell textAlign="center">
                                            <Input
                                                size="xs"
                                                w="70px"
                                                mx="auto"
                                                type="number"
                                                min="1"
                                                max="168"
                                                placeholder={String(defaultHours)}
                                                value={draft.hours}
                                                onChange={(e) => setDraft(row, { hours: e.target.value })}
                                            />
                                        </Table.Cell>
                                    )}
                                    {canEdit && (
                                        <Table.Cell textAlign="center">
                                            <Switch
                                                checked={!!draft.disabled}
                                                onCheckedChange={({ checked }) => setDraft(row, { disabled: !!checked })}
                                                colorPalette="red"
                                            />
                                        </Table.Cell>
                                    )}
                                    {canEdit && (
                                        <Table.Cell textAlign="center">
                                            <Button
                                                size="xs"
                                                variant="outline"
                                                loading={busyId === row.user_id}
                                                disabled={drafts[row.user_id] === undefined}
                                                onClick={() => save(row)}
                                            >
                                                Сохранить
                                            </Button>
                                        </Table.Cell>
                                    )}
                                </Table.Row>
                            );
                        })}
                    </Table.Body>
                </Table.Root>
            </Box>

            <Text fontSize="xs" color="fg.muted" mt="3">
                «Окно, ч» пустое — действует умолчание ({defaultHours} ч). «Отключён» прячет резерв
                у партнёра на сайте, не трогая его флаг в 1С. Пустое окно и выключенный тумблер —
                возврат к умолчаниям (строка отклонения удаляется).
            </Text>
        </Box>
    );
}

Index.layout = (page) => <CrmLayout>{page}</CrmLayout>;
