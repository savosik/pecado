import { useEffect, useState } from 'react';
import { Dialog, Portal, Text, VStack } from '@chakra-ui/react';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { NativeSelectField, NativeSelectRoot } from '@/components/ui/native-select';

/**
 * Подтверждение массового действия.
 *
 * Один диалог на три действия, потому что вопрос у них один и тот же —
 * «к чему применить», — а различается только чем заполняется поле.
 * Чужие лиды сервер пропустит молча, поэтому число в заголовке — это
 * «выбрано», а не «будет изменено».
 */
export default function BulkActionDialog({
    action,
    count = 0,
    stages = [],
    managers = [],
    onApply,
    onClose,
}) {
    const [value, setValue] = useState('');

    useEffect(() => { setValue(''); }, [action]);

    if (! action) return null;

    const isAssign = action === 'assign';
    const isMove = action === 'move';
    const isDelete = action === 'delete';

    const title = isAssign ? 'Сменить менеджера' : (isMove ? 'Перенести по воронке' : 'Удалить лидов');
    const ready = isDelete || (isMove ? value !== '' : true);

    const apply = () => onApply({
        action,
        manager_id: isAssign ? (value === '' ? null : Number(value)) : undefined,
        stage_id: isMove ? Number(value) : undefined,
    });

    return (
        <Dialog.Root open onOpenChange={({ open }) => ! open && onClose()} size="sm">
            <Portal>
                <Dialog.Backdrop />
                <Dialog.Positioner>
                    <Dialog.Content>
                        <Dialog.Header>
                            <Dialog.Title>{title}</Dialog.Title>
                        </Dialog.Header>

                        <Dialog.Body>
                            <VStack align="stretch" gap={3}>
                                <Text fontSize="sm" color="fg.muted">
                                    Выбрано лидов: {count}. Чужие будут пропущены.
                                </Text>

                                {isAssign && (
                                    <Field label="Кому передать">
                                        <NativeSelectRoot>
                                            <NativeSelectField
                                                value={value}
                                                onChange={(event) => setValue(event.target.value)}
                                            >
                                                <option value="">Снять менеджера — сделать ничьими</option>
                                                {managers.map((manager) => (
                                                    <option key={manager.id} value={manager.id}>{manager.name}</option>
                                                ))}
                                            </NativeSelectField>
                                        </NativeSelectRoot>
                                    </Field>
                                )}

                                {isMove && (
                                    <Field label="На какую стадию">
                                        <NativeSelectRoot>
                                            <NativeSelectField
                                                value={value}
                                                onChange={(event) => setValue(event.target.value)}
                                            >
                                                <option value="">Выберите стадию</option>
                                                {stages.map((stage) => (
                                                    <option key={stage.id} value={stage.id}>{stage.name}</option>
                                                ))}
                                            </NativeSelectField>
                                        </NativeSelectRoot>
                                    </Field>
                                )}

                                {isDelete && (
                                    <Text fontSize="sm">
                                        Лиды исчезнут с доски вместе с перепиской. Вернуть их сможет
                                        только администратор базы.
                                    </Text>
                                )}
                            </VStack>
                        </Dialog.Body>

                        <Dialog.Footer>
                            <Button variant="ghost" onClick={onClose}>Отмена</Button>
                            <Button
                                colorPalette={isDelete ? 'red' : 'blue'}
                                disabled={! ready}
                                onClick={apply}
                            >
                                {isDelete ? 'Удалить' : 'Применить'}
                            </Button>
                        </Dialog.Footer>
                    </Dialog.Content>
                </Dialog.Positioner>
            </Portal>
        </Dialog.Root>
    );
}
