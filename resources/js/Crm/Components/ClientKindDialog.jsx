import { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import { Dialog, HStack, Portal, Text, Textarea, VStack } from '@chakra-ui/react';
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
];

/**
 * «Это не клиент» — убрать аккаунт из клиентской базы отдела.
 *
 * Аккаунт не удаляется и не блокируется: он перестаёт быть клиентом для CRM,
 * а заказы, документы и вход в кабинет остаются как были. 1С этой пометки
 * не касается, поэтому её не перезапишет очередной partner.updated.
 */
export default function ClientKindDialog({ client, open, onClose }) {
    const [kind, setKind] = useState('staff');
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (open) {
            setKind('staff');
            setReason('');
        }
    }, [open]);

    if (!client) return null;

    const submit = () => {
        setBusy(true);

        router.put(route('crm.clients.kind.update', client.id), {
            user_kind: kind,
            reason: reason.trim() || null,
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

    return (
        <Dialog.Root open={open} onOpenChange={(e) => { if (!e.open) onClose(); }} size="md">
            <Portal>
                <Dialog.Backdrop />
                <Dialog.Positioner>
                    <Dialog.Content>
                        <Dialog.Header>
                            <Dialog.Title>Убрать из клиентской базы</Dialog.Title>
                        </Dialog.Header>

                        <Dialog.Body>
                            <VStack align="stretch" gap={4}>
                                <Text fontSize="sm" color="fg.muted">
                                    «{client.name}» пропадёт из списка клиентов, планов, задач и отчётов
                                    продаж. Заказы, документы и вход в кабинет останутся как есть —
                                    аккаунт не удаляется и не блокируется.
                                </Text>

                                <Field label="Кто это на самом деле">
                                    <RadioGroup value={kind} onValueChange={(e) => setKind(e.value)}>
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
                                    <Textarea
                                        rows={2}
                                        value={reason}
                                        maxLength={255}
                                        placeholder="Например: закупщик Гевеи, сам не покупает"
                                        onChange={(e) => setReason(e.target.value)}
                                    />
                                </Field>
                            </VStack>
                        </Dialog.Body>

                        <Dialog.Footer>
                            <HStack gap={2}>
                                <Button variant="outline" onClick={onClose} disabled={busy}>Отмена</Button>
                                <Button colorPalette="red" onClick={submit} loading={busy}>
                                    Убрать из базы
                                </Button>
                            </HStack>
                        </Dialog.Footer>
                    </Dialog.Content>
                </Dialog.Positioner>
            </Portal>
        </Dialog.Root>
    );
}
