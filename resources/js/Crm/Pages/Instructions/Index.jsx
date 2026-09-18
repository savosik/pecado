import { Head } from '@inertiajs/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import InstructionList from '@/shared/Instructions/InstructionList';

/**
 * Инструкции для отдела продаж — ведутся в админке, здесь только читаются.
 */
export default function Index({ instructions }) {
    return (
        <>
            <Head title="CRM — Инструкции" />
            <PageHeader title="Инструкции" description="Как работать в CRM и с клиентами: тексты, PDF и видео" />
            <InstructionList instructions={instructions} />
        </>
    );
}

Index.layout = (page) => <CrmLayout>{page}</CrmLayout>;
