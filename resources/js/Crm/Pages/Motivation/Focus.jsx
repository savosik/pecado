import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { Badge, Box, HStack, SimpleGrid, Table, Text, VStack } from '@chakra-ui/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import MetricHint from '@/Crm/Components/MetricHint';
import PartnerActions, { usePartnerDialogs } from './components/PartnerActions';
import { fmtDay, fmtPercent, fmtRub, fmtRub0 } from '../Salary/components/format';

const selectStyle = {
    padding: '0.45rem 0.6rem',
    borderRadius: '0.5rem',
    border: '1px solid var(--chakra-colors-border)',
    minWidth: '180px',
    background: 'var(--chakra-colors-bg-panel)',
    color: 'var(--chakra-colors-fg)',
};

const COLLAPSED = 12;

/**
 * «Фокус-товары»: что продвигать и кому это можно предложить сегодня.
 *
 * Блок «сколько заработано» показывает сумму честно, без укрупнения:
 * при нынешних продажах собственных марок это сотни рублей в месяц.
 */
export default function MotivationFocus({ month, month_label: monthLabel, manager, scope_options: scopeOptions, can_see_all: canSeeAll, data }) {
    const { dialogs, setTaskFor, setCallFor } = usePartnerDialogs();
    const [showAll, setShowAll] = useState(false);
    const items = data?.items ?? [];
    const partners = data?.partners ?? [];
    const visible = showAll ? items : items.slice(0, COLLAPSED);

    const navigate = (changes) => {
        const params = { month, ...changes };
        if (canSeeAll && manager?.id && !('manager' in changes)) params.manager = manager.id;
        Object.keys(params).forEach((k) => { if (params[k] === '' || params[k] === null || params[k] === undefined) delete params[k]; });
        router.get('/crm/motivation/focus', params, { preserveState: true, preserveScroll: true, replace: true });
    };

    return (
        <CrmLayout breadcrumbs={[{ label: 'Продажи' }, { label: 'Моя мотивация', href: '/crm/motivation' }, { label: 'Фокус-товары' }]}>
            <Head title="Фокус-товары — CRM" />
            <PageHeader
                title="Фокус-товары"
                description="Что продвигать и кому это можно предложить сегодня."
                actions={canSeeAll && (scopeOptions ?? []).length > 0 ? (
                    <select aria-label="Работник" style={selectStyle} value={manager?.id ?? ''} onChange={(e) => navigate({ manager: e.target.value })}>
                        <option value="">Выберите работника…</option>
                        {scopeOptions.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
                    </select>
                ) : null}
            />

            <VStack align="stretch" gap={4}>
                {manager === null && (
                    <Alert status="info" title="Карточка работника не привязана">Выберите работника выше или обратитесь к руководителю.</Alert>
                )}

                {data && (
                    <>
                        <SimpleGrid columns={{ base: 1, sm: 3 }} gap={3}>
                            <Stat label="Позиций в перечне" value={String(items.length)} hint="Состав перечня на этот период. Меняется приказом руководителя; прошлые месяцы читаются по снимку." />
                            <Stat label={`Отгружено из перечня за ${monthLabel.toLowerCase()}`} value={data.earned ? fmtRub0(data.earned.revenue) : '—'} />
                            <Stat label="Начислено П3" value={data.earned ? `+${fmtRub(data.earned.amount, 0)}` : '—'} tone="green" hint={`Ставка ${fmtPercent(data.rate_p3, 2)} с отгрузок перечня, с первого рубля. ${data.note}`} />
                        </SimpleGrid>

                        <Alert status="info" title="Это надбавка за состав проданного, а не основной источник дохода">
                            {data.note}
                        </Alert>

                        {items.length === 0 ? (
                            <Alert status="warning" title="Фокус-перечень на этот период пуст">
                                Перечень заполняет руководитель приказом. Пока он пуст, показатель П3 не начисляется.
                            </Alert>
                        ) : (
                            <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" overflowX="auto">
                                <HStack px={4} pt={3} pb={1} justify="space-between">
                                    <Text fontWeight="700">Что сейчас в перечне</Text>
                                    {items.length > COLLAPSED && (
                                        <Button size="xs" variant="ghost" onClick={() => setShowAll((v) => !v)}>
                                            {showAll ? 'Свернуть' : `Показать все ${items.length}`}
                                        </Button>
                                    )}
                                </HStack>
                                <Table.Root size="sm">
                                    <Table.Header>
                                        <Table.Row>
                                            <Table.ColumnHeader>Позиция</Table.ColumnHeader>
                                            <Table.ColumnHeader textAlign="right">Ставка</Table.ColumnHeader>
                                            <Table.ColumnHeader textAlign="right">Остаток</Table.ColumnHeader>
                                            <Table.ColumnHeader>В перечне с</Table.ColumnHeader>
                                            <Table.ColumnHeader>Выходит</Table.ColumnHeader>
                                        </Table.Row>
                                    </Table.Header>
                                    <Table.Body>
                                        {visible.map((item) => (
                                            <Table.Row key={item.id}>
                                                <Table.Cell>
                                                    <Text fontSize="sm">{item.name}</Text>
                                                    {item.sku && <Text fontSize="xs" color="fg.subtle">{item.sku}</Text>}
                                                </Table.Cell>
                                                <Table.Cell textAlign="right">
                                                    <HStack justify="flex-end" gap={1}>
                                                        <Text fontSize="sm">{fmtPercent(item.rate, 2)}</Text>
                                                        {item.own_rate && <Badge size="xs" colorPalette="purple" variant="subtle">повышенная</Badge>}
                                                    </HStack>
                                                </Table.Cell>
                                                <Table.Cell textAlign="right"><Text fontSize="sm" color={item.stock > 0 ? undefined : 'orange.fg'} fontVariantNumeric="tabular-nums">{item.stock > 0 ? `${item.stock} шт` : 'нет на складе'}</Text></Table.Cell>
                                                <Table.Cell><Text fontSize="sm">{item.starts_on ? fmtDay(item.starts_on) : '—'}</Text></Table.Cell>
                                                <Table.Cell><Text fontSize="sm" color={item.ends_on ? 'orange.fg' : 'fg.subtle'}>{item.ends_on ? fmtDay(item.ends_on) : 'бессрочно'}</Text></Table.Cell>
                                            </Table.Row>
                                        ))}
                                    </Table.Body>
                                </Table.Root>
                            </Box>
                        )}

                        <Box>
                            <HStack gap={2} mb={2}>
                                <Text fontWeight="700">Кому предложить</Text>
                                <MetricHint text="Партнёры, покупающие у нас категории, в которых перечень представлен. Потенциал — их обычный месячный объём в этих категориях; «даст вам» — потенциал по ставке П3. Закупок у других поставщиков в данных нет." />
                            </HStack>
                            {partners.length === 0 ? (
                                <Alert status="info" title="Предлагать пока некому">
                                    Никто из ваших партнёров не покупает категории, в которых представлен перечень, — либо перечень пуст.
                                </Alert>
                            ) : (
                                <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" overflowX="auto">
                                    <Table.Root size="sm">
                                        <Table.Header>
                                            <Table.Row>
                                                <Table.ColumnHeader>Партнёр</Table.ColumnHeader>
                                                <Table.ColumnHeader textAlign="right">Берёт из перечня</Table.ColumnHeader>
                                                <Table.ColumnHeader>Похожие категории берёт</Table.ColumnHeader>
                                                <Table.ColumnHeader textAlign="right">Потенциал в месяц</Table.ColumnHeader>
                                                <Table.ColumnHeader textAlign="right">Даст вам</Table.ColumnHeader>
                                                <Table.ColumnHeader>Последняя покупка</Table.ColumnHeader>
                                                <Table.ColumnHeader textAlign="right">Действия</Table.ColumnHeader>
                                            </Table.Row>
                                        </Table.Header>
                                        <Table.Body>
                                            {partners.map((row) => (
                                                <Table.Row key={row.id}>
                                                    <Table.Cell><Link href={`/crm/partners/${row.id}`}><Text fontSize="sm" fontWeight="600" _hover={{ textDecoration: 'underline' }}>{row.name}</Text></Link></Table.Cell>
                                                    <Table.Cell textAlign="right"><Text fontSize="sm" fontVariantNumeric="tabular-nums" color={row.focus_this_month > 0 ? undefined : 'fg.subtle'}>{row.focus_this_month > 0 ? fmtRub0(row.focus_this_month) : '—'}</Text></Table.Cell>
                                                    <Table.Cell><HStack gap={1} flexWrap="wrap">{row.categories.map((c) => <Badge key={c} size="xs" variant="outline">{c}</Badge>)}</HStack></Table.Cell>
                                                    <Table.Cell textAlign="right"><Text fontSize="sm" fontVariantNumeric="tabular-nums">{fmtRub0(row.potential)}</Text></Table.Cell>
                                                    <Table.Cell textAlign="right"><Text fontSize="sm" fontWeight="700" color="green.fg" fontVariantNumeric="tabular-nums">+{fmtRub(row.your_gain, 0)}</Text></Table.Cell>
                                                    <Table.Cell><Text fontSize="sm">{row.last_purchase_on ? fmtDay(row.last_purchase_on) : '—'}</Text></Table.Cell>
                                                    <Table.Cell textAlign="right"><PartnerActions row={row} onTask={setTaskFor} onCall={setCallFor} /></Table.Cell>
                                                </Table.Row>
                                            ))}
                                        </Table.Body>
                                    </Table.Root>
                                </Box>
                            )}
                        </Box>
                    </>
                )}
            </VStack>

            {dialogs}
        </CrmLayout>
    );
}

function Stat({ label, value, tone, hint }) {
    return (
        <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={4}>
            <HStack gap={1} fontSize="xs" color="fg.muted">
                <Text>{label}</Text>
                {hint && <MetricHint text={hint} />}
            </HStack>
            <Text fontSize="2xl" fontWeight="800" fontVariantNumeric="tabular-nums" color={tone ? `${tone}.fg` : undefined}>{value}</Text>
        </Box>
    );
}
