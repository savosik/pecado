import { Head } from '@inertiajs/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import InstructionViewer from '@/shared/Instructions/InstructionViewer';

export default function Show({ instruction, backUrl }) {
    return (
        <>
            <Head title={`CRM — ${instruction.title}`} />
            <InstructionViewer instruction={instruction} backUrl={backUrl} />
        </>
    );
}

Show.layout = (page) => <CrmLayout>{page}</CrmLayout>;
