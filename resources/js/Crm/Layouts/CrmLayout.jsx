import { PanelLayout } from '@/shared/Panel/PanelLayout';
import { menuConfig } from '../config/menuConfig';

const panel = {
    key: 'crm',
    basePath: '/crm',
    menuConfig,
    homeLabel: 'Рабочий стол',
    logoAlt: 'Pecado CRM',
    badge: 'CRM',
    logoHeight: '8',
};

export const CrmLayout = ({ children, breadcrumbs = [] }) => (
    <PanelLayout panel={panel} breadcrumbs={breadcrumbs}>
        {children}
    </PanelLayout>
);

export default CrmLayout;
