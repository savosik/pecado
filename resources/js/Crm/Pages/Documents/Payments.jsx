import CrmLayout from '@/Crm/Layouts/CrmLayout';
import PaymentList from './PaymentList';

/**
 * Платежи партнёров менеджера.
 */
export default function Payments(props) {
    return <PaymentList {...props} />;
}

Payments.layout = (page) => <CrmLayout>{page}</CrmLayout>;
