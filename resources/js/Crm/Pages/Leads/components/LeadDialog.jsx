import { useEffect, useState } from 'react';
import axios from 'axios';
import { Dialog, HStack, Input, Portal, SimpleGrid, Textarea, VStack } from '@chakra-ui/react';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { toastError } from '@/utils/toast';

const EMPTY = {
    name: '',
    company_name: '',
    phone: '',
    email: '',
    messenger: '',
    source: '',
    qualified_amount: '',
    expected_close_at: '',
    decision_maker: '',
    interests: '',
    notes: '',
    lost_reason: '',
};

/**
 * Карточка лида.
 *
 * Заполняется не хуже партнёра — те же смысловые поля, что в профиле клиента.
 * Но обязательное только одно: имя. Контакт нужен любой, и требовать
 * конкретный означало бы не дать завести лида, у которого есть только телефон.
 */
export default function LeadDialog({ open, lead, stages = [], canEdit = true, onClose, onSaved }) {
    const [form, setForm] = useState(EMPTY);
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (! open) return;

        setErrors({});
        setForm(lead
            ? {
                ...EMPTY,
                ...Object.fromEntries(
                    Object.keys(EMPTY).map((key) => [key, lead[key] ?? '']),
                ),
            }
            : EMPTY);
    }, [open, lead]);

    const set = (patch) => setForm((prev) => ({ ...prev, ...patch }));

    const submit = async () => {
        setBusy(true);
        setErrors({});

        const payload = {
            ...form,
            qualified_amount: form.qualified_amount === '' ? null : form.qualified_amount,
            expected_close_at: form.expected_close_at || null,
        };

        try {
            if (lead) {
                await axios.patch(route('crm.leads.update', lead.id), payload);
            } else {
                await axios.post(route('crm.leads.store'), payload);
            }

            onSaved?.();
        } catch (error) {
            if (error.response?.status === 422) {
                setErrors(error.response.data.errors ?? {});
            } else {
                toastError('Не удалось сохранить лида.');
            }
        } finally {
            setBusy(false);
        }
    };

    const err = (key) => errors[key]?.[0];

    return (
        <Dialog.Root
            open={open}
            onOpenChange={({ open: isOpen }) => ! isOpen && onClose()}
            size="lg"
            scrollBehavior="inside"
            closeOnInteractOutside={false}
        >
            <Portal>
                <Dialog.Backdrop />
                <Dialog.Positioner>
                    <Dialog.Content>
                        <Dialog.Header>
                            <Dialog.Title>{lead ? 'Лид' : 'Новый лид'}</Dialog.Title>
                        </Dialog.Header>

                        <Dialog.Body>
                            <VStack align="stretch" gap={4}>
                                <Field label="Имя или организация" required invalid={!! err('name')} errorText={err('name')}>
                                    <Input
                                        value={form.name}
                                        onChange={(event) => set({ name: event.target.value })}
                                        disabled={! canEdit}
                                    />
                                </Field>

                                <SimpleGrid columns={{ base: 1, md: 3 }} gap={3}>
                                    <Field label="Телефон" invalid={!! err('phone')} errorText={err('phone')}>
                                        <Input
                                            value={form.phone}
                                            onChange={(event) => set({ phone: event.target.value })}
                                            disabled={! canEdit}
                                        />
                                    </Field>
                                    <Field label="Email" invalid={!! err('email')} errorText={err('email')}>
                                        <Input
                                            value={form.email}
                                            onChange={(event) => set({ email: event.target.value })}
                                            disabled={! canEdit}
                                        />
                                    </Field>
                                    <Field label="Мессенджер">
                                        <Input
                                            value={form.messenger}
                                            onChange={(event) => set({ messenger: event.target.value })}
                                            disabled={! canEdit}
                                        />
                                    </Field>
                                </SimpleGrid>

                                <SimpleGrid columns={{ base: 1, md: 2 }} gap={3}>
                                    <Field label="Организация">
                                        <Input
                                            value={form.company_name}
                                            onChange={(event) => set({ company_name: event.target.value })}
                                            disabled={! canEdit}
                                        />
                                    </Field>
                                    <Field label="Откуда пришёл">
                                        <Input
                                            value={form.source}
                                            onChange={(event) => set({ source: event.target.value })}
                                            placeholder="Выставка, сайт, рекомендация..."
                                            disabled={! canEdit}
                                        />
                                    </Field>
                                </SimpleGrid>

                                <SimpleGrid columns={{ base: 1, md: 2 }} gap={3}>
                                    <Field label="Оценка сделки, ₽">
                                        <Input
                                            type="number"
                                            min={0}
                                            value={form.qualified_amount}
                                            onChange={(event) => set({ qualified_amount: event.target.value })}
                                            disabled={! canEdit}
                                        />
                                    </Field>
                                    <Field label="Ожидаемое закрытие">
                                        <Input
                                            type="date"
                                            value={form.expected_close_at}
                                            onChange={(event) => set({ expected_close_at: event.target.value })}
                                            disabled={! canEdit}
                                        />
                                    </Field>
                                </SimpleGrid>

                                <Field label="ЛПР — кто принимает решение">
                                    <Input
                                        value={form.decision_maker}
                                        onChange={(event) => set({ decision_maker: event.target.value })}
                                        disabled={! canEdit}
                                    />
                                </Field>

                                <Field label="Что интересует">
                                    <Textarea
                                        rows={2}
                                        value={form.interests}
                                        onChange={(event) => set({ interests: event.target.value })}
                                        disabled={! canEdit}
                                    />
                                </Field>

                                <Field label="Заметки">
                                    <Textarea
                                        rows={4}
                                        value={form.notes}
                                        onChange={(event) => set({ notes: event.target.value })}
                                        disabled={! canEdit}
                                    />
                                </Field>

                                {lead && (
                                    <Field label="Причина проигрыша">
                                        <Input
                                            value={form.lost_reason}
                                            onChange={(event) => set({ lost_reason: event.target.value })}
                                            disabled={! canEdit}
                                        />
                                    </Field>
                                )}
                            </VStack>
                        </Dialog.Body>

                        <Dialog.Footer>
                            <HStack gap={2}>
                                <Button variant="ghost" onClick={onClose} disabled={busy}>Закрыть</Button>
                                {canEdit && (
                                    <Button onClick={submit} loading={busy}>Сохранить</Button>
                                )}
                            </HStack>
                        </Dialog.Footer>
                    </Dialog.Content>
                </Dialog.Positioner>
            </Portal>
        </Dialog.Root>
    );
}
