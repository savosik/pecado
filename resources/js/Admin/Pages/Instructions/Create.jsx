import { Head } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader } from '@/Admin/Components';
import InstructionForm from './Form';

export default function Create({ options }) {
    return (
        <>
            <Head title="Новая инструкция" />
            <PageHeader title="Новая инструкция" description="Текст блоками, PDF или видео — для клиентов, CRM или склада" />
            <InstructionForm options={options} />
        </>
    );
}

Create.layout = (page) => <AdminLayout>{page}</AdminLayout>;
