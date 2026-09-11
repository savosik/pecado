import { useState } from 'react';
import axios from 'axios';
import { Badge, Box, HStack, SimpleGrid, Table, Text, VStack } from '@chakra-ui/react';
import { LuPencil, LuShieldCheck, LuTrash2 } from 'react-icons/lu';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { toastError, toastSuccess } from '@/utils/toast';

const inputStyle = {
    padding: '0.4rem 0.55rem',
    borderRadius: '0.5rem',
    border: '1px solid var(--chakra-colors-border)',
    background: 'var(--chakra-colors-bg-panel)',
    color: 'var(--chakra-colors-fg)',
    width: '100%',
};

const selectStyle = { ...inputStyle, minWidth: '200px' };

/**
 * Отклонения по работнику: у кого условия не общие.
 *
 * Хранится только отклонение от приказа — возврат к общему значению есть
 * удаление строки. Здесь оформляется наём на особых условиях (пониженный оклад,
 * повышенная ставка П2) и фиксируется база гарантии переходного периода.
 */
export default function PersonalOverrides({ personal, managers, components, canEdit, onChanged }) {
    const [editing, setEditing] = useState(null);   // { manager_id, component_key, params }
    const [busy, setBusy] = useState(false);
    const [report, setReport] = useState(null);

    const fixGuarantee = async (overwrite = false) => {
        setBusy(true);
        try {
            const res = await axios.post('/crm/motivation/settings/guarantee', { overwrite });
            onChanged(res.data.personal);
            setReport(res.data.report);
            toastSuccess(res.data.message);
        } catch (e) {
            toastError(e.response?.data?.message ?? 'Не удалось зафиксировать базу');
        } finally {
            setBusy(false);
        }
    };

    const startEdit = (managerId, componentKey, current) => {
        const meta = components[componentKey];
        setEditing({ manager_id: managerId, component_key: componentKey, params: { ...meta.defaults, ...(current ?? {}) }, comment: '' });
    };

    const save = async () => {
        setBusy(true);
        try {
            const res = await axios.post('/crm/motivation/settings/personal', editing);
            onChanged(res.data.personal);
            toastSuccess('Отклонение сохранено');
            setEditing(null);
        } catch (e) {
            toastError(e.response?.data?.message ?? 'Не удалось сохранить');
        } finally {
            setBusy(false);
        }
    };

    const reset = async (managerId, componentKey) => {
        setBusy(true);
        try {
            const res = await axios.delete('/crm/motivation/settings/personal', { data: { manager_id: managerId, component_key: componentKey } });
            onChanged(res.data.personal);
            toastSuccess('Отклонение снято — действуют значения приказа');
        } catch (e) {
            toastError(e.response?.data?.message ?? 'Не удалось снять');
        } finally {
            setBusy(false);
        }
    };

    return (
        <VStack align="stretch" gap={4}>
            <Alert status="info" title="Здесь только те, у кого условия отличаются от приказа">
                Пониженный оклад и повышенная ставка П2 для найма на особых условиях, база гарантии переходного периода — всё это персональное отклонение, а не вторая схема. Работник без отклонений в списке не появляется.
            </Alert>

            {canEdit && (
                <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={4}>
                    <Text fontWeight="700" mb={2}>Добавить отклонение</Text>
                    <HStack gap={2} flexWrap="wrap">
                        <select aria-label="Работник" style={selectStyle} defaultValue="" id="po-manager">
                            <option value="">Работник…</option>
                            {managers.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
                        </select>
                        <select aria-label="Параметр" style={selectStyle} defaultValue="" id="po-component">
                            <option value="">Параметр…</option>
                            {Object.entries(components).map(([key, meta]) => <option key={key} value={key}>{meta.label}</option>)}
                        </select>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => {
                                const managerId = Number(document.getElementById('po-manager').value);
                                const componentKey = document.getElementById('po-component').value;
                                if (!managerId || !componentKey) { toastError('Выберите работника и параметр'); return; }
                                const existing = personal.find((p) => p.manager_id === managerId)?.overrides?.[componentKey];
                                startEdit(managerId, componentKey, existing);
                            }}
                        >
                            <LuPencil /> Задать
                        </Button>
                    </HStack>
                </Box>
            )}

            {canEdit && (
                <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={4}>
                    <HStack justify="space-between" flexWrap="wrap" gap={2}>
                        <Box>
                            <Text fontWeight="700">База гарантии переходного периода (п. 12.3)</Text>
                            <Text fontSize="xs" color="fg.muted">Среднее за три месяца до введения Положения фиксируется один раз всем работникам и действует первый квартал новой системы. Уже зафиксированные не трогаются.</Text>
                        </Box>
                        <HStack gap={2}>
                            <Button size="sm" variant="outline" loading={busy} onClick={() => fixGuarantee(false)}><LuShieldCheck /> Зафиксировать базу</Button>
                            <Button size="sm" variant="ghost" loading={busy} onClick={() => fixGuarantee(true)}>Пересчитать заново</Button>
                        </HStack>
                    </HStack>
                    {report && (
                        <Box mt={3} overflowX="auto">
                            <Text fontSize="xs" color="fg.muted" mb={1}>Введение с {report.effective_from.slice(0, 7)}, гарантия до {report.until.slice(0, 7)} (не включая).</Text>
                            <Table.Root size="sm">
                                <Table.Body>
                                    {report.rows.map((r) => (
                                        <Table.Row key={r.manager.id}>
                                            <Table.Cell><Text fontSize="sm" fontWeight="600">{r.manager.name}</Text></Table.Cell>
                                            <Table.Cell><Text fontSize="xs" color="fg.muted">{r.months.map((m) => `${m.month.slice(0, 7)}: ${Math.round(m.total).toLocaleString('ru-RU')} ₽ (${m.status})`).join(' · ')}</Text></Table.Cell>
                                            <Table.Cell textAlign="right"><Text fontSize="sm">среднее {Math.round(r.average).toLocaleString('ru-RU')} ₽ · минимум {Math.round(r.minimum).toLocaleString('ru-RU')} ₽</Text></Table.Cell>
                                            <Table.Cell><Badge size="xs" variant="subtle" colorPalette={r.skipped ? 'gray' : 'green'}>{r.skipped ? 'уже была' : 'зафиксировано'}</Badge></Table.Cell>
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </Table.Root>
                        </Box>
                    )}
                </Box>
            )}

            {editing && (
                <Box bg="bg.panel" borderWidth="2px" borderColor="blue.solid" borderRadius="xl" p={4}>
                    <Text fontWeight="700" mb={1}>
                        {managers.find((m) => m.id === editing.manager_id)?.name} · {components[editing.component_key]?.label}
                    </Text>
                    <Text fontSize="xs" color="fg.muted" mb={3}>Значения, совпадающие с приказом, не сохраняются — хранится только отличие.</Text>
                    <SimpleGrid columns={{ base: 1, sm: 2, md: 3 }} gap={3} mb={3}>
                        {Object.entries(components[editing.component_key]?.schema?.properties ?? {}).map(([key, prop]) => (
                            <VStack key={key} align="stretch" gap={1}>
                                <Text fontSize="xs" color="fg.muted">{prop.title ?? key}</Text>
                                {(Array.isArray(prop.type) ? prop.type.includes('string') : prop.type === 'string') ? (
                                    <input
                                        type="text"
                                        style={inputStyle}
                                        value={editing.params[key] ?? ''}
                                        aria-label={prop.title ?? key}
                                        placeholder="пусто — без ограничения"
                                        onChange={(e) => setEditing({ ...editing, params: { ...editing.params, [key]: e.target.value === '' ? null : e.target.value } })}
                                    />
                                ) : (
                                    <input
                                        type="number"
                                        step="any"
                                        style={inputStyle}
                                        value={editing.params[key] ?? ''}
                                        aria-label={prop.title ?? key}
                                        onChange={(e) => setEditing({ ...editing, params: { ...editing.params, [key]: e.target.value === '' ? null : Number(e.target.value) } })}
                                    />
                                )}
                            </VStack>
                        ))}
                        <VStack align="stretch" gap={1}>
                            <Text fontSize="xs" color="fg.muted">Основание (приказ о приёме, дата)</Text>
                            <input style={inputStyle} value={editing.comment} aria-label="Основание" onChange={(e) => setEditing({ ...editing, comment: e.target.value })} />
                        </VStack>
                    </SimpleGrid>
                    <HStack justify="flex-end" gap={2}>
                        <Button size="sm" variant="ghost" onClick={() => setEditing(null)}>Отмена</Button>
                        <Button size="sm" onClick={save} loading={busy}>Сохранить</Button>
                    </HStack>
                </Box>
            )}

            {personal.length === 0 ? (
                <Text fontSize="sm" color="fg.muted">Отклонений нет: у всех работников действуют значения приказа.</Text>
            ) : (
                <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" overflowX="auto">
                    <Table.Root size="sm">
                        <Table.Header>
                            <Table.Row>
                                <Table.ColumnHeader>Работник</Table.ColumnHeader>
                                <Table.ColumnHeader>Параметр</Table.ColumnHeader>
                                <Table.ColumnHeader>Отличия от приказа</Table.ColumnHeader>
                                {canEdit && <Table.ColumnHeader textAlign="right">Действия</Table.ColumnHeader>}
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {personal.flatMap((row) => Object.entries(row.overrides).map(([componentKey, params]) => (
                                <Table.Row key={`${row.manager_id}-${componentKey}`}>
                                    <Table.Cell><Text fontSize="sm" fontWeight="600">{row.name}</Text></Table.Cell>
                                    <Table.Cell><Text fontSize="sm">{components[componentKey]?.label ?? componentKey}</Text></Table.Cell>
                                    <Table.Cell>
                                        <HStack gap={1} flexWrap="wrap">
                                            {Object.entries(params).map(([k, v]) => (
                                                <Badge key={k} size="xs" variant="subtle" colorPalette="blue">{components[componentKey]?.schema?.properties?.[k]?.title ?? k}: {String(v)}</Badge>
                                            ))}
                                        </HStack>
                                    </Table.Cell>
                                    {canEdit && (
                                        <Table.Cell textAlign="right">
                                            <HStack justify="flex-end" gap={1}>
                                                <Button size="xs" variant="ghost" aria-label="Изменить" onClick={() => startEdit(row.manager_id, componentKey, params)}><LuPencil /></Button>
                                                <Button size="xs" variant="ghost" colorPalette="red" aria-label="Снять отклонение" onClick={() => reset(row.manager_id, componentKey)} loading={busy}><LuTrash2 /></Button>
                                            </HStack>
                                        </Table.Cell>
                                    )}
                                </Table.Row>
                            )))}
                        </Table.Body>
                    </Table.Root>
                </Box>
            )}
        </VStack>
    );
}
