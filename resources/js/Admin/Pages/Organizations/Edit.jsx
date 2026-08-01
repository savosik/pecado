import React, { useRef } from "react";
import { Box, Stack } from "@chakra-ui/react";
import { Head, useForm, usePage } from "@inertiajs/react";
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader } from "@/Admin/Components/PageHeader";
import { FormActions } from "@/Admin/Components/FormActions";
import { Alert } from "@/components/ui/alert";
import { toaster } from "@/components/ui/toaster";
import OrganizationForm from "./Components/OrganizationForm";

const OrganizationsEdit = () => {
    const { organization } = usePage().props;

    const { data, setData, put, processing, errors, transform } = useForm({
        name: organization.name ?? "",
        legal_name: organization.legal_name ?? "",
        external_id: organization.external_id ?? "",
        tax_id: organization.tax_id ?? "",
        tax_code: organization.tax_code ?? "",
        bank_name: organization.bank_name ?? "",
        bank_bik: organization.bank_bik ?? "",
        account_number: organization.account_number ?? "",
        correspondent_account: organization.correspondent_account ?? "",
        is_active: !!organization.is_active,
        sort_order: organization.sort_order ?? 0,
    });

    const closeAfterSaveRef = useRef(false);

    transform((data) => ({
        ...data,
        _close: closeAfterSaveRef.current ? 1 : 0,
    }));

    const handleSubmit = (e, shouldClose = false) => {
        e.preventDefault();
        closeAfterSaveRef.current = shouldClose;
        put(route("admin.organizations.update", organization.id), {
            onSuccess: () => {
                toaster.create({
                    description: "Организация успешно обновлена",
                    type: "success",
                });
            },
        });
    };

    return (
        <>
            <Head title={`Организация: ${organization.name}`} />

            <PageHeader
                title="Редактирование организации"
                backUrl={route("admin.organizations.index")}
                backLabel="К списку организаций"
            />

            {/*
                Заглушку создал OrganizationResolver: 1С прислала документ с UUID,
                которого не было в справочнике. Сохранение этой формы = подтверждение
                карточки админом, флаг is_stub снимается на бэкенде.
            */}
            {organization.is_stub && (
                <Box mb={4}>
                    <Alert status="warning" title="Организация не заведена вручную">
                        Запись создана автоматически: 1С прислала документ с этим UUID, а в справочнике
                        организации не было. Укажите название и реквизиты и сохраните — после этого пометка
                        снимется, а все ранее принятые заказы и реализации покажут организацию правильно.
                    </Alert>
                </Box>
            )}

            <form onSubmit={handleSubmit}>
                <Stack gap={4} maxW="3xl">
                    <OrganizationForm data={data} setData={setData} errors={errors} />

                    <FormActions
                        onSaveAndClose={(e) => handleSubmit(e, true)}
                        backUrl={route("admin.organizations.index")}
                        isLoading={processing}
                        submitLabel="Сохранить"
                    />
                </Stack>
            </form>
        </>
    );
};

OrganizationsEdit.layout = (page) => <AdminLayout>{page}</AdminLayout>;

export default OrganizationsEdit;
