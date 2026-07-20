import { PanelLayout } from '@/shared/Panel/PanelLayout';
import { menuConfig } from '../config/menuConfig';

const panel = {
    key: 'wms',
    basePath: '/wms',
    menuConfig,
    homeLabel: 'Рабочий стол',
    logoAlt: 'Pecado Склад',
    badge: 'Склад',
    logoHeight: '8',
};

export const WmsLayout = ({ children, breadcrumbs = [] }) => (
    <PanelLayout panel={panel} breadcrumbs={breadcrumbs}>
        {children}
    </PanelLayout>
);

export default WmsLayout;
