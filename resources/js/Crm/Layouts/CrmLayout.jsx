import { PanelLayout } from '@/shared/Panel/PanelLayout';
import PresenceBar from '@/Crm/Components/PresenceBar';
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

/**
 * Полоска присутствия — только в CRM: складу и админке знать, кто из партнёров
 * сейчас в каталоге, незачем, а каркас панели у всех троих общий.
 */
export const CrmLayout = ({ children, breadcrumbs = [] }) => (
    <PanelLayout panel={panel} breadcrumbs={breadcrumbs} topBar={<PresenceBar />}>
        {children}
    </PanelLayout>
);

export default CrmLayout;
