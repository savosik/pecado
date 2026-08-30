import { useState } from 'react';
import axios from 'axios';
import { Badge, Box, HStack, Input, SimpleGrid, Text, VStack } from '@chakra-ui/react';
import { LuPlus } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Switch } from '@/components/ui/switch';
import { toastError, toastSuccess } from '@/utils/toast';
import { LadderEditor, TiersEditor } from './LadderEditor';
import { fmtRub0 } from '../format';

const clone = (value) => JSON.parse(JSON.stringify(value ?? []));

/**
 * Версии схемы отдела: что входит в расчёт и умолчания для всех менеджеров.
 *
 * Версия действует с месяца и не правится — утверждённые месяцы читаются по той
 * схеме, по которой считались. Новая версия начинается копией действующей.
 */
export default function SchemePanel({ versions, components, currentMonth, onSaved }) {
    const [draft, setDraft] = useState(null);
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState([]);

    const startFrom = (scheme) => {
        const [y, m] = currentMonth.split('-').map(Number);
        const next = new Date(y, m, 1);
        setDraft({
            effective_from: `${next.getFullYear()}-${String(next.getMonth() + 1).padStart(2, '0')}`,
            title: '',
            comment: '',
            components: clone(scheme.components),
        });
        setErrors([]);
    };

    const updateComponent = (index, patch) => setDraft({
        ...draft,
        components: draft.components.map((c, i) => (i === index ? { ...c, ...patch } : c)),
    });

    const updateDefaults = (index, patch) => {
        const c = draft.components[index];
        updateComponent(index, { defaults: { ...c.defaults, ...patch } });
    };

    const save = async () => {
        setSaving(true);
        setErrors([]);
        try {
            await axios.post('/crm/salary/settings/scheme', draft);
            toastSuccess('Новая версия схемы сохранена');
            setDraft(null);
            await onSaved?.();
        } catch (e) {
            const data = e.response?.data;
            const list = data?.errors?.components ?? data?.errors?.effective_from ?? (data?.message ? [data.message] : ['Не удалось сохранить']);
            setErrors(Array.isArray(list) ? list : [String(list)]);
            toastError(data?.message ?? 'Не удалось сохранить');
        } finally {
            setSaving(false);
        }
    };

    return (
        <SimpleGrid columns={{ base: 1, xl: draft ? '1fr 2fr' : 1 }} gap={5} alignItems="start">
            <VStack align="stretch" gap={3}>
                {(versions ?? []).map((v, index) => (
                    <Box key={v.id} bg="bg.panel" borderWidth="1px" borderColor={index === 0 ? 'blue.muted' : 'border'} borderRadius="xl" p={4}>
                        <HStack justify="space-between" flexWrap="wrap" gap={2}>
                            <HStack gap={2}>
                                <Text fontWeight="600">v{v.version} · {v.title}</Text>
                                {index === 0 && <Badge size="xs" colorPalette="blue" variant="subtle">последняя</Badge>}
                            </HStack>
                            <Text fontSize="xs" color="fg.muted">с {v.effective_label}{v.author ? ` · ${v.author}` : ''}</Text>
                        </HStack>
                        {v.comment && <Text fontSize="xs" color="fg.muted" mt={1}>{v.comment}</Text>}
                        <HStack gap={2} mt={2} flexWrap="wrap">
                            {v.components.map((c) => (
                                <Badge key={c.key} size="xs" variant={c.enabled ? 'subtle' : 'outline'} colorPalette={c.enabled ? 'green' : 'gray'}>
                                    {components?.[c.key]?.label ?? c.key}
                                    {c.key === 'salary' && c.defaults?.amount !== undefined ? ` ${fmtRub0(c.defaults.amount)}` : ''}
                                    {c.key === 'kpi_bonus' && c.defaults?.base !== undefined ? ` ${fmtRub0(c.defaults.base)} × до ${c.defaults.cap}` : ''}
                                    {!c.enabled ? ' · выкл' : ''}
                                </Badge>
                            ))}
                        </HStack>
                        {index === 0 && !draft && (
                            <Button size="xs" variant="outline" mt={3} onClick={() => startFrom(v)}>
                                <LuPlus /> Новая версия на основе этой
                            </Button>
                        )}
                    </Box>
                ))}
            </VStack>

            {draft && (
                <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={4}>
                    <Text fontWeight="600" mb={3}>Новая версия схемы</Text>
                    <HStack gap={3} align="start" flexWrap="wrap" mb={4}>
                        <Field label="Действует с месяца" maxW="180px">
                            <Input type="month" value={draft.effective_from} onChange={(e) => setDraft({ ...draft, effective_from: e.target.value })} />
                        </Field>
                        <Field label="Название" flex="1" minW="200px">
                            <Input value={draft.title} onChange={(e) => setDraft({ ...draft, title: e.target.value })} placeholder="Например: Схема 2027" maxLength={120} />
                        </Field>
                        <Field label="Что изменилось" flex="1" minW="200px">
                            <Input value={draft.comment} onChange={(e) => setDraft({ ...draft, comment: e.target.value })} maxLength={255} />
                        </Field>
                    </HStack>

                    <VStack align="stretch" gap={4}>
                        {draft.components.map((c, index) => (
                            <Box key={c.key} borderWidth="1px" borderColor="border" borderRadius="lg" p={3}>
                                <HStack justify="space-between" mb={2} gap={3}>
                                    <Box>
                                        <Text fontWeight="600">{components?.[c.key]?.label ?? c.key}</Text>
                                        <Text fontSize="xs" color="fg.muted">{components?.[c.key]?.description}</Text>
                                    </Box>
                                    <Switch checked={Boolean(c.enabled)} onCheckedChange={(e) => updateComponent(index, { enabled: e.checked })}>
                                        {c.enabled ? 'входит' : 'выключен'}
                                    </Switch>
                                </HStack>

                                {c.key === 'salary' && (
                                    <Field label="Оклад по умолчанию, ₽" maxW="220px">
                                        <Input type="number" min="0" step="1000" value={c.defaults?.amount ?? ''} onChange={(e) => updateDefaults(index, { amount: Number(e.target.value) })} />
                                    </Field>
                                )}

                                {c.key === 'kpi_bonus' && (
                                    <VStack align="stretch" gap={3}>
                                        <HStack gap={3} flexWrap="wrap" align="start">
                                            <Field label="База премии, ₽" maxW="200px">
                                                <Input type="number" min="0" step="1000" value={c.defaults?.base ?? ''} onChange={(e) => updateDefaults(index, { base: Number(e.target.value) })} />
                                            </Field>
                                            <Field label="Потолок, ×" maxW="140px">
                                                <Input type="number" min="1" max="10" step="0.1" value={c.defaults?.cap ?? ''} onChange={(e) => updateDefaults(index, { cap: Number(e.target.value) })} />
                                            </Field>
                                        </HStack>
                                        <Text fontSize="sm" fontWeight="600">{components?.active_clients?.label}</Text>
                                        <LadderEditor ladder={c.defaults?.active_clients?.ladder ?? []} onChange={(rows) => updateDefaults(index, { active_clients: { ladder: rows } })} />
                                        <Text fontSize="sm" fontWeight="600">{components?.discipline_penalty?.label}</Text>
                                        <TiersEditor tiers={c.defaults?.discipline_penalty?.tiers ?? []} onChange={(rows) => updateDefaults(index, { discipline_penalty: { tiers: rows } })} />
                                    </VStack>
                                )}

                                {c.key === 'new_clients_bonus' && (
                                    <SimpleGrid columns={{ base: 2, md: 3 }} gap={3}>
                                        {[
                                            ['bonus', 'Бонус за клиента, ₽'], ['min_first_amount', 'Мин. первая отгрузка, ₽'], ['monthly_cap', 'Потолок за месяц, ₽'],
                                            ['repeat_within_days', 'Повтор в течение, дней'], ['returned_weight', 'Вес вернувшегося'], ['returned_after_days', 'Пауза вернувшегося, дней'],
                                        ].map(([key, label]) => (
                                            <Field key={key} label={label}>
                                                <Input type="number" step={key === 'returned_weight' ? '0.1' : '1'} value={c.defaults?.[key] ?? ''} onChange={(e) => updateDefaults(index, { [key]: Number(e.target.value) })} />
                                            </Field>
                                        ))}
                                    </SimpleGrid>
                                )}
                            </Box>
                        ))}
                    </VStack>

                    {errors.length > 0 && (
                        <Box borderWidth="1px" borderColor="red.muted" bg="red.subtle" borderRadius="md" p={3} mt={4}>
                            {errors.map((err) => <Text key={err} fontSize="sm" color="red.fg">{err}</Text>)}
                        </Box>
                    )}

                    <HStack justify="flex-end" gap={2} mt={4}>
                        <Button variant="ghost" onClick={() => setDraft(null)} disabled={saving}>Отмена</Button>
                        <Button colorPalette="blue" onClick={save} loading={saving}>Сохранить версию</Button>
                    </HStack>
                </Box>
            )}
        </SimpleGrid>
    );
}
