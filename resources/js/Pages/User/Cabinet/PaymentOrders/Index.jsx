import { Head, router } from '@inertiajs/react';
import { Text } from '@chakra-ui/react';
import CabinetLayout from '../CabinetLayout';
import PaymentOrderForm from '@/shared/PaymentOrderForm';

const toQuery = (params) => new URLSearchParams(
    Object.entries(params).filter(([, v]) => v !== null && v !== undefined && v !== ''),
).toString();

/**
 * Платёжное поручение «бери и плати» (pay-01): готовые реквизиты, назначение,
 * QR и файл для клиент-банка — по любому непогашенному документу.
 */
export default function PaymentOrdersIndex({ options }) {
    const onSend = (payload) => new Promise((resolve, reject) => {
        router.post('/cabinet/payment-orders/send', payload, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => resolve(),
            onError: (errors) => reject(new Error(Object.values(errors || {})[0] || 'Не удалось отправить')),
        });
    });

    return (
        <CabinetLayout title="Платёжное поручение">
            <Head title="Платёжное поручение — Pecado" />
            <Text fontSize="sm" color="fg.muted" mb="4">
                Выберите, что оплатить, — мы соберём платёжку с реквизитами и назначением. Скачайте PDF с QR-кодом,
                загрузите файл в клиент-банк или отправьте бухгалтеру прямо отсюда.
            </Text>
            <PaymentOrderForm
                options={options}
                previewUrl={(params) => `/cabinet/payment-orders/preview?${toQuery(params)}`}
                downloadUrl={(params, format) => `/cabinet/payment-orders/download?${toQuery({ ...params, format })}`}
                onSend={onSend}
            />
        </CabinetLayout>
    );
}
