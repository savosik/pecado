import CrmLayout from '@/Crm/Layouts/CrmLayout';
import PaymentList from './PaymentList';

/**
 * Платежи партнёров менеджера — пункт раздела «Финансы».
 */
export default function Payments(props) {
    return <PaymentList {...props} />;
}

Payments.layout = (page) => (
    <CrmLayout breadcrumbs={[{ label: 'Финансы' }, { label: 'Платежи' }]}>{page}</CrmLayout>
);
