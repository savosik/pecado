import CrmLayout from '@/Crm/Layouts/CrmLayout';
import PaymentList from './PaymentList';

/**
 * Платежи клиентов менеджера.
 */
export default function Payments(props) {
    return <PaymentList {...props} />;
}

Payments.layout = (page) => <CrmLayout>{page}</CrmLayout>;
