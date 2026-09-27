import { Head } from '@inertiajs/react';
import WmsLayout from '@/Wms/Layouts/WmsLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import InstructionList from '@/shared/Instructions/InstructionList';

/**
 * Инструкции для склада — ведутся в админке, здесь только читаются.
 */
export default function WmsInstructionsIndex({ instructions }) {
    return (
        <>
            <Head title="Склад — Инструкции" />
            <PageHeader title="Инструкции" description="Приём, сборка, выдача, некондиция: тексты, PDF и видео" />
            <InstructionList instructions={instructions} />
        </>
    );
}

WmsInstructionsIndex.layout = (page) => <WmsLayout>{page}</WmsLayout>;
