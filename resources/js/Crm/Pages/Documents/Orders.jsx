import CrmLayout from '@/Crm/Layouts/CrmLayout';
import DocumentList from './DocumentList';

/**
 * Заказы клиентов менеджера.
 *
 * Вся вёрстка в DocumentList — здесь только то, чем заказы отличаются
 * от реализаций: заголовок и маршрут.
 */
export default function Orders({ orders, ...props }) {
    return (
        <DocumentList
            routeName="crm.orders"
            title="Заказы"
            description="Заказы ваших клиентов. Данные приходят из 1С и здесь только читаются"
            pagination={orders}
            {...props}
        />
    );
}

Orders.layout = (page) => <CrmLayout>{page}</CrmLayout>;
