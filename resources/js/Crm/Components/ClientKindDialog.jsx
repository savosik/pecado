import { useEffect, useMemo, useState } from 'react';
import { router } from '@inertiajs/react';
import { Dialog, HStack, Portal, Text, Textarea, VStack, Wrap } from '@chakra-ui/react';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Radio, RadioGroup } from '@/components/ui/radio';
import { toastError } from '@/utils/toast';

const KINDS = [
    {
        value: 'staff',
        label: 'Сотрудник',
        hint: 'Живой человек: закупщик, наш менеджер, сотрудник партнёра. Остаётся в админке и может входить в кабинет.',
    },
    {
        value: 'service',
        label: 'Служебный',
        hint: 'Техническая учётка: интеграция, тестовый аккаунт, дубль из 1С. Прячется везде, кроме списка пользователей.',
    },
    {
        value: 'deleted',
        label: 'Удалить',
        hint: 'Мягкое удаление: аккаунт скрыт из всех списков, вход и API-токены закрыты. Заказы и документы остаются в истории. Вернуть можно в админке по фильтру «Удалён».',
    },
];

/**
 * Типовые причины «это не партнёр», по типам, к которым они подходят.
 *
 * Это подсказки для текста, а не справочник: в журнал уходит строка
 * «причина: уточнение», и через полгода её читают глазами, а не кодом.
 */
const REASONS = [
    { label: 'Наш сотрудник', kinds: ['staff'] },
    { label: 'Закупщик или сотрудник партнёра — покупает юрлицо', kinds: ['staff'] },
    { label: 'Подрядчик: фотограф, дизайнер, маркетолог', kinds: ['staff'] },
    { label: 'Интеграция или API-учётка', kinds: ['service'] },
    { label: 'Тестовый аккаунт', kinds: ['service'] },
    { label: 'Собственное юрлицо компании', kinds: ['service'] },
    { label: 'Дубль — тот же партнёр под другим кодом 1С', kinds: ['service', 'deleted'] },
    { label: 'Конкурент', kinds: ['service', 'deleted'] },
    { label: 'Спам или бот-регистрация', kinds: ['deleted'] },
    { label: 'Ошибочная регистрация', kinds: ['deleted'] },
    { label: 'Розница или физлицо — оптом не покупает', kinds: ['deleted'] },
    { label: 'Попросил удалить свои данные', kinds: ['deleted'] },
];

const REASON_MAX = 255;

const PLACEHOLDERS = {
    staff: 'Например: закупщик Гевеи, сам не покупает',
    service: 'Например: выгрузка остатков для Гевеи',
    deleted: 'Например: регистрации с одного IP за ночь',
};

/**
 * «Это не партнёр» — убрать аккаунт из базы партнёров отдела.
 *
 * Сотрудник и служебный не удаляются и не блокируются: аккаунт перестаёт быть
 * партнёром для CRM, а заказы, документы и вход в кабинет остаются как были.
 * «Удалить» — мягкое удаление: строка остаётся ради истории, но аккаунт
 * скрыт везде и войти не может. 1С этой пометки не касается, поэтому её
 * не перезапишет очередной partner.updated.
 */
export default function ClientKindDialog({ client, open, onClose }) {
    const [kind, setKind] = useState('staff');
    const [preset, setPreset] = useState(null);
    const [comment, setComment] = useState('');
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (open) {
            setKind('staff');
            setPreset(null);
            setComment('');
        }
    }, [open]);

    const presets = useMemo(() => REASONS.filter((item) => item.kinds.includes(kind)), [kind]);

    // Итоговая строка журнала: «причина: уточнение», либо только одно из двух.
    const reason = useMemo(() => {
        const note = comment.trim();
        if (preset && note) return `${preset}: ${note}`;

        return preset || note || null;
    }, [preset, comment]);

    const commentLimit = REASON_MAX - (preset ? preset.length + 2 : 0);

    if (!client) return null;

    const changeKind = (value) => {
        setKind(value);
        // Причина другого типа к новому не подходит — снимаем, уточнение оставляем.
        if (preset && !REASONS.find((item) => item.label === preset)?.kinds.includes(value)) {
            setPreset(null);
        }
    };

    const submit = () => {
        setBusy(true);

        router.put(route('crm.clients.kind.update', client.id), {
            user_kind: kind,
            reason,
        }, {
            preserveScroll: true,
            onError: (errors) => toastError(
                'Не удалось изменить тип аккаунта',
                Object.values(errors)[0] || 'Попробуйте ещё раз.',
            ),
            onFinish: () => {
                setBusy(false);
                onClose();
            },
        });
    };

    const isDelete = kind === 'deleted';

    return (
        <Dialog.Root open={open} onOpenChange={(e) => { if (!e.open) onClose(); }} size="md">
            <Portal>
                <Dialog.Backdrop />
                <Dialog.Positioner>
                    <Dialog.Content>
                        <Dialog.Header>
                            <Dialog.Title>Убрать из базы партнёров</Dialog.Title>
                        </Dialog.Header>

                        <Dialog.Body>
                            <VStack align="stretch" gap={4}>
                                <Text fontSize="sm" color="fg.muted">
                                    «{client.name}» пропадёт из списка партнёров, планов, задач и отчётов
                                    продаж. {isDelete
                                        ? 'Аккаунт будет скрыт везде, включая список пользователей, а вход в кабинет закрыт. Заказы и документы останутся в истории.'
                                        : 'Заказы, документы и вход в кабинет останутся как есть — аккаунт не удаляется и не блокируется.'}
                                </Text>

                                <Field label="Кто это на самом деле">
                                    <RadioGroup value={kind} onValueChange={(e) => changeKind(e.value)}>
                                        <VStack align="stretch" gap={3}>
                                            {KINDS.map((option) => (
                                                <VStack key={option.value} align="start" gap={0}>
                                                    <Radio value={option.value}>{option.label}</Radio>
                                                    <Text fontSize="xs" color="fg.muted" pl={6}>
                                                        {option.hint}
                                                    </Text>
                                                </VStack>
                                            ))}
                                        </VStack>
                                    </RadioGroup>
                                </Field>

                                <Field
                                    label="Причина"
                                    helperText="Необязательно, но через полгода вопрос «почему его нет в базе» задают обязательно."
                                >
                                    <Wrap gap={2} mb={2}>
                                        {presets.map((item) => {
                                            const active = preset === item.label;

                                            return (
                                                <Button
                                                    key={item.label}
                                                    size="xs"
                                                    variant={active ? 'solid' : 'outline'}
                                                    colorPalette={active ? 'blue' : 'gray'}
                                                    onClick={() => setPreset(active ? null : item.label)}
                                                >
                                                    {item.label}
                                                </Button>
                                            );
                                        })}
                                    </Wrap>
                                    <Textarea
                                        rows={2}
                                        value={comment}
                                        maxLength={commentLimit}
                                        placeholder={preset ? 'Уточнение (необязательно)' : PLACEHOLDERS[kind]}
                                        onChange={(e) => setComment(e.target.value)}
                                    />
                                </Field>
                            </VStack>
                        </Dialog.Body>

                        <Dialog.Footer>
                            <HStack gap={2}>
                                <Button variant="outline" onClick={onClose} disabled={busy}>Отмена</Button>
                                <Button colorPalette="red" onClick={submit} loading={busy}>
                                    {isDelete ? 'Удалить аккаунт' : 'Убрать из базы'}
                                </Button>
                            </HStack>
                        </Dialog.Footer>
                    </Dialog.Content>
                </Dialog.Positioner>
            </Portal>
        </Dialog.Root>
    );
}
