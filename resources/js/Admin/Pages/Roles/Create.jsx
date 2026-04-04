import { useRef } from 'react';
import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions } from '@/Admin/Components';
import { Card, Input, Stack } from '@chakra-ui/react';
import { PermissionMatrix } from '@/Admin/Components/PermissionMatrix';
import { toaster } from '@/components/ui/toaster';

export default function Create({ permissionGroups, actionLabels }) {
    const { data, setData, post, processing, errors, transform } = useForm({
        name: '',
        permissions: [],
    });

    const closeAfterSaveRef = useRef(false);
    transform((data) => ({ ...data, _close: closeAfterSaveRef.current ? 1 : 0 }));

    const handleSubmit = (e, shouldClose = false) => {
        e.preventDefault();
        closeAfterSaveRef.current = shouldClose;
        post(route('admin.roles.store'), {
            onSuccess: () => toaster.create({ title: 'Роль создана', type: 'success' }),
        });
    };

    return (
        <>
            <PageHeader title="Создание роли" description="Настройте название и права доступа" />
            <form onSubmit={handleSubmit}>
                <Card.Root mb={4}>
                    <Card.Body>
                        <Stack gap={6}>
                            <FormField label="Название роли" error={errors.name} required>
                                <Input value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="Например: content-manager" />
                            </FormField>
                        </Stack>
                    </Card.Body>
                </Card.Root>

                <Card.Root>
                    <Card.Body>
                        <PermissionMatrix
                            permissionGroups={permissionGroups}
                            actionLabels={actionLabels}
                            selected={data.permissions}
                            onChange={(perms) => setData('permissions', perms)}
                        />
                    </Card.Body>
                    <Card.Footer>
                        <FormActions
                            onSaveAndClose={(e) => handleSubmit(e, true)}
                            loading={processing}
                            onCancel={() => window.history.back()}
                            submitLabel="Создать роль"
                        />
                    </Card.Footer>
                </Card.Root>
            </form>
        </>
    );
}

Create.layout = (page) => <AdminLayout>{page}</AdminLayout>;
