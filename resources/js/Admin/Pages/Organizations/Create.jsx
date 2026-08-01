import React, { useRef } from "react";
import { Stack } from "@chakra-ui/react";
import { Head, useForm } from "@inertiajs/react";
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader } from "@/Admin/Components/PageHeader";
import { FormActions } from "@/Admin/Components/FormActions";
import { toaster } from "@/components/ui/toaster";
import OrganizationForm from "./Components/OrganizationForm";

const OrganizationsCreate = () => {
    const { data, setData, post, processing, errors, transform } = useForm({
        name: "",
        legal_name: "",
        external_id: "",
        tax_id: "",
        tax_code: "",
        bank_name: "",
        bank_bik: "",
        account_number: "",
        correspondent_account: "",
        is_active: true,
        sort_order: 0,
    });

    const closeAfterSaveRef = useRef(false);

    transform((data) => ({
        ...data,
        _close: closeAfterSaveRef.current ? 1 : 0,
    }));

    const handleSubmit = (e, shouldClose = false) => {
        e.preventDefault();
        closeAfterSaveRef.current = shouldClose;
        post(route("admin.organizations.store"), {
            onSuccess: () => {
                toaster.create({
                    description: "Организация успешно создана",
                    type: "success",
                });
            },
        });
    };

    return (
        <>
            <Head title="Создание организации" />

            <PageHeader title="Создание организации" backUrl={route("admin.organizations.index")} />

            <form onSubmit={handleSubmit}>
                <Stack gap={4} maxW="3xl">
                    <OrganizationForm data={data} setData={setData} errors={errors} />

                    <FormActions
                        onSaveAndClose={(e) => handleSubmit(e, true)}
                        backUrl={route("admin.organizations.index")}
                        isLoading={processing}
                        submitLabel="Создать"
                    />
                </Stack>
            </form>
        </>
    );
};

OrganizationsCreate.layout = (page) => <AdminLayout>{page}</AdminLayout>;

export default OrganizationsCreate;
