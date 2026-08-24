import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Badge, Box, HStack, Input, Text, Textarea, VStack, Wrap } from '@chakra-ui/react';
import axios from 'axios';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Alert } from '@/components/ui/alert';
import { LuPlus, LuX } from 'react-icons/lu';
import SubscriberPicker from './SubscriberPicker';

const controlStyle = {
    padding: '0.5rem',
    borderRadius: '0.375rem',
    border: '1px solid var(--chakra-colors-border)',
    minWidth: '190px',
};

const emptyRule = {
    id: null,
    name: '',
    match: 'all',
    conditions: [{ field: 'tag', op: 'has_tag', value: '' }],
    recipients: [],
    cc: [],
    clients: [],
    auto_send: false,
    is_active: true,
    throttle_minutes: null,
};

/**
 * Форма правила — один экран, без шагов и мастеров.
 *
 * Главное здесь не поля, а превью: список реальных писем, которые уже подошли бы
 * под условие. Абстрактный «список получателей» ничего не говорит о том, верно ли
 * набрано условие; конкретные строки — говорят, и сразу.
 */
export default function RuleForm({
    rule,
    fieldGroups,
    operators,
    unaryOperators,
    tagSuggestions,
    autoSendEnabled,
    onSaved,
    onCancel,
}) {
    const [form, setForm] = useState(() => ({ ...emptyRule, ...(rule || {}) }));
    const [recipientsText, setRecipientsText] = useState(() => (rule?.recipients || []).join(', '));
    const [ccText, setCcText] = useState(() => (rule?.cc || []).join(', '));
    const [preview, setPreview] = useState(null);
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);
    const timer = useRef(null);

    const fieldTypes = useMemo(() => {
        const map = {};
        fieldGroups.forEach((group) => group.fields.forEach((field) => {
            map[field.value] = field.type;
        }));
        return map;
    }, [fieldGroups]);

    const loadPreview = useCallback((state) => {
        axios.post(route('crm.emails.rules.preview'), {
            match: state.match,
            conditions: state.conditions.filter((c) => c.field && c.op),
            client_ids: (state.clients || []).map((item) => item.id),
        })
            .then((res) => setPreview(res.data))
            .catch(() => setPreview(null));
    }, []);

    // Превью пересчитывается по мере набора: смысл в том, чтобы менеджер видел
    // результат раньше, чем нажмёт «Сохранить», а не узнавал о промахе потом.
    // Подписчики входят в пересчёт наравне с условиями: сузив правило до трёх
    // партнёров, менеджер должен сразу увидеть, что улов стал меньше.
    useEffect(() => {
        clearTimeout(timer.current);
        timer.current = setTimeout(() => loadPreview(form), 400);

        return () => clearTimeout(timer.current);
    }, [form.match, JSON.stringify(form.conditions), JSON.stringify(form.clients), loadPreview]);

    const patch = (changes) => setForm((prev) => ({ ...prev, ...changes }));

    const patchCondition = (index, changes) => {
        setForm((prev) => ({
            ...prev,
            conditions: prev.conditions.map((c, i) => {
                if (i !== index) {
                    return c;
                }

                const next = { ...c, ...changes };

                // Сменили поле — старое сравнение может к нему не подходить.
                if (changes.field !== undefined) {
                    const type = fieldTypes[changes.field] || 'text';
                    const allowed = operators[type] || [];

                    if (!allowed.some((op) => op.value === next.op)) {
                        next.op = allowed[0]?.value || 'contains';
                    }
                }

                return next;
            }),
        }));
    };

    const addCondition = () => patch({
        conditions: [...form.conditions, { field: 'tag', op: 'has_tag', value: '' }],
    });

    const removeCondition = (index) => patch({
        conditions: form.conditions.filter((_, i) => i !== index),
    });

    const parseAddresses = (text) => text
        .split(/[,;\n]+/)
        .map((item) => item.trim())
        .filter(Boolean);

    const save = async () => {
        setSaving(true);
        setErrors({});

        const payload = {
            name: form.name,
            match: form.match,
            conditions: form.conditions.filter((c) => c.field && c.op),
            recipients: parseAddresses(recipientsText),
            cc: parseAddresses(ccText),
            client_ids: (form.clients || []).map((item) => item.id),
            auto_send: form.auto_send,
            is_active: form.is_active,
            throttle_minutes: form.throttle_minutes || null,
        };

        try {
            const res = form.id
                ? await axios.patch(route('crm.emails.rules.update', form.id), payload)
                : await axios.post(route('crm.emails.rules.store'), payload);

            onSaved(res.data);
        } catch (e) {
            setErrors(e?.response?.data?.errors || {});

            if (!e?.response?.data?.errors) {
                setErrors({ name: [e?.response?.data?.message || 'Не удалось сохранить правило.'] });
            }
        } finally {
            setSaving(false);
        }
    };

    const errorOf = (key) => (errors[key] ? errors[key][0] : null);

    return (
        <Box borderWidth="1px" borderRadius="lg" p={4}>
            <VStack align="stretch" gap={4}>
                <Box>
                    <Text fontSize="sm" fontWeight="600" mb={1}>Название</Text>
                    <Input
                        value={form.name}
                        onChange={(e) => patch({ name: e.target.value })}
                        placeholder="Например: акты Афониной"
                        maxW="420px"
                    />
                    {errorOf('name') && <Text fontSize="xs" color="red.500" mt={1}>{errorOf('name')}</Text>}
                </Box>

                <Box>
                    <HStack gap={3} mb={2}>
                        <Text fontSize="sm" fontWeight="600">Если письмо</Text>
                        <select
                            value={form.match}
                            onChange={(e) => patch({ match: e.target.value })}
                            style={{ ...controlStyle, minWidth: '210px' }}
                        >
                            <option value="all">подходит под все условия</option>
                            <option value="any">подходит хотя бы под одно</option>
                        </select>
                    </HStack>

                    <VStack align="stretch" gap={2}>
                        {form.conditions.map((condition, index) => {
                            const type = fieldTypes[condition.field] || 'text';
                            const allowed = operators[type] || [];
                            const needsValue = !unaryOperators.includes(condition.op);

                            return (
                                <HStack key={index} gap={2} align="start" flexWrap="wrap">
                                    <select
                                        value={condition.field}
                                        onChange={(e) => patchCondition(index, { field: e.target.value })}
                                        style={controlStyle}
                                    >
                                        {fieldGroups.map((group) => (
                                            <optgroup key={group.label} label={group.label}>
                                                {group.fields.map((field) => (
                                                    <option key={field.value} value={field.value}>{field.label}</option>
                                                ))}
                                            </optgroup>
                                        ))}
                                    </select>

                                    <select
                                        value={condition.op}
                                        onChange={(e) => patchCondition(index, { op: e.target.value })}
                                        style={controlStyle}
                                    >
                                        {allowed.map((op) => (
                                            <option key={op.value} value={op.value}>{op.label}</option>
                                        ))}
                                    </select>

                                    {needsValue && (
                                        <Box>
                                            <Input
                                                value={condition.value ?? ''}
                                                onChange={(e) => patchCondition(index, { value: e.target.value })}
                                                placeholder={condition.field === 'tag' ? 'начните набирать метку' : 'значение'}
                                                list={condition.field === 'tag' ? 'mail-rule-tags' : undefined}
                                                type={type === 'number' ? 'number' : 'text'}
                                                minW="220px"
                                            />
                                            {errorOf(`conditions.${index}.value`) && (
                                                <Text fontSize="xs" color="red.500" mt={1}>
                                                    {errorOf(`conditions.${index}.value`)}
                                                </Text>
                                            )}
                                        </Box>
                                    )}

                                    <Button
                                        size="xs"
                                        variant="ghost"
                                        colorPalette="red"
                                        onClick={() => removeCondition(index)}
                                        title="Убрать условие"
                                    >
                                        <LuX />
                                    </Button>
                                </HStack>
                            );
                        })}
                    </VStack>

                    <datalist id="mail-rule-tags">
                        {tagSuggestions.map((tag) => <option key={tag} value={tag} />)}
                    </datalist>

                    <Button size="xs" variant="outline" mt={2} onClick={addCondition}>
                        <LuPlus /> Ещё условие
                    </Button>

                    {form.conditions.length === 0 && (
                        <Text fontSize="xs" color="orange.600" mt={2}>
                            Без условий правило поймает вообще все письма.
                        </Text>
                    )}
                </Box>

                <SubscriberPicker
                    value={form.clients}
                    onChange={(clients) => patch({ clients })}
                />

                <Box>
                    <Text fontSize="sm" fontWeight="600" mb={1}>Отправить на</Text>
                    <Textarea
                        value={recipientsText}
                        onChange={(e) => setRecipientsText(e.target.value)}
                        placeholder="buh@romashka.ru, glavbuh@romashka.ru"
                        rows={2}
                        maxW="620px"
                    />
                    <Text fontSize="xs" color="fg.muted" mt={1}>
                        Можно написать «клиент» — письмо уйдёт на его адрес, «менеджер» —
                        персональному менеджеру, или роль из справочника контактов
                        («бухгалтер», «директор», «закупщик») — тогда письмо уйдёт всем людям
                        этой роли у партнёра письма.
                    </Text>
                    {errorOf('recipients') && <Text fontSize="xs" color="red.500" mt={1}>{errorOf('recipients')}</Text>}
                    {errorOf('recipients.0') && <Text fontSize="xs" color="red.500" mt={1}>{errorOf('recipients.0')}</Text>}
                </Box>

                <Box>
                    <Text fontSize="sm" fontWeight="600" mb={1}>Копия (необязательно)</Text>
                    <Textarea
                        value={ccText}
                        onChange={(e) => setCcText(e.target.value)}
                        rows={1}
                        maxW="620px"
                    />
                </Box>

                <VStack align="start" gap={2}>
                    <Checkbox
                        checked={form.auto_send}
                        onCheckedChange={(e) => patch({ auto_send: !!e.checked })}
                        disabled={!autoSendEnabled}
                    >
                        Отправлять автоматически, без моего участия
                    </Checkbox>
                    {!autoSendEnabled && (
                        <Text fontSize="xs" color="fg.muted">
                            Автоотправка выключена администратором (MAIL_STREAM_AUTOSEND). Правило будет
                            проставлять получателей, а письмо — ждать самолётика.
                        </Text>
                    )}
                    {form.auto_send && (
                        <HStack gap={2}>
                            <Text fontSize="sm">Не чаще одного письма на адрес за</Text>
                            <Input
                                type="number"
                                value={form.throttle_minutes ?? ''}
                                onChange={(e) => patch({ throttle_minutes: e.target.value ? Number(e.target.value) : null })}
                                w="110px"
                                size="sm"
                            />
                            <Text fontSize="sm">минут</Text>
                        </HStack>
                    )}
                    <Checkbox
                        checked={form.is_active}
                        onCheckedChange={(e) => patch({ is_active: !!e.checked })}
                    >
                        Правило включено
                    </Checkbox>
                </VStack>

                <Box borderTopWidth="1px" pt={3}>
                    {preview === null && <Text fontSize="sm" color="fg.muted">Считаем, что попадёт под фильтр…</Text>}
                    {preview !== null && (
                        <VStack align="stretch" gap={2}>
                            <Text fontSize="sm" fontWeight="600">
                                Под фильтр попадает писем: {preview.total}
                                <Text as="span" fontSize="xs" color="fg.muted" ml={2}>
                                    (просмотрено последних {preview.scanned})
                                </Text>
                            </Text>

                            {preview.total === 0 && (
                                <Alert status="warning" title="Пока ни одного письма">
                                    Возможно, условие набрано с опечаткой — или таких писем ещё не было.
                                </Alert>
                            )}

                            {preview.letters.map((letter) => (
                                <Box key={letter.id} borderWidth="1px" borderRadius="md" p={2}>
                                    <HStack justifyContent="space-between" flexWrap="wrap" gap={2}>
                                        <VStack align="start" gap={0}>
                                            <Text fontSize="sm" fontWeight="600">{letter.subject}</Text>
                                            <Text fontSize="xs" color="fg.muted">
                                                {[letter.client, letter.created_at_label].filter(Boolean).join(' · ')}
                                            </Text>
                                        </VStack>
                                        <HStack gap={2}>
                                            <Badge variant="subtle">{letter.folder}</Badge>
                                            <Badge variant="outline">{letter.origin_label}</Badge>
                                        </HStack>
                                    </HStack>
                                    {letter.tags?.length > 0 && (
                                        <Wrap gap={1} mt={2}>
                                            {letter.tags.slice(0, 8).map((tag) => (
                                                <Badge key={tag} size="sm" variant="outline" colorPalette="gray">{tag}</Badge>
                                            ))}
                                        </Wrap>
                                    )}
                                </Box>
                            ))}
                        </VStack>
                    )}
                </Box>

                <HStack gap={2}>
                    <Button size="sm" onClick={save} loading={saving}>Сохранить правило</Button>
                    <Button size="sm" variant="ghost" onClick={onCancel}>Отмена</Button>
                </HStack>
            </VStack>
        </Box>
    );
}
