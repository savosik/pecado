import { Head } from '@inertiajs/react';
import WmsLayout from '@/Wms/Layouts/WmsLayout';
import InstructionViewer from '@/shared/Instructions/InstructionViewer';

export default function WmsInstructionShow({ instruction, backUrl }) {
    return (
        <>
            <Head title={`Склад — ${instruction.title}`} />
            <InstructionViewer instruction={instruction} backUrl={backUrl} />
        </>
    );
}

WmsInstructionShow.layout = (page) => <WmsLayout>{page}</WmsLayout>;
