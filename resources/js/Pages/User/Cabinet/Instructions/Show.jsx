import { Head } from '@inertiajs/react';
import CabinetLayout from '../CabinetLayout';
import InstructionViewer from '@/shared/Instructions/InstructionViewer';

export default function CabinetInstructionShow({ instruction, backUrl }) {
    return (
        <CabinetLayout title={instruction.title}>
            <Head title={instruction.title} />
            <InstructionViewer instruction={instruction} backUrl={backUrl} showTitle={false} />
        </CabinetLayout>
    );
}
