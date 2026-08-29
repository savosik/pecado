import axios from 'axios';
import SharedPaymentOrderDialog from '@/shared/PaymentOrderDialog';
import { toastSuccess } from '@/utils/toast';

const toQuery = (params) => new URLSearchParams(
    Object.entries(params).filter(([, v]) => v !== null && v !== undefined && v !== ''),
).toString();

/**
 * Платёжка из карточки партнёра (pay-01): менеджер собирает и шлёт бухгалтеру сам.
 *
 * Тонкая обёртка над общим диалогом — здесь только маршруты CRM.
 *
 * @param {boolean} open
 * @param {{id: number, name: string}|null} client
 * @param {Function} onClose
 */
export default function PaymentOrderDialog({ open, client, onClose }) {
    if (!client) return null;

    const onSend = async (payload) => {
        const { data } = await axios.post(route('crm.clients.payment-orders.send', client.id), payload);
        toastSuccess('Платёжка отправлена', data?.message || '');
    };

    return (
        <SharedPaymentOrderDialog
            open={open}
            onClose={onClose}
            title={`Платёжное поручение · ${client.name}`}
            loadOptions={() => axios.get(route('crm.clients.payment-orders.options', client.id)).then(({ data }) => data)}
            previewUrl={(params) => `${route('crm.clients.payment-orders.preview', client.id)}?${toQuery(params)}`}
            downloadUrl={(params, format) => `${route('crm.clients.payment-orders.download', client.id)}?${toQuery({ ...params, format })}`}
            onSend={onSend}
        />
    );
}
