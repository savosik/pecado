import { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import { Dialog, HStack, Input, NativeSelect, Portal, Text, Textarea, VStack } from '@chakra-ui/react';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { toastError, toastSuccess } from '@/utils/toast';

const iso = (date) => [
    date.getFullYear(),
    String(date.getMonth() + 1).padStart(2, '0'),
    String(date.getDate()).padStart(2, '0'),
].join('-');

/**
 * Разблокировка до даты — единственная ручка лестницы долга.
 *
 * «Клиент обещал оплатить до …»: гейт снят, ступень остаётся видна.
 * Потолок срока приходит с сервера по роли; бессрочной нет.
 *
 * @param {boolean} open
 * @param {{id: number, name: string, contractors?: Array<{company_id: number, company_name: string}>}|null} client
 * @param {number} maxDays
 * @param {Function} onClose
 * @param {Function} [onSaved]
 */
export default function DebtPauseDialog({ open, client, maxDays = 14, onClose, onSaved }) {
    const [companyId, setCompanyId] = useState('');
    const [until, setUntil] = useState('');
    const [reason, setReason] = useState('');
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState(false);

    const today = new Date();
    const limit = new Date(today);
    limit.setDate(limit.getDate() + maxDays);

    useEffect(() => {
        if (!open) return;
        const week = new Date(today);
        week.setDate(week.getDate() + Math.min(7, maxDays));
        setCompanyId('');
        setUntil(iso(week));
        setReason('');
        setErrors({});
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, client?.id]);

    if (!client) return null;

    const submit = () => {
        setBusy(true);
        router.post(route('crm.debt.pauses.store'), {
            user_id: client.id,
            company_id: companyId === '' ? null : Number(companyId),
            until,
            reason,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                toastSuccess('Разблокировка поставлена', `Ограничения сняты до ${until.split('-').reverse().join('.')}.`);
                onSaved?.();
                onClose();
            },
            onError: (serverErrors) => {
                setErrors(serverErrors || {});
                toastError('Не удалось поставить разблокировку', Object.values(serverErrors || {})[0] || 'Проверьте поля формы');
            },
            onFinish: () => setBusy(false),
        });
    };

    const contractors = client.contractors || [];

    return (
        <Dialog.Root open={open} onOpenChange={(e) => { if (!e.open) onClose(); }} size="md">
            <Portal>
                <Dialog.Backdrop />
                <Dialog.Positioner>
                    <Dialog.Content>
                        <Dialog.Header>
                            <Dialog.Title>Разблокировать до даты</Dialog.Title>
                        </Dialog.Header>
                        <Dialog.Body>
                            <VStack align="stretch" gap={4}>
                                <Text fontSize="sm" color="fg.muted">
                                    {client.name}: ограничения по ступени снимаются до указанной даты, ступень остаётся
                                    видна. Истечёт без оплаты — ограничения вернутся сами, вам придёт задача.
                                    Не больше {maxDays} дней от сегодня.
                                </Text>

                                {contractors.length > 0 && (
                                    <Field label="Кого разблокировать">
                                        <NativeSelect.Root size="sm">
                                            <NativeSelect.Field value={companyId} onChange={(e) => setCompanyId(e.target.value)}>
                                                <option value="">Партнёра целиком (все юрлица)</option>
                                                {contractors.map((row) => (
                                                    <option key={row.company_id} value={String(row.company_id)}>
                                                        Только {row.company_name}
                                                    </option>
                                                ))}
                                            </NativeSelect.Field>
                                            <NativeSelect.Indicator />
                                        </NativeSelect.Root>
                                    </Field>
                                )}

                                <Field label="До какой даты" required invalid={!!errors.until} errorText={errors.until} maxW="200px">
                                    <Input
                                        type="date"
                                        value={until}
                                        min={iso(today)}
                                        max={iso(limit)}
                                        onChange={(e) => setUntil(e.target.value)}
                                    />
                                </Field>

                                <Field
                                    label="Причина"
                                    required
                                    invalid={!!errors.reason}
                                    errorText={errors.reason}
                                    helperText="Что обещал клиент: сумма и дата. Через месяц по этой строке восстанавливают договорённость."
                                >
                                    <Textarea
                                        rows={2}
                                        value={reason}
                                        maxLength={500}
                                        placeholder="Например: обещал оплатить всю сумму до 15 сентября, письмо в переписке"
                                        onChange={(e) => setReason(e.target.value)}
                                    />
                                </Field>
                            </VStack>
                        </Dialog.Body>
                        <Dialog.Footer>
                            <HStack gap={2}>
                                <Button variant="outline" onClick={onClose} disabled={busy}>Отмена</Button>
                                <Button colorPalette="green" onClick={submit} loading={busy} disabled={!until || reason.trim().length < 5}>
                                    Разблокировать
                                </Button>
                            </HStack>
                        </Dialog.Footer>
                    </Dialog.Content>
                </Dialog.Positioner>
            </Portal>
        </Dialog.Root>
    );
}
