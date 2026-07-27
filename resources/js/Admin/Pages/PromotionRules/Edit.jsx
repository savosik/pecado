import AdminLayout from '@/Admin/Layouts/AdminLayout';
import RuleForm from './components/RuleForm';

export default function Edit({ rule, promotions, regions, warehouses, erp_promotion_types, issue_mode_available }) {
    return (
        <RuleForm
            isEdit
            rule={rule}
            promotions={promotions}
            regions={regions}
            warehouses={warehouses}
            erpPromotionTypes={erp_promotion_types}
            issueModeAvailable={issue_mode_available}
        />
    );
}

Edit.layout = (page) => <AdminLayout>{page}</AdminLayout>;
