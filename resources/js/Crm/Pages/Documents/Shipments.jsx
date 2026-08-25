import CrmLayout from '@/Crm/Layouts/CrmLayout';
import DocumentList from './DocumentList';

/**
 * Реализации по партнёрам менеджера — пункт раздела «Финансы».
 *
 * Файл остаётся среди документов: вёрстку и фильтры журнал делит с заказами
 * (DocumentList), и переносить половину пары в другую папку значило бы развести
 * два одинаковых экрана по разным углам. В меню пункт живёт в «Финансах».
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

Shipments.layout = (page) => (
    <CrmLayout breadcrumbs={[{ label: 'Финансы' }, { label: 'Реализации' }]}>{page}</CrmLayout>
);
