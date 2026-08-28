import { useEffect, useState } from 'react';
import axios from 'axios';
import { Dialog, Portal, Spinner, Text } from '@chakra-ui/react';
import { Button } from '@/components/ui/button';
import PaymentOrderForm from '@/shared/PaymentOrderForm';
import { toastSuccess } from '@/utils/toast';

const toQuery = (params) => new URLSearchParams(
    Object.entries(params).filter(([, v]) => v !== null && v !== undefined && v !== ''),
).toString();

/**
 * Платёжка из карточки партнёра (pay-01): менеджер собирает и шлёт бухгалтеру сам.
 *
 * @param {boolean} open
 * @param {{id: number, name: string}|null} client
 * @param {Function} onClose
 */
export default function PaymentOrderDialog({ open, client, onClose }) {
    const [options, setOptions] = useState(null);
    const [error, setError] = useState(null);

    useEffect(() => {
        if (!open || !client) return undefined;
        let cancelled = false;
        setOptions(null);
        setError(null);
        axios.get(route('crm.clients.payment-orders.options', client.id))
            .then(({ data }) => { if (!cancelled) setOptions(data); })
            .catch(() => { if (!cancelled) setError('Не удалось загрузить данные по расчётам'); });
        return () => { cancelled = true; };
    }, [open, client?.id]);

    if (!client) return null;

    const onSend = async (payload) => {
        const { data } = await axios.post(route('crm.clients.payment-orders.send', client.id), payload);
        toastSuccess('Платёжка отправлена', data?.message || '');
    };

    return (
        <Dialog.Root open={open} onOpenChange={(e) => { if (!e.open) onClose(); }} size="xl" scrollBehavior="inside">
            <Portal>
                <Dialog.Backdrop />
                <Dialog.Positioner>
                    <Dialog.Content>
                        <Dialog.Header>
                            <Dialog.Title>Платёжное поручение · {client.name}</Dialog.Title>
                        </Dialog.Header>
                        <Dialog.Body>
                            {error && <Text color="red.fg">{error}</Text>}
                            {!options && !error && <Spinner size="sm" />}
                            {options && (
                                <PaymentOrderForm
                                    compact
                                    options={options}
                                    previewUrl={(params) => `${route('crm.clients.payment-orders.preview', client.id)}?${toQuery(params)}`}
                                    downloadUrl={(params, format) => `${route('crm.clients.payment-orders.download', client.id)}?${toQuery({ ...params, format })}`}
                                    onSend={onSend}
                                />
                            )}
                        </Dialog.Body>
                        <Dialog.Footer>
                            <Button variant="outline" onClick={onClose}>Закрыть</Button>
                        </Dialog.Footer>
                    </Dialog.Content>
                </Dialog.Positioner>
            </Portal>
        </Dialog.Root>
    );
}
