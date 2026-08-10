import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Dialog, HStack, Input, Portal, SimpleGrid, Text, Textarea, VStack } from '@chakra-ui/react';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { NativeSelectField, NativeSelectRoot } from '@/components/ui/native-select';
import { formatMoney, formatWeight } from './deliveryFormat';

/**
 * Отметка «эти реализации уже отправлены» — без обращения к ApiShip.
 *
 * Нужна с первого дня работы раздела: систему внедряют задним числом, часть груза
 * склад уже отправил, а часть перевозчиков к агрегатору вообще не подключена.
 * Такая отправка попадает в журнал наравне с остальными и так же занимает
 * реализации — просто заявки в ApiShip за ней нет.
 */
export function MarkShippedDialog({ open, onClose, selected, statuses }) {
    const today = new Date().toISOString().slice(0, 10);

    const [form, setForm] = useState({
        carrier_name: '',
        provider_number: '',
        tracking_url: '',
        delivery_cost: '',
        shipped_at: today,
        status: 'submitted',
        comment: '',
    });
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    const set = (patch) => setForm({ ...form, ...patch });

    const totals = {
        weight: selected.reduce((sum, item) => sum + item.weight, 0),
        amount: selected.reduce((sum, item) => sum + item.amount, 0),
    };

    const submit = () => {
        setSaving(true);
        setErrors({});

        router.post('/wms/delivery-candidates/mark-shipped', {
            ...form,
            delivery_cost: form.delivery_cost === '' ? null : form.delivery_cost,
            tracking_url: form.tracking_url === '' ? null : form.tracking_url,
            shipment_ids: selected.map((item) => item.id),
        }, {
            preserveScroll: true,
            onError: setErrors,
            onSuccess: () => onClose(true),
            onFinish: () => setSaving(false),
        });
    };

    return (
        <Dialog.Root open={open} onOpenChange={({ open: isOpen }) => !isOpen && onClose(false)} size="lg">
            <Portal>
                <Dialog.Backdrop />
                <Dialog.Positioner>
                    <Dialog.Content>
                        <Dialog.Header>
                            <Dialog.Title>Отметить как отправленные</Dialog.Title>
                        </Dialog.Header>

                        <Dialog.Body>
                            <VStack gap={4} align="stretch">
                                <Text fontSize="sm" color="fg.muted">
                                    Груз уехал мимо ApiShip — заявку делали на сайте перевозчика или
                                    по телефону. Реализации уйдут из списка и попадут в журнал отправок.
                                </Text>

                                <HStack gap={5} flexWrap="wrap" fontSize="sm" bg="bg.subtle" p={3} borderRadius="md">
                                    <Text><Text as="span" color="fg.muted">Реализаций: </Text>{selected.length}</Text>
                                    <Text><Text as="span" color="fg.muted">Вес: </Text>{formatWeight(totals.weight)}</Text>
                                    <Text><Text as="span" color="fg.muted">Сумма: </Text>{formatMoney(totals.amount)}</Text>
                                    {selected[0]?.client && (
                                        <Text color="fg.muted" lineClamp={1}>{selected[0].client}</Text>
                                    )}
                                </HStack>

                                <SimpleGrid columns={{ base: 1, md: 2 }} gap={3}>
                                    <Field
                                        label="Транспортная компания"
                                        required
                                        errorText={errors.carrier_name}
                                        invalid={!!errors.carrier_name}
                                    >
                                        <Input
                                            size="sm"
                                            value={form.carrier_name}
                                            onChange={(event) => set({ carrier_name: event.target.value })}
                                            placeholder="СДЭК, ПЭК, Деловые Линии..."
                                        />
                                    </Field>

                                    <Field
                                        label="Трек-номер"
                                        errorText={errors.provider_number}
                                        invalid={!!errors.provider_number}
                                    >
                                        <Input
                                            size="sm"
                                            value={form.provider_number}
                                            onChange={(event) => set({ provider_number: event.target.value })}
                                        />
                                    </Field>

                                    <Field
                                        label="Дата отправки"
                                        required
                                        errorText={errors.shipped_at}
                                        invalid={!!errors.shipped_at}
                                    >
                                        <Input
                                            size="sm"
                                            type="date"
                                            max={today}
                                            value={form.shipped_at}
                                            onChange={(event) => set({ shipped_at: event.target.value })}
                                        />
                                    </Field>

                                    <Field label="Состояние" required errorText={errors.status} invalid={!!errors.status}>
                                        <NativeSelectRoot size="sm">
                                            <NativeSelectField
                                                value={form.status}
                                                onChange={(event) => set({ status: event.target.value })}
                                            >
                                                {statuses.map((item) => (
                                                    <option key={item.value} value={item.value}>{item.label}</option>
                                                ))}
                                            </NativeSelectField>
                                        </NativeSelectRoot>
                                    </Field>

                                    <Field
                                        label="Ссылка отслеживания"
                                        helperText="Если перевозчик её даёт"
                                        errorText={errors.tracking_url}
                                        invalid={!!errors.tracking_url}
                                    >
                                        <Input
                                            size="sm"
                                            value={form.tracking_url}
                                            onChange={(event) => set({ tracking_url: event.target.value })}
                                            placeholder="https://..."
                                        />
                                    </Field>

                                    <Field
                                        label="Стоимость доставки, ₽"
                                        errorText={errors.delivery_cost}
                                        invalid={!!errors.delivery_cost}
                                    >
                                        <Input
                                            size="sm"
                                            type="number"
                                            value={form.delivery_cost}
                                            onChange={(event) => set({ delivery_cost: event.target.value })}
                                        />
                                    </Field>
                                </SimpleGrid>

                                <Field label="Комментарий" errorText={errors.comment} invalid={!!errors.comment}>
                                    <Textarea
                                        size="sm"
                                        rows={2}
                                        value={form.comment}
                                        onChange={(event) => set({ comment: event.target.value })}
                                        placeholder="Например: увёз водитель клиента, накладная у бухгалтерии"
                                    />
                                </Field>

                                {errors.shipment_ids && (
                                    <Text fontSize="sm" color="red.500">{errors.shipment_ids}</Text>
                                )}
                            </VStack>
                        </Dialog.Body>

                        <Dialog.Footer>
                            <Button variant="outline" onClick={() => onClose(false)}>Отмена</Button>
                            <Button onClick={submit} loading={saving} disabled={!form.carrier_name}>
                                Отметить отправленными
                            </Button>
                        </Dialog.Footer>
                    </Dialog.Content>
                </Dialog.Positioner>
            </Portal>
        </Dialog.Root>
    );
}
