import { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import { Dialog, HStack, Portal, Text, Textarea, VStack } from '@chakra-ui/react';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { NativeSelectField, NativeSelectRoot } from '@/components/ui/native-select';
import { toastError } from '@/utils/toast';

/**
 * «Персональный менеджер» — закрепить партнёра за менеджером или снять закрепление.
 *
 * С v16.10.0 менеджера партнёра 1С не присылает: распределяет базу РОП, и
 * этот диалог — единственное место, где закрепление меняется из CRM. Пустой
 * выбор — «не закреплён»: партнёр остаётся в базе отдела как лид и виден
 * тем, у кого включена галочка «Нераспределённые».
 */
export default function ClientManagerDialog({ client, managers = [], open, onClose }) {
    const current = client?.manager?.id ? String(client.manager.id) : '';
    const [managerId, setManagerId] = useState(current);
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (open) {
            setManagerId(current);
            setReason('');
        }
    }, [open, current]);

    if (!client) return null;

    const unchanged = managerId === current;
    const chosen = managers.find((manager) => String(manager.id) === managerId);

    const submit = () => {
        setBusy(true);

        router.put(route('crm.clients.manager.update', client.id), {
            personal_manager_id: managerId ? Number(managerId) : null,
            reason: reason.trim() || null,
        }, {
            preserveScroll: true,
            onError: (errors) => toastError(
                'Не удалось изменить менеджера',
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
                            <Dialog.Title>Персональный менеджер</Dialog.Title>
                        </Dialog.Header>

                        <Dialog.Body>
                            <VStack align="stretch" gap={4}>
                                <Text fontSize="sm" color="fg.muted">
                                    Партнёр «{client.name}» появится в списке выбранного менеджера,
                                    в его планах и задачах. Без менеджера партнёр остаётся в базе
                                    отдела как лид — его видно только с галочкой «Нераспределённые».
                                </Text>

                                <Field label="За кем закрепить">
                                    <NativeSelectRoot size="sm">
                                        <NativeSelectField
                                            value={managerId}
                                            onChange={(event) => setManagerId(event.target.value)}
                                        >
                                            <option value="">Не закреплён (лид)</option>
                                            {managers.map((manager) => (
                                                <option key={manager.id} value={String(manager.id)}>
                                                    {manager.name}
                                                </option>
                                            ))}
                                        </NativeSelectField>
                                    </NativeSelectRoot>
                                </Field>

                                <Field
                                    label="Причина"
                                    helperText="Необязательно. Запись попадёт в историю статусов партнёра."
                                >
                                    <Textarea
                                        rows={2}
                                        value={reason}
                                        maxLength={255}
                                        placeholder="Например: передан при увольнении, новый регион"
                                        onChange={(e) => setReason(e.target.value)}
                                    />
                                </Field>
                            </VStack>
                        </Dialog.Body>

                        <Dialog.Footer>
                            <HStack gap={2}>
                                <Button variant="outline" onClick={onClose} disabled={busy}>Отмена</Button>
                                <Button
                                    colorPalette={chosen ? 'blue' : 'orange'}
                                    onClick={submit}
                                    loading={busy}
                                    disabled={unchanged}
                                >
                                    {chosen ? `Закрепить за ${chosen.name}` : 'Оставить без менеджера'}
                                </Button>
                            </HStack>
                        </Dialog.Footer>
                    </Dialog.Content>
                </Dialog.Positioner>
            </Portal>
        </Dialog.Root>
    );
}
