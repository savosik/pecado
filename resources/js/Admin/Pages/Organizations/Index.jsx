import React from "react";
import { Badge, Box, HStack, Text } from "@chakra-ui/react";
import { Head, Link, usePage, router } from "@inertiajs/react";
import { LuPlus } from "react-icons/lu";
import { createActionsColumn } from '@/Admin/helpers/createActionsColumn';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { DataTable } from "@/Admin/Components/DataTable";
import { PageHeader } from "@/Admin/Components/PageHeader";
import { ConfirmDialog } from "@/Admin/Components/ConfirmDialog";
import { Button } from "@/components/ui/button";
import { Alert } from "@/components/ui/alert";
import { toaster } from "@/components/ui/toaster";
import { usePermission } from '@/Admin/hooks/usePermission';

const OrganizationsIndex = ({ filters }) => {
    const { organizations, stubCount } = usePage().props;
    const { can } = usePermission();
    const [deleteId, setDeleteId] = React.useState(null);

    const handleSort = (field, direction) => {
        router.get(route("admin.organizations.index"), {
            ...filters,
            sort_by: field,
            sort_order: direction,
        }, {
            preserveState: true,
            replace: true,
        });
    };

    const columns = [
        { label: "ID", key: "id", sortable: true },
        {
            label: "Название",
            key: "name",
            sortable: true,
            render: (value, organization) => (
                <HStack gap={2} align="center">
                    <Text fontWeight={organization.is_stub ? "normal" : "500"} color={organization.is_stub ? "fg.muted" : undefined}>
                        {value}
                    </Text>
                    {organization.is_stub && (
                        <Badge colorPalette="orange" variant="subtle">Не заведена — пришла из 1С</Badge>
                    )}
                </HStack>
            ),
        },
        { label: "UUID в 1С", key: "external_id", sortable: true },
        { label: "ИНН", key: "tax_id", sortable: true },
        {
            label: "Активна",
            key: "is_active",
            render: (value) => (
                <Badge colorPalette={value ? "green" : "gray"} variant="subtle">
                    {value ? "Да" : "Нет"}
                </Badge>
            ),
        },
        createActionsColumn('admin.organizations', (organization) => setDeleteId(organization.id), { permissionPrefix: 'organizations', showView: true }),
    ];

    const handleDelete = () => {
        if (deleteId) {
            router.delete(route("admin.organizations.destroy", deleteId), {
                onSuccess: () => {
                    toaster.create({
                        description: "Организация успешно удалена",
                        type: "success",
                    });
                    setDeleteId(null);
                },
            });
        }
    };

    return (
        <>
            <Head title="Организации" />

            <PageHeader
                title="Организации"
                actions={
                    can('organizations.create') && (
                        <Button asChild colorPalette="blue">
                            <Link href={route("admin.organizations.create")}>
                                <LuPlus /> Создать организацию
                            </Link>
                        </Button>
                    )
                }
            />

            {/*
                Заглушки — единственный сигнал, что 1С проводит документы на юрлицо,
                которого нет в справочнике. Без этого баннера клиент увидит в кабинете
                UUID вместо названия продавца, и никто об этом не узнает.
            */}
            {stubCount > 0 && (
                <Box mb={4}>
                    <Alert status="warning" title={`Не заведённых организаций: ${stubCount}`}>
                        1С прислала документы с UUID организаций, которых нет в справочнике. Такие записи
                        показаны первыми в списке. Откройте каждую, укажите название и реквизиты — все ранее
                        принятые заказы и реализации подтянут их автоматически.
                    </Alert>
                </Box>
            )}

            <DataTable
                columns={columns}
                data={organizations.data}
                pagination={organizations}
                searchPlaceholder="Поиск по названию, ИНН или UUID..."
                onSort={handleSort}
                sortColumn={filters?.sort_by}
                sortDirection={filters?.sort_order}
            />

            <ConfirmDialog
                open={!!deleteId}
                onClose={() => setDeleteId(null)}
                onConfirm={handleDelete}
                title="Удаление организации"
                description="Организация будет скрыта, но останется в истории документов. Если 1С продолжит проводить на неё документы, она вернётся в справочник автоматически."
            />
        </>
    );
};

OrganizationsIndex.layout = (page) => <AdminLayout>{page}</AdminLayout>;

export default OrganizationsIndex;
