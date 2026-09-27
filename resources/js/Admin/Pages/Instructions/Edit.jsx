import { Head } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader } from '@/Admin/Components';
import InstructionForm from './Form';

export default function Edit({ instruction, options }) {
    return (
        <>
            <Head title={`Инструкция: ${instruction.title}`} />
            <PageHeader title={instruction.title} description="Правка инструкции — читатель увидит новую дату обновления" />
            <InstructionForm instruction={instruction} options={options} />
        </>
    );
}

Edit.layout = (page) => <AdminLayout>{page}</AdminLayout>;
