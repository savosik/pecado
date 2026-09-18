import { Head } from '@inertiajs/react';
import CabinetLayout from '../CabinetLayout';
import InstructionList from '@/shared/Instructions/InstructionList';

/**
 * Инструкции для клиентов: как пользоваться кабинетом, заказами, резервами.
 */
export default function CabinetInstructionsIndex({ instructions }) {
    return (
        <CabinetLayout title="Инструкции">
            <Head title="Инструкции" />
            <InstructionList instructions={instructions} />
        </CabinetLayout>
    );
}
