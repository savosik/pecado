import { useCallback, useEffect, useMemo, useState } from 'react';
import axios from 'axios';
import { router } from '@inertiajs/react';
import { Box, Dialog, Flex, HStack, Input, Portal, Text, VStack } from '@chakra-ui/react';
import { LuPlus, LuTrash2 } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field } from '@/components/ui/field';
import { NativeSelectField, NativeSelectRoot } from '@/components/ui/native-select';
import { toaster } from '@/components/ui/toaster';

/**
 * Конструктор правила: событие → область → условия → получатели → порядок.
 *
 * Значения условий выбираются из подсказок, а не набираются руками: опечатка
 * в статусе или ИНН дала бы молча не работающее правило, которое потом никто
 * не найдёт. Технических ключей событий менеджер не видит — только русские
 * названия из реестра.
 */
export default function RuleFormDialog({ rule, events, canManageAll, onClose }) {
    const [form, setForm] = useState(() => ({
        name: rule?.name || '',
        description: rule?.description || '',
        event_key: rule?.event_key || '',
        scope_type: rule?.scope_type || (canManageAll ? 'global' : 'company'),
        scope_user_id: rule?.scope_user_id || null,
        scope_company_id: rule?.scope_company_id || null,
        priority: rule?.priority ?? 100,
        stop_processing: rule?.stop_processing || false,
        is_active: rule?.is_active ?? true,
        throttle_seconds: rule?.throttle_seconds || '',
    }));

    const [conditions, setConditions] = useState(() => toRows(rule?.conditions));
    const [recipients, setRecipients] = useState(() =>
        (rule?.recipients || []).map((r) => ({
            kind: r.kind,
            contact_id: r.contact_id,
            value: r.value || '',
            is_fallback: r.is_fallback,
        })),
    );
    const [meta, setMeta] = useState({ fields: [], roles: [], recipient_kinds: [], config_lists: [] });
    const [contacts, setContacts] = useState([]);
    const [saving, setSaving] = useState(false);

    /** Условия хранятся деревом, но в форме показываются плоским списком строк. */
    function toRows(tree) {
        if (!tree) return [];
        if (Array.isArray(tree.all)) return tree.all.filter((n) => !n.all && !n.any);
        return [tree];
    }

    const loadMeta = useCallback(async (eventKey) => {
        const { data } = await axios.get('/crm/notifications/rules/meta', { params: { event_key: eventKey } });
        setMeta(data);
    }, []);

    useEffect(() => {
        loadMeta(form.event_key);
    }, [form.event_key, loadMeta]);

    useEffect(() => {
        if (!form.scope_company_id && !form.scope_user_id) {
            setContacts([]);
            return;
        }
        axios
            .get('/crm/notifications/rules/contacts', {
                params: { user_id: form.scope_user_id, company_id: form.scope_company_id },
            })
            .then(({ data }) => setContacts(data.data || []))
            .catch(() => setContacts([]));
    }, [form.scope_company_id, form.scope_user_id]);

    const fieldByKey = useMemo(() => {
        const map = {};
        meta.fields?.forEach((f) => { map[f.key] = f; });
        return map;
    }, [meta.fields]);

    const submit = () => {
        if (!form.event_key) {
            toaster.error({ title: 'Выберите событие' });
            return;
        }
        if (recipients.length === 0) {
            toaster.error({ title: 'Добавьте хотя бы одного получателя' });
            return;
        }

        const payload = {
            ...form,
            throttle_seconds: form.throttle_seconds === '' ? null : Number(form.throttle_seconds),
            conditions: conditions.length === 0 ? null : (conditions.length === 1 ? conditions[0] : { all: conditions }),
            recipients,
        };

        setSaving(true);
        const done = { preserveScroll: true, onFinish: () => setSaving(false), onSuccess: onClose };

        if (rule?.id) {
            router.patch(`/crm/notifications/rules/${rule.id}`, payload, done);
        } else {
            router.post('/crm/notifications/rules', payload, done);
        }
    };

    const addCondition = () => setConditions([...conditions, { field: meta.fields?.[0]?.key || '', op: '=', value: '' }]);
    const dropCondition = (i) => setConditions(conditions.filter((_, idx) => idx !== i));
    const patchCondition = (i, patch) =>
        setConditions(conditions.map((c, idx) => (idx === i ? { ...c, ...patch } : c)));

    const addRecipient = () => setRecipients([...recipients, { kind: 'client_user', value: '', is_fallback: false }]);
    const dropRecipient = (i) => setRecipients(recipients.filter((_, idx) => idx !== i));
    const patchRecipient = (i, patch) =>
        setRecipients(recipients.map((r, idx) => (idx === i ? { ...r, ...patch } : r)));

    return (
        <Dialog.Root open onOpenChange={(e) => { if (!e.open) onClose(); }} size="xl" scrollBehavior="inside">
            <Portal>
                <Dialog.Backdrop />
                <Dialog.Positioner>
                    <Dialog.Content>
                        <Dialog.Header>
                            <Dialog.Title>{rule?.id ? 'Правило уведомления' : 'Новое правило уведомления'}</Dialog.Title>
                        </Dialog.Header>

                        <Dialog.Body>
                            <VStack align="stretch" gap={5}>
                                <Field label="Название" required helperText="Как правило будет называться в списке">
                                    <Input
                                        size="sm"
                                        value={form.name}
                                        onChange={(e) => setForm({ ...form, name: e.target.value })}
                                        placeholder="Просрочка — бухгалтеру контрагента"
                                    />
                                </Field>

                                <Field label="Когда это происходит" required>
                                    <NativeSelectRoot size="sm">
                                        <NativeSelectField
                                            value={form.event_key}
                                            onChange={(e) => { setForm({ ...form, event_key: e.target.value }); setConditions([]); }}
                                        >
                                            <option value="">— выберите событие —</option>
                                            {events.map((group) => (
                                                <optgroup key={group.group} label={group.group}>
                                                    {group.items.map((item) => (
                                                        <option key={item.value} value={item.value}>{item.label}</option>
                                                    ))}
                                                </optgroup>
                                            ))}
                                        </NativeSelectField>
                                    </NativeSelectRoot>
                                </Field>

                                <Field label="У кого" helperText={canManageAll ? 'Правило для всех партнёров — основной способ настройки' : 'Правила для всех партнёров ведёт руководитель отдела'}>
                                    <NativeSelectRoot size="sm">
                                        <NativeSelectField
                                            value={form.scope_type}
                                            onChange={(e) => setForm({ ...form, scope_type: e.target.value })}
                                        >
                                            {canManageAll && <option value="global">У всех партнёров</option>}
                                            <option value="company">У конкретного контрагента</option>
                                            <option value="user">У конкретного партнёра</option>
                                        </NativeSelectField>
                                    </NativeSelectRoot>
                                </Field>

                                {form.scope_type === 'company' && (
                                    <Field label="Контрагент" required helperText="Укажите числовой идентификатор из карточки контрагента">
                                        <Input
                                            size="sm"
                                            type="number"
                                            value={form.scope_company_id || ''}
                                            onChange={(e) => setForm({ ...form, scope_company_id: Number(e.target.value) || null })}
                                        />
                                    </Field>
                                )}

                                {form.scope_type === 'user' && (
                                    <Field label="Партнёр" required helperText="Укажите числовой идентификатор из карточки партнёра">
                                        <Input
                                            size="sm"
                                            type="number"
                                            value={form.scope_user_id || ''}
                                            onChange={(e) => setForm({ ...form, scope_user_id: Number(e.target.value) || null })}
                                        />
                                    </Field>
                                )}

                                <Box>
                                    <Text fontWeight="600" mb={2}>Дополнительные условия</Text>
                                    <Text fontSize="xs" color="fg.muted" mb={3}>
                                        Без условий правило сработает на каждое такое событие.
                                    </Text>

                                    <VStack align="stretch" gap={2}>
                                        {conditions.map((condition, index) => {
                                            const spec = fieldByKey[condition.field];
                                            return (
                                                <Flex key={index} gap={2} align="end" wrap="wrap">
                                                    <Field label={index === 0 ? 'Поле' : ''} flex="1 1 180px">
                                                        <NativeSelectRoot size="sm">
                                                            <NativeSelectField
                                                                value={condition.field}
                                                                onChange={(e) => patchCondition(index, { field: e.target.value, value: '' })}
                                                            >
                                                                {meta.fields?.map((f) => (
                                                                    <option key={f.key} value={f.key}>{f.label}</option>
                                                                ))}
                                                            </NativeSelectField>
                                                        </NativeSelectRoot>
                                                    </Field>

                                                    <Field label={index === 0 ? 'Сравнение' : ''} flex="0 0 150px">
                                                        <NativeSelectRoot size="sm">
                                                            <NativeSelectField
                                                                value={condition.op}
                                                                onChange={(e) => patchCondition(index, { op: e.target.value })}
                                                            >
                                                                {(spec?.operators || ['=']).map((op) => (
                                                                    <option key={op} value={op}>{opLabel(op)}</option>
                                                                ))}
                                                            </NativeSelectField>
                                                        </NativeSelectRoot>
                                                    </Field>

                                                    <Field label={index === 0 ? 'Значение' : ''} flex="1 1 180px">
                                                        {spec?.options?.length ? (
                                                            <NativeSelectRoot size="sm">
                                                                <NativeSelectField
                                                                    value={Array.isArray(condition.value) ? condition.value[0] : condition.value}
                                                                    onChange={(e) => patchCondition(index, {
                                                                        value: ['in', 'not_in'].includes(condition.op) ? [e.target.value] : e.target.value,
                                                                    })}
                                                                >
                                                                    <option value="">— выберите —</option>
                                                                    {spec.options.map((o) => (
                                                                        <option key={o.value} value={o.value}>{o.label}</option>
                                                                    ))}
                                                                </NativeSelectField>
                                                            </NativeSelectRoot>
                                                        ) : (
                                                            <Input
                                                                size="sm"
                                                                value={condition.value ?? ''}
                                                                onChange={(e) => patchCondition(index, { value: e.target.value })}
                                                            />
                                                        )}
                                                    </Field>

                                                    <Button size="xs" variant="ghost" colorPalette="red" onClick={() => dropCondition(index)}>
                                                        <LuTrash2 />
                                                    </Button>
                                                </Flex>
                                            );
                                        })}
                                    </VStack>

                                    <Button size="xs" variant="outline" mt={2} onClick={addCondition} disabled={!form.event_key}>
                                        <LuPlus /> Добавить условие
                                    </Button>
                                </Box>

                                <Box>
                                    <Text fontWeight="600" mb={2}>Кому отправить</Text>
                                    <Text fontSize="xs" color="fg.muted" mb={3}>
                                        Роль вместо конкретного человека работает для всех контрагентов сразу
                                        и подхватывает нового сотрудника без правки правила.
                                    </Text>

                                    <VStack align="stretch" gap={2}>
                                        {recipients.map((recipient, index) => (
                                            <Flex key={index} gap={2} align="end" wrap="wrap">
                                                <Field label={index === 0 ? 'Адресат' : ''} flex="1 1 220px">
                                                    <NativeSelectRoot size="sm">
                                                        <NativeSelectField
                                                            value={recipient.kind}
                                                            onChange={(e) => patchRecipient(index, { kind: e.target.value, value: '', contact_id: null })}
                                                        >
                                                            {meta.recipient_kinds?.map((k) => (
                                                                <option key={k.value} value={k.value}>{k.label}</option>
                                                            ))}
                                                        </NativeSelectField>
                                                    </NativeSelectRoot>
                                                </Field>

                                                {recipient.kind === 'contact_role' && (
                                                    <Field label={index === 0 ? 'Роль' : ''} flex="1 1 160px">
                                                        <NativeSelectRoot size="sm">
                                                            <NativeSelectField
                                                                value={recipient.value}
                                                                onChange={(e) => patchRecipient(index, { value: e.target.value })}
                                                            >
                                                                <option value="">— выберите —</option>
                                                                {meta.roles?.map((r) => (
                                                                    <option key={r.value} value={r.value}>{r.label}</option>
                                                                ))}
                                                            </NativeSelectField>
                                                        </NativeSelectRoot>
                                                    </Field>
                                                )}

                                                {recipient.kind === 'contact' && (
                                                    <Field label={index === 0 ? 'Контакт' : ''} flex="1 1 220px">
                                                        <NativeSelectRoot size="sm">
                                                            <NativeSelectField
                                                                value={recipient.contact_id || ''}
                                                                onChange={(e) => patchRecipient(index, { contact_id: Number(e.target.value) || null })}
                                                            >
                                                                <option value="">— выберите —</option>
                                                                {contacts.map((c) => (
                                                                    <option key={c.id} value={c.id}>{c.label}</option>
                                                                ))}
                                                            </NativeSelectField>
                                                        </NativeSelectRoot>
                                                    </Field>
                                                )}

                                                {(recipient.kind === 'email' || recipient.kind === 'suppress') && (
                                                    <Field label={index === 0 ? 'Адрес' : ''} flex="1 1 220px">
                                                        <Input
                                                            size="sm"
                                                            type="email"
                                                            value={recipient.value}
                                                            onChange={(e) => patchRecipient(index, { value: e.target.value })}
                                                        />
                                                    </Field>
                                                )}

                                                {recipient.kind === 'config_list' && (
                                                    <Field label={index === 0 ? 'Список' : ''} flex="1 1 220px">
                                                        <NativeSelectRoot size="sm">
                                                            <NativeSelectField
                                                                value={recipient.value}
                                                                onChange={(e) => patchRecipient(index, { value: e.target.value })}
                                                            >
                                                                <option value="">— выберите —</option>
                                                                {meta.config_lists?.map((l) => (
                                                                    <option key={l.value} value={l.value}>{l.label}</option>
                                                                ))}
                                                            </NativeSelectField>
                                                        </NativeSelectRoot>
                                                    </Field>
                                                )}

                                                <Checkbox
                                                    checked={recipient.is_fallback}
                                                    onCheckedChange={(e) => patchRecipient(index, { is_fallback: !!e.checked })}
                                                >
                                                    <Text fontSize="xs">только если некому больше</Text>
                                                </Checkbox>

                                                <Button size="xs" variant="ghost" colorPalette="red" onClick={() => dropRecipient(index)}>
                                                    <LuTrash2 />
                                                </Button>
                                            </Flex>
                                        ))}
                                    </VStack>

                                    <Button size="xs" variant="outline" mt={2} onClick={addRecipient}>
                                        <LuPlus /> Добавить получателя
                                    </Button>

                                    {form.scope_type !== 'global' && contacts.length === 0 && (
                                        <Text fontSize="xs" color="orange.600" mt={2}>
                                            У этого контрагента нет контактов с адресом — правило, адресованное роли,
                                            писать будет некому. Добавьте контакты на вкладке «Контакты» его карточки.
                                        </Text>
                                    )}
                                </Box>

                                <Flex gap={4} wrap="wrap">
                                    <Field label="Приоритет" flex="0 0 140px" helperText="Меньше — раньше">
                                        <Input
                                            size="sm"
                                            type="number"
                                            value={form.priority}
                                            onChange={(e) => setForm({ ...form, priority: Number(e.target.value) })}
                                        />
                                    </Field>
                                    <Field label="Не чаще, сек" flex="0 0 160px" helperText="Пусто — без ограничения">
                                        <Input
                                            size="sm"
                                            type="number"
                                            value={form.throttle_seconds}
                                            onChange={(e) => setForm({ ...form, throttle_seconds: e.target.value })}
                                        />
                                    </Field>
                                </Flex>

                                <Checkbox
                                    checked={form.stop_processing}
                                    onCheckedChange={(e) => setForm({ ...form, stop_processing: !!e.checked })}
                                >
                                    <Text fontSize="sm">Не обрабатывать следующие правила</Text>
                                </Checkbox>

                                {form.stop_processing && (
                                    <Box borderWidth="1px" borderColor="orange.300" borderRadius="md" p={3} bg="orange.50" _dark={{ bg: 'orange.950' }}>
                                        <Text fontSize="sm">
                                            Правила с приоритетом больше {form.priority} по этому же событию
                                            рассматриваться не будут. Так задаётся «вместо»: например, закрытие
                                            заказа уходит директору и не уходит клиенту.
                                        </Text>
                                    </Box>
                                )}
                            </VStack>
                        </Dialog.Body>

                        <Dialog.Footer>
                            <HStack>
                                <Button variant="ghost" onClick={onClose}>Отмена</Button>
                                <Button onClick={submit} loading={saving}>Сохранить</Button>
                            </HStack>
                        </Dialog.Footer>
                    </Dialog.Content>
                </Dialog.Positioner>
            </Portal>
        </Dialog.Root>
    );
}

function opLabel(op) {
    const map = {
        '=': 'равно',
        '!=': 'не равно',
        in: 'один из',
        not_in: 'кроме',
        '>': 'больше',
        '>=': 'от',
        '<': 'меньше',
        '<=': 'до',
        between: 'в диапазоне',
        contains: 'содержит',
        not_contains: 'не содержит',
        is_empty: 'не заполнено',
        not_empty: 'заполнено',
        has_tag: 'есть метка',
        not_has_tag: 'нет метки',
    };
    return map[op] || op;
}
