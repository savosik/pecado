import { PanelLayout } from '@/shared/Panel/PanelLayout';
import BugReportWidget from '@/Components/BugReportWidget';
import { menuConfig } from '../config/menuConfig';

const panel = {
    key: 'admin',
    basePath: '/admin',
    menuConfig,
    homeLabel: 'Главная',
    logoAlt: 'Pecado Админка',
    badge: 'Админка',
    logoHeight: '8',
    // Страницы создания/редактирования есть только в админке,
    // поэтому крошка «Создание»/«Редактирование» нужна лишь здесь.
    actionBreadcrumbs: true,
    profileHref: '/admin/profile',
};

export const AdminLayout = ({ children, breadcrumbs = [] }) => (
    <PanelLayout panel={panel} breadcrumbs={breadcrumbs} extras={<BugReportWidget />}>
        {children}
    </PanelLayout>
);

export default AdminLayout;
