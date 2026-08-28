import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Badge, Box, Flex, HStack, Table, Text, VStack } from '@chakra-ui/react';
import { LuLock, LuLockOpen } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import { Alert } from '@/components/ui/alert';
import { ConfirmDialog } from '@/shared/Panel/ConfirmDialog';
import DebtLevelBadge from '@/Crm/Components/DebtLevelBadge';
import DebtPauseDialog from '@/Crm/Components/DebtPauseDialog';

const rub = (value) => `${Number(value || 0).toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ₽`;

/**
 * Вкладка «Дебиторка» в карточке партнёра (карточка debt-05).
 *
 * Ступень, «почему», контрагенты, история разблокировок и единственная
 * кнопка действия. Порогов и настроек здесь нет — они в конфиге.
 *
 * @param {{id: number, name: string}} client
 * @param {{partner: object|null, contractors: Array, pauses: Array, active_pause: object|null, thresholds: object, shadow: boolean}} debt
 * @param {number} pauseMaxDays
 */
export default function PartnerDebt({ client, debt, pauseMaxDays = 14 }) {
    const [pauseOpen, setPauseOpen] = useState(false);
    const [releaseFor, setReleaseFor] = useState(null);
    const [releasing, setReleasing] = useState(false);

    const partner = debt?.partner;

    if (!partner) {
        return <Text fontSize="sm" color="fg.muted">Просрочки по регистру нет — ступень не считалась.</Text>;
    }

    const reload = () => router.reload({ only: ['debt'] });

    const release = () => {
        if (!releaseFor) return;
        setReleasing(true);
        router.delete(route('crm.debt.pauses.release', releaseFor.id), {
            preserveScroll: true,
            onFinish: () => { setReleasing(false); setReleaseFor(null); reload(); },
        });
    };

    const thresholds = debt.thresholds || {};

    return (
        <VStack align="stretch" gap={4}>
            {debt.shadow && (
                <Alert status="info" title="Теневой расчёт">
                    Ступени считаются, но писем, ограничений и задач нет — режим включается по ступеням отдельно.
                </Alert>
            )}

            <Flex gap={4} wrap="wrap" align="center" justify="space-between">
                <HStack gap={3} wrap="wrap">
                    <DebtLevelBadge
                        debt={{ ...partner, label: partner.level_label, color: partner.level_color }}
                        pause={debt.active_pause}
                        size="md"
                    />
                    <Text fontSize="sm" color="fg">{partner.reason}</Text>
                </HStack>
                <HStack gap={2}>
                    {debt.active_pause ? (
                        <Button size="sm" variant="outline" colorPalette="red" onClick={() => setReleaseFor(debt.active_pause)}>
                            <LuLock /> Снять разблокировку
                        </Button>
                    ) : (
                        <Button size="sm" colorPalette="green" onClick={() => setPauseOpen(true)}>
                            <LuLockOpen /> Разблокировать до даты
                        </Button>
                    )}
                </HStack>
            </Flex>

            <Flex gap={6} wrap="wrap" fontSize="sm">
                <Box><Text color="fg.muted">Просрочка</Text><Text fontWeight="semibold">{rub(partner.overdue_amount)}</Text></Box>
                <Box><Text color="fg.muted">Весь долг</Text><Text fontWeight="semibold">{rub(partner.debt_amount)}</Text></Box>
                <Box><Text color="fg.muted">Самый ранний срок</Text><Text fontWeight="semibold">{partner.oldest_due_date || '—'} ({partner.age_days} дн.)</Text></Box>
                <Box><Text color="fg.muted">Считано</Text><Text fontWeight="semibold">{partner.computed_at || '—'}</Text></Box>
            </Flex>

            <Box>
                <Text fontSize="xs" color="fg.muted" mb={2}>
                    По контрагентам. Ступень партнёра — худший из них; заказы закрываются по контрагенту, стоп — по партнёру.
                </Text>
                <Table.Root size="sm" variant="line">
                    <Table.Header>
                        <Table.Row>
                            <Table.ColumnHeader>Контрагент</Table.ColumnHeader>
                            <Table.ColumnHeader>Ступень</Table.ColumnHeader>
                            <Table.ColumnHeader textAlign="right">Просрочка</Table.ColumnHeader>
                            <Table.ColumnHeader textAlign="right">Возраст</Table.ColumnHeader>
                            <Table.ColumnHeader>Почему</Table.ColumnHeader>
                        </Table.Row>
                    </Table.Header>
                    <Table.Body>
                        {(debt.contractors || []).map((row) => (
                            <Table.Row key={row.id}>
                                <Table.Cell>{row.company_name || `#${row.company_id}`}</Table.Cell>
                                <Table.Cell>
                                    <Badge colorPalette={row.level_color} variant={row.dry_run ? 'outline' : 'subtle'}>{row.level_label}</Badge>
                                </Table.Cell>
                                <Table.Cell textAlign="right">{rub(row.overdue_amount)}</Table.Cell>
                                <Table.Cell textAlign="right">{row.age_days} дн.</Table.Cell>
                                <Table.Cell><Text fontSize="xs" color="fg.muted">{row.reason}</Text></Table.Cell>
                            </Table.Row>
                        ))}
                    </Table.Body>
                </Table.Root>
            </Box>

            {(debt.pauses || []).length > 0 && (
                <Box>
                    <Text fontSize="xs" color="fg.muted" mb={2}>Разблокировки — история договорённостей.</Text>
                    <VStack align="stretch" gap={1}>
                        {debt.pauses.map((pause) => (
                            <Flex key={pause.id} gap={3} fontSize="sm" wrap="wrap" align="center">
                                <Badge colorPalette={pause.is_active ? 'green' : 'gray'} variant="subtle">
                                    {pause.is_active ? 'действует' : (pause.released_reason === 'expired' ? 'истекла' : 'снята')}
                                </Badge>
                                <Text>до {pause.until}</Text>
                                <Text color="fg.muted">{pause.company_name ? `только ${pause.company_name}` : 'весь партнёр'}</Text>
                                <Text color="fg.muted">· {pause.author || '—'}, {pause.created_at}</Text>
                                <Text flex="1" minW="200px">{pause.reason}</Text>
                            </Flex>
                        ))}
                    </VStack>
                </Box>
            )}

            <Text fontSize="xs" color="fg.muted">
                Пороги: отсечка {Number(thresholds.min_overdue || 0).toLocaleString('ru-RU')} ₽, льготный период {thresholds.grace_bank_days} банк. дн.;
                предзаказы закрываются с {thresholds.no_preorders_days} дн., заказы контрагента — с {thresholds.no_orders_days} дн.,
                стоп — с {thresholds.hold_days} дн. при просрочке ≥ {Math.round((thresholds.hold_share || 0) * 100)} % долга.
            </Text>

            <DebtPauseDialog
                open={pauseOpen}
                client={{ id: client.id, name: client.name, contractors: debt.contractors }}
                maxDays={pauseMaxDays}
                onClose={() => setPauseOpen(false)}
                onSaved={reload}
            />

            <ConfirmDialog
                open={releaseFor !== null}
                onClose={() => setReleaseFor(null)}
                onConfirm={release}
                isLoading={releasing}
                title="Снять разблокировку?"
                description="Ограничения по текущей ступени начнут действовать сразу."
                confirmLabel="Снять"
                colorPalette="red"
            />
        </VStack>
    );
}
