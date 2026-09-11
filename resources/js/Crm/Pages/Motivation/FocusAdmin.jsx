import { useEffect, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import { Badge, Box, HStack, SimpleGrid, Table, Text, VStack } from '@chakra-ui/react';
import { LuLock, LuPlus, LuSnowflake, LuCircleX } from 'react-icons/lu';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import MetricHint from '@/Crm/Components/MetricHint';
import { toastError, toastSuccess } from '@/utils/toast';
import { fmtDay, fmtPercent, fmtRub0 } from '../Salary/components/format';
import MotivationTabs from './components/MotivationTabs';
import { hubBreadcrumbs } from './components/hubs';
import FoldSection from './components/FoldSection';

const inputStyle = {
    padding: '0.45rem 0.6rem',
    borderRadius: '0.5rem',
    border: '1px solid var(--chakra-colors-border)',
    minWidth: '160px',
    background: 'var(--chakra-colors-bg-panel)',
    color: 'var(--chakra-colors-fg)',
};

const SCOPES = [
    { value: 'brand', label: 'Бренд целиком' },
    { value: 'category', label: 'Категория с подкатегориями' },
    { value: 'product', label: 'Отдельная позиция' },
];

const STATUS_PALETTE = { active: 'green', scheduled: 'blue', expired: 'gray' };

function monthLabel(iso) {
    const [y, m] = iso.split('-');
    return new Date(Number(y), Number(m) - 1, 1).toLocaleDateString('ru-RU', { month: 'short', year: 'numeric' });
}

/**
 * Фокус-перечень: правила включения, состав на период, добавление, отдача.
 *
 * Даты ограничены нормой п. 6.4.3 и проверяются сервером: включение не раньше
 * первой партии, исключение не раньше первого числа следующего периода.
 */
export default function MotivationFocusAdmin(props) {
    const [data, setData] = useState(props);
    const [busy, setBusy] = useState(false);
    const [form, setForm] = useState({ scope: 'brand', target_id: '', target_name: '', rate: '', starts_on: '', ends_on: '', order_number: '', order_date: '', comment: '' });
    const [q, setQ] = useState('');
    const [options, setOptions] = useState([]);
    const [closing, setClosing] = useState({});

    useEffect(() => { setData(props); }, [props]);

    useEffect(() => {
        if (!data.can_edit) return undefined;
        const handle = setTimeout(async () => {
            try {
                const res = await axios.get('/crm/motivation/focus-list/search', { params: { scope: form.scope, q } });
                setOptions(res.data.options ?? []);
            } catch {
                setOptions([]);
            }
        }, 250);
        return () => clearTimeout(handle);
    }, [form.scope, q, data.can_edit]);

    const navigate = (month) => router.get('/crm/motivation/focus-list', { month }, { preserveState: true, preserveScroll: true, replace: true });

    const call = async (method, url, payload) => {
        setBusy(true);
        try {
            const res = await axios({ method, url, data: payload, params: { month: data.month } });
            setData(res.data);
            if (res.data.message) toastSuccess(res.data.message);
            return true;
        } catch (e) {
            toastError(e.response?.data?.message ?? Object.values(e.response?.data?.errors ?? {}).flat().join(' ') ?? 'Не удалось выполнить');
            return false;
        } finally {
            setBusy(false);
        }
    };

    const pick = (o) => setForm({ ...form, target_id: o.id, target_name: o.name, starts_on: form.starts_on || o.first_arrival_on || '' });

    const items = data.composition?.items ?? [];
    const months = [];
    for (let i = -3; i <= 1; i++) {
        const d = new Date(data.month.slice(0, 4), Number(data.month.slice(5, 7)) - 1 + i, 1);
        months.push(`${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`);
    }

    return (
        <CrmLayout breadcrumbs={hubBreadcrumbs('department', 'focus')}>
            <Head title="Фокус-перечень — CRM" />
            <PageHeader title="Фокус-перечень" description="Что сейчас в перечне и что изменить. Изменение правила не меняет состав в утверждённых периодах." />
            <MotivationTabs hub="department" current="focus" />

            <VStack align="stretch" gap={4}>
                <Alert status="info" title="Цена показателя">{data.note}</Alert>

                <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" overflowX="auto">
                    <HStack px={4} pt={3} gap={1}><Text fontWeight="700">Правила</Text><MetricHint text="Бренд разворачивается во все свои позиции, категория — вместе с подкатегориями. Своя ставка правила приоритетнее общей ставки П3 из приказа." /></HStack>
                    {data.rules.length === 0 ? <Text px={4} pb={4} fontSize="sm" color="fg.muted">Правил ещё нет — перечень пуст, П3 никому не начисляется.</Text> : (
                        <Table.Root size="sm">
                            <Table.Header>
                                <Table.Row>
                                    <Table.ColumnHeader>Что включено</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Ставка</Table.ColumnHeader>
                                    <Table.ColumnHeader>Действует</Table.ColumnHeader>
                                    <Table.ColumnHeader>Основание</Table.ColumnHeader>
                                    <Table.ColumnHeader>Кто</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Действия</Table.ColumnHeader>
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {data.rules.map((r) => (
                                    <Table.Row key={r.id}>
                                        <Table.Cell>
                                            <HStack gap={2}><Badge size="xs" variant="subtle">{r.scope_label}</Badge><Text fontSize="sm" fontWeight="600">{r.target_name}</Text></HStack>
                                            {r.comment && <Text fontSize="xs" color="fg.subtle">{r.comment}</Text>}
                                        </Table.Cell>
                                        <Table.Cell textAlign="right"><Text fontSize="sm">{r.rate === null ? `общая ${fmtPercent(data.rate_p3)}` : fmtPercent(r.rate)}</Text></Table.Cell>
                                        <Table.Cell>
                                            <HStack gap={2}>
                                                <Badge size="xs" variant="subtle" colorPalette={STATUS_PALETTE[r.status]}>{r.status_label}</Badge>
                                                <Text fontSize="xs" color="fg.muted">{fmtDay(r.starts_on)} — {r.ends_on ? fmtDay(r.ends_on) : 'бессрочно'}</Text>
                                            </HStack>
                                        </Table.Cell>
                                        <Table.Cell><Text fontSize="xs" color="fg.muted">{r.order_number ? `Приказ ${r.order_number}${r.order_date ? ` от ${fmtDay(r.order_date)}` : ''}` : '—'}</Text></Table.Cell>
                                        <Table.Cell><Text fontSize="xs" color="fg.muted">{r.author ?? '—'}</Text></Table.Cell>
                                        <Table.Cell textAlign="right">
                                            {data.can_edit && r.status !== 'expired' && (
                                                <HStack justify="flex-end" gap={1}>
                                                    <input type="date" aria-label="Дата исключения" style={{ ...inputStyle, minWidth: '150px', padding: '0.25rem 0.4rem' }} min={r.min_end_on} value={closing[r.id] ?? ''} onChange={(e) => setClosing({ ...closing, [r.id]: e.target.value })} />
                                                    <Button size="xs" variant="outline" colorPalette="orange" loading={busy} disabled={!closing[r.id]} onClick={() => call('patch', `/crm/motivation/focus-list/rules/${r.id}`, { ends_on: closing[r.id] })}><LuCircleX /> Исключить</Button>
                                                </HStack>
                                            )}
                                        </Table.Cell>
                                    </Table.Row>
                                ))}
                            </Table.Body>
                        </Table.Root>
                    )}
                </Box>

                {data.can_edit && (
                    <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={4}>
                        <HStack gap={1} mb={3}><Text fontWeight="700">Добавить в перечень</Text><MetricHint text="Дата включения — не раньше поступления первой партии на склад; дата исключения — не раньше первого числа следующего периода (п. 6.4.3). Ставка — долей от 1, пусто — общая ставка П3." /></HStack>
                        <SimpleGrid columns={{ base: 1, md: 3 }} gap={3}>
                            <select aria-label="Вид правила" style={inputStyle} value={form.scope} onChange={(e) => { setForm({ ...form, scope: e.target.value, target_id: '', target_name: '' }); setQ(''); }}>
                                {SCOPES.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
                            </select>
                            <input aria-label="Поиск цели" style={inputStyle} placeholder="Название или артикул…" value={q} onChange={(e) => setQ(e.target.value)} />
                            <Text fontSize="sm" alignSelf="center">{form.target_name ? <>Выбрано: <b>{form.target_name}</b></> : <Text as="span" color="fg.muted">Ничего не выбрано</Text>}</Text>
                        </SimpleGrid>
                        {options.length > 0 && !form.target_id && (
                            <Box mt={2} maxH="200px" overflowY="auto" borderWidth="1px" borderColor="border" borderRadius="lg">
                                {options.map((o) => (
                                    <HStack key={o.id} as="button" type="button" w="100%" px={3} py={1.5} gap={2} textAlign="left" cursor="pointer" _hover={{ bg: 'bg.subtle' }} onClick={() => pick(o)}>
                                        <Text fontSize="sm">{o.name}</Text>
                                        {o.hint && <Text fontSize="xs" color="fg.subtle">{o.hint}</Text>}
                                        <Text fontSize="xs" color="fg.muted" ml="auto">{o.first_arrival_on ? `первая партия ${fmtDay(o.first_arrival_on)}` : 'партий не было'}</Text>
                                    </HStack>
                                ))}
                            </Box>
                        )}
                        <SimpleGrid columns={{ base: 2, md: 6 }} gap={3} mt={3}>
                            <label style={{ fontSize: '0.75rem' }}>С даты<br /><input type="date" style={inputStyle} value={form.starts_on} onChange={(e) => setForm({ ...form, starts_on: e.target.value })} /></label>
                            <label style={{ fontSize: '0.75rem' }}>По дату<br /><input type="date" style={inputStyle} value={form.ends_on} onChange={(e) => setForm({ ...form, ends_on: e.target.value })} /></label>
                            <label style={{ fontSize: '0.75rem' }}>Своя ставка<br /><input type="number" step="0.001" min="0" max="1" style={inputStyle} placeholder={`общая ${data.rate_p3}`} value={form.rate} onChange={(e) => setForm({ ...form, rate: e.target.value })} /></label>
                            <label style={{ fontSize: '0.75rem' }}>№ приказа<br /><input style={inputStyle} value={form.order_number} onChange={(e) => setForm({ ...form, order_number: e.target.value })} /></label>
                            <label style={{ fontSize: '0.75rem' }}>Дата приказа<br /><input type="date" style={inputStyle} value={form.order_date} onChange={(e) => setForm({ ...form, order_date: e.target.value })} /></label>
                            <label style={{ fontSize: '0.75rem' }}>Зачем<br /><input style={inputStyle} placeholder="Новинка, распродажа…" value={form.comment} onChange={(e) => setForm({ ...form, comment: e.target.value })} /></label>
                        </SimpleGrid>
                        <HStack mt={3} justify="flex-end">
                            <Button size="sm" loading={busy} disabled={!form.target_id || !form.starts_on} onClick={async () => {
                                if (await call('post', '/crm/motivation/focus-list/rules', { ...form, rate: form.rate === '' ? null : Number(form.rate) })) {
                                    setForm({ scope: form.scope, target_id: '', target_name: '', rate: '', starts_on: '', ends_on: '', order_number: '', order_date: '', comment: '' });
                                    setQ('');
                                }
                            }}><LuPlus /> Добавить правило</Button>
                        </HStack>
                    </Box>
                )}

                <FoldSection title={'Состав перечня на период'} summary={`${items.length} поз.${data.composition.frozen ? ' · снимок заморожен' : ''}`}>
                    <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" overflowX="auto">
                        <HStack px={4} pt={3} gap={2} flexWrap="wrap">
                            <Text fontWeight="700">Состав на период</Text>
                            <select aria-label="Период" style={{ ...inputStyle, minWidth: '140px', padding: '0.25rem 0.4rem' }} value={data.month} onChange={(e) => navigate(e.target.value)}>
                                {months.map((m) => <option key={m} value={m}>{monthLabel(m)}</option>)}
                            </select>
                            {data.composition.frozen
                                ? <Badge colorPalette="blue" variant="subtle"><LuLock /> снимок заморожен</Badge>
                                : <Badge variant="subtle">из действующих правил</Badge>}
                            <Text fontSize="sm" color="fg.muted">{items.length} поз.</Text>
                            {data.can_edit && !data.composition.frozen && items.length > 0 && (
                                <Button size="xs" variant="ghost" ml="auto" loading={busy} onClick={() => call('post', '/crm/motivation/focus-list/freeze', {})}><LuSnowflake /> Заморозить состав</Button>
                            )}
                        </HStack>
                        {items.length === 0 ? <Text px={4} pb={4} pt={2} fontSize="sm" color="fg.muted">В этом периоде перечень пуст.</Text> : (
                            <Table.Root size="sm" mt={2}>
                                <Table.Header>
                                    <Table.Row>
                                        <Table.ColumnHeader>Позиция</Table.ColumnHeader>
                                        <Table.ColumnHeader>Артикул</Table.ColumnHeader>
                                        <Table.ColumnHeader>По какому правилу</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="right">Ставка</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="right">Остаток</Table.ColumnHeader>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {items.map((i) => (
                                        <Table.Row key={i.id}>
                                            <Table.Cell><Text fontSize="sm">{i.name}</Text></Table.Cell>
                                            <Table.Cell><Text fontSize="xs" color="fg.muted">{i.sku ?? '—'}</Text></Table.Cell>
                                            <Table.Cell><Text fontSize="xs">{i.rule_label}</Text></Table.Cell>
                                            <Table.Cell textAlign="right"><Text fontSize="sm" fontWeight={i.own_rate ? '700' : '400'}>{fmtPercent(i.rate)}</Text></Table.Cell>
                                            <Table.Cell textAlign="right"><Text fontSize="sm" color={i.stock > 0 ? undefined : 'fg.subtle'}>{i.stock}</Text></Table.Cell>
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </Table.Root>
                        )}
                    </Box>
                </FoldSection>

                <FoldSection title={'Отдача: окупается ли показатель'} summary={`за ${data.returns.length} мес.`}>
                    <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" overflowX="auto">
                        <Table.Root size="sm" mt={2}>
                            <Table.Header>
                                <Table.Row>
                                    <Table.ColumnHeader>Месяц</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Позиций</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Отгружено</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Начислено П3</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Партнёров берёт</Table.ColumnHeader>
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {data.returns.map((r) => (
                                    <Table.Row key={r.month}>
                                        <Table.Cell><HStack gap={2}><Text fontSize="sm">{monthLabel(r.month)}</Text>{r.frozen && <LuLock size={12} aria-label="Снимок заморожен" />}</HStack></Table.Cell>
                                        <Table.Cell textAlign="right"><Text fontSize="sm">{r.items}</Text></Table.Cell>
                                        <Table.Cell textAlign="right"><Text fontSize="sm">{fmtRub0(r.shipped)}</Text></Table.Cell>
                                        <Table.Cell textAlign="right"><Text fontSize="sm" fontWeight="600">{fmtRub0(r.accrued)}</Text></Table.Cell>
                                        <Table.Cell textAlign="right"><Text fontSize="sm">{r.partners}</Text></Table.Cell>
                                    </Table.Row>
                                ))}
                            </Table.Body>
                        </Table.Root>
                    </Box>
                </FoldSection>
            </VStack>
        </CrmLayout>
    );
}
