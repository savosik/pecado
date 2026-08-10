import CrmLayout from '@/Crm/Layouts/CrmLayout';
import DocumentList from './DocumentList';

/**
 * Реализации по партнёрам менеджера.
 */
export default function Shipments({ shipments, ...props }) {
    return (
        <DocumentList
            routeName="crm.shipments"
            title="Реализации"
            description="Отгрузки вашим партнёрам. Данные приходят из 1С и здесь только читаются"
            pagination={shipments}
            {...props}
        />
    );
}

Shipments.layout = (page) => <CrmLayout>{page}</CrmLayout>;
