import { useEffect, useState } from 'react';
import axios from 'axios';
import { Badge, Box, HStack, Input, Text, VStack } from '@chakra-ui/react';
import {
    DrawerBackdrop, DrawerBody, DrawerCloseTrigger, DrawerContent, DrawerFooter, DrawerHeader, DrawerRoot, DrawerTitle,
} from '@/components/ui/drawer';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { SegmentedControl } from '@/components/ui/segmented-control';
import { toastError, toastSuccess } from '@/utils/toast';
import { LadderEditor, TiersEditor } from './LadderEditor';

const SOURCE = {
    scheme: { label: 'по схеме', palette: 'gray' },
    permanent: { label: 'постоянно', palette: 'blue' },
    month: { label: 'на месяц', palette: 'orange' },
};

const SourceBadge = ({ source }) => {
    const meta = SOURCE[source] ?? SOURCE.scheme;

    return <Badge size="xs" variant="subtle" colorPalette={meta.palette}>{meta.label}</Badge>;
};

const clone = (value) => JSON.parse(JSON.stringify(value ?? {}));

/**
 * Форма параметров одного менеджера: оклад и KPI (база, потолок, лестница, ступени).
 *
 * Слой выбирается переключателем: «на месяц» — отклонение только этого месяца,
 * «постоянно» — для всех месяцев без своего отклонения. Отправляется полный
 * набор параметров компонента, разницу с нижним слоем считает сервер.
 */
export default function ParamsDrawer({ manager, month, monthLabel, components, schemeEnabled, open, onClose, onSaved }) {
    const [scope, setScope] = useState('month');
    const [salary, setSalary] = useState({});
    const [kpi, setKpi] = useState({});
    const [newClients, setNewClients] = useState({});
    const [comment, setComment] = useState('');
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState([]);

    useEffect(() => {
        if (!manager) return;
        setSalary(clone(manager.params?.salary));
        setKpi(clone(manager.params?.kpi_bonus));
        setNewClients(clone(manager.params?.new_clients_bonus));
        setComment('');
        setErrors([]);
        setScope('month');
    }, [manager, open]);

    if (!manager) return null;

    const sources = manager.sources ?? {};
    const ladder = kpi.active_clients?.ladder ?? [];
    const tiers = kpi.discipline_penalty?.tiers ?? [];
    const hasNewClients = Boolean(manager.params?.new_clients_bonus);
    const newClientsEnabled = (schemeEnabled ?? []).includes('new_clients_bonus');
    const nc = (key, fallback = '') => (newClients[key] ?? fallback);
    const setNc = (key, value) => setNewClients({ ...newClients, [key]: value });

    const payload = (component, params) => ({
        manager_id: manager.id,
        month: scope === 'month' ? month : null,
        component,
        params,
        comment: comment || null,
    });

    const save = async () => {
        setSaving(true);
        setErrors([]);
        try {
            await axios.post('/crm/salary/settings/params', payload('salary', { amount: Number(salary.amount ?? 0) }));
            let res = await axios.post('/crm/salary/settings/params', payload('kpi_bonus', {
                ...kpi,
                base: Number(kpi.base ?? 0),
                cap: Number(kpi.cap ?? 2),
                active_clients: { ladder },
                discipline_penalty: { tiers },
            }));
            if (hasNewClients) {
                res = await axios.post('/crm/salary/settings/params', payload('new_clients_bonus', {
                    bonus: Number(newClients.bonus ?? 0),
                    min_first_amount: Number(newClients.min_first_amount ?? 0),
                    repeat_within_days: Number(newClients.repeat_within_days ?? 60),
                    monthly_cap: Number(newClients.monthly_cap ?? 0),
                    returned_weight: Number(newClients.returned_weight ?? 0.5),
                    returned_after_days: Number(newClients.returned_after_days ?? 90),
                }));
            }
            toastSuccess('Параметры сохранены');
            onSaved?.(res.data.manager);
            onClose();
        } catch (e) {
            const data = e.response?.data;
            const list = data?.errors?.params ?? (data?.message ? [data.message] : ['Не удалось сохранить']);
            setErrors(Array.isArray(list) ? list : [String(list)]);
            toastError(data?.message ?? 'Не удалось сохранить');
        } finally {
            setSaving(false);
        }
    };

    const reset = async (component) => {
        setSaving(true);
        try {
            const res = await axios.delete('/crm/salary/settings/params', {
                data: { manager_id: manager.id, month: scope === 'month' ? month : null, component },
            });
            toastSuccess(scope === 'month' ? 'Отклонение на месяц снято' : 'Постоянное отклонение снято');
            onSaved?.(res.data.manager);
            onClose();
        } catch (e) {
            toastError(e.response?.data?.message ?? 'Не удалось сбросить');
        } finally {
            setSaving(false);
        }
    };

    return (
        <DrawerRoot open={open} onOpenChange={(e) => { if (!e.open) onClose(); }} size="lg">
            <DrawerBackdrop />
            <DrawerContent>
                <DrawerHeader>
                    <DrawerTitle>{manager.name}</DrawerTitle>
                    <Text fontSize="sm" color="fg.muted">Параметры зарплаты · {monthLabel}</Text>
                    <DrawerCloseTrigger />
                </DrawerHeader>
                <DrawerBody>
                    <VStack align="stretch" gap={5}>
                        <SegmentedControl
                            size="sm"
                            value={scope}
                            onValueChange={(e) => setScope(e.value)}
                            items={[
                                { value: 'month', label: `Только ${monthLabel.toLowerCase()}` },
                                { value: 'permanent', label: 'Постоянно' },
                            ]}
                        />
                        <Text fontSize="xs" color="fg.muted">
                            {scope === 'month'
                                ? 'Отклонение на этот месяц: в следующем месяце снова подействует постоянное значение или схема.'
                                : 'Постоянное значение для этого менеджера: действует во всех месяцах, где нет отклонения на месяц.'}
                        </Text>

                        <Section title={components?.salary?.label ?? 'Оклад'} hint={components?.salary?.description} onReset={() => reset('salary')}>
                            <Field label={<HStack gap={2}>Оклад, ₽ <SourceBadge source={sources.salary?.amount} /></HStack>}>
                                <Input type="number" min="0" step="1000" value={salary.amount ?? ''} onChange={(e) => setSalary({ ...salary, amount: e.target.value })} />
                            </Field>
                        </Section>

                        <Section title={components?.kpi_bonus?.label ?? 'KPI-премия'} hint={components?.kpi_bonus?.how_computed} onReset={() => reset('kpi_bonus')}>
                            <HStack gap={4} align="start" flexWrap="wrap">
                                <Field label={<HStack gap={2}>База премии, ₽ <SourceBadge source={sources.kpi_bonus?.base} /></HStack>} flex="1" minW="180px">
                                    <Input type="number" min="0" step="1000" value={kpi.base ?? ''} onChange={(e) => setKpi({ ...kpi, base: e.target.value })} />
                                </Field>
                                <Field label={<HStack gap={2}>Потолок, × <SourceBadge source={sources.kpi_bonus?.cap} /></HStack>} helperText="2 = не больше 200 % выполнения" maxW="160px">
                                    <Input type="number" min="1" max="10" step="0.1" value={kpi.cap ?? ''} onChange={(e) => setKpi({ ...kpi, cap: e.target.value })} />
                                </Field>
                            </HStack>

                            <Box mt={4}>
                                <HStack gap={2} mb={2}>
                                    <Text fontSize="sm" fontWeight="600">{components?.active_clients?.label ?? 'Активные плановые клиенты'}</Text>
                                    <SourceBadge source={sources.kpi_bonus?.active_clients} />
                                </HStack>
                                <LadderEditor ladder={ladder} onChange={(rows) => setKpi({ ...kpi, active_clients: { ladder: rows } })} />
                            </Box>

                            <Box mt={4}>
                                <HStack gap={2} mb={2}>
                                    <Text fontSize="sm" fontWeight="600">{components?.discipline_penalty?.label ?? 'Штраф за дисциплину'}</Text>
                                    <SourceBadge source={sources.kpi_bonus?.discipline_penalty} />
                                </HStack>
                                <TiersEditor tiers={tiers} onChange={(rows) => setKpi({ ...kpi, discipline_penalty: { tiers: rows } })} />
                            </Box>
                        </Section>

                        {hasNewClients && (
                            <Section
                                title={components?.new_clients_bonus?.label ?? 'Новые клиенты'}
                                hint={`${components?.new_clients_bonus?.how_computed ?? ''}${newClientsEnabled ? '' : ' Компонент выключен в схеме отдела — параметры сохранятся, но в расчёт не войдут, пока РОП не включит его новой версией схемы.'}`}
                                onReset={() => reset('new_clients_bonus')}
                            >
                                <HStack gap={4} align="start" flexWrap="wrap">
                                    <Field label={<HStack gap={2}>Бонус за клиента, ₽ <SourceBadge source={sources.new_clients_bonus?.bonus} /></HStack>} flex="1" minW="160px">
                                        <Input type="number" min="0" step="500" value={nc('bonus')} onChange={(e) => setNc('bonus', e.target.value)} />
                                    </Field>
                                    <Field label={<HStack gap={2}>Мин. первая отгрузка, ₽ <SourceBadge source={sources.new_clients_bonus?.min_first_amount} /></HStack>} flex="1" minW="160px">
                                        <Input type="number" min="0" step="1000" value={nc('min_first_amount')} onChange={(e) => setNc('min_first_amount', e.target.value)} />
                                    </Field>
                                    <Field label={<HStack gap={2}>Потолок за месяц, ₽ <SourceBadge source={sources.new_clients_bonus?.monthly_cap} /></HStack>} helperText="0 — без потолка" flex="1" minW="160px">
                                        <Input type="number" min="0" step="1000" value={nc('monthly_cap')} onChange={(e) => setNc('monthly_cap', e.target.value)} />
                                    </Field>
                                </HStack>
                                <HStack gap={4} align="start" flexWrap="wrap" mt={3}>
                                    <Field label={<HStack gap={2}>Повтор в течение, дней <SourceBadge source={sources.new_clients_bonus?.repeat_within_days} /></HStack>} flex="1" minW="160px">
                                        <Input type="number" min="1" max="365" step="1" value={nc('repeat_within_days')} onChange={(e) => setNc('repeat_within_days', e.target.value)} />
                                    </Field>
                                    <Field label={<HStack gap={2}>Вес вернувшегося (0–1) <SourceBadge source={sources.new_clients_bonus?.returned_weight} /></HStack>} flex="1" minW="160px">
                                        <Input type="number" min="0" max="1" step="0.1" value={nc('returned_weight')} onChange={(e) => setNc('returned_weight', e.target.value)} />
                                    </Field>
                                    <Field label={<HStack gap={2}>Вернувшийся — пауза дольше, дней <SourceBadge source={sources.new_clients_bonus?.returned_after_days} /></HStack>} flex="1" minW="160px">
                                        <Input type="number" min="1" max="1000" step="1" value={nc('returned_after_days')} onChange={(e) => setNc('returned_after_days', e.target.value)} />
                                    </Field>
                                </HStack>
                            </Section>
                        )}

                        <Field label="Пояснение" optionalText="необязательно">
                            <Input value={comment} onChange={(e) => setComment(e.target.value)} placeholder="Например: испытательный срок, сезонная база" maxLength={255} />
                        </Field>

                        {errors.length > 0 && (
                            <Box borderWidth="1px" borderColor="red.muted" bg="red.subtle" borderRadius="md" p={3}>
                                {errors.map((err) => <Text key={err} fontSize="sm" color="red.fg">{err}</Text>)}
                            </Box>
                        )}
                    </VStack>
                </DrawerBody>
                <DrawerFooter>
                    <Button variant="ghost" onClick={onClose} disabled={saving}>Отмена</Button>
                    <Button colorPalette="blue" onClick={save} loading={saving}>Сохранить</Button>
                </DrawerFooter>
            </DrawerContent>
        </DrawerRoot>
    );
}

function Section({ title, hint, onReset, children }) {
    return (
        <Box borderWidth="1px" borderColor="border" borderRadius="lg" p={4}>
            <HStack justify="space-between" align="start" mb={3} gap={3}>
                <Box>
                    <Text fontWeight="600">{title}</Text>
                    {hint && <Text fontSize="xs" color="fg.muted">{hint}</Text>}
                </Box>
                <Button size="xs" variant="ghost" onClick={onReset}>Сбросить</Button>
            </HStack>
            {children}
        </Box>
    );
}
