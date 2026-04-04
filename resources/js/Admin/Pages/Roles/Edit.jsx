import { useRef } from 'react';
import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions } from '@/Admin/Components';
import { Card, Input, Stack, Badge, Text, HStack } from '@chakra-ui/react';
import { PermissionMatrix } from '@/Admin/Components/PermissionMatrix';
import { toaster } from '@/components/ui/toaster';

export default function Edit({ role, permissionGroups, actionLabels }) {
    const isSuperAdmin = role.name === 'super-admin';

    const { data, setData, put, processing, errors, transform } = useForm({
        name: role.name || '',
        permissions: role.permissions || [],
    });

    const closeAfterSaveRef = useRef(false);
    transform((data) => ({ ...data, _close: closeAfterSaveRef.current ? 1 : 0 }));

    const handleSubmit = (e, shouldClose = false) => {
        e.preventDefault();
        closeAfterSaveRef.current = shouldClose;
        put(route('admin.roles.update', role.id), {
            onSuccess: () => toaster.create({ title: 'Роль обновлена', type: 'success' }),
        });
    };

    return (
        <>
            <PageHeader
                title={`Редактирование: ${role.name}`}
                description="Изменение названия и прав доступа"
            />

            <form onSubmit={handleSubmit}>
                <Card.Root mb={4}>
                    <Card.Body>
                        <Stack gap={4}>
                            <FormField label="Название роли" error={errors.name} required>
                                <Input
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    disabled={isSuperAdmin}
                                />
                            </FormField>
                            <HStack>
                                <Badge colorPalette="green">Пользователей: {role.users_count}</Badge>
                                <Badge colorPalette="blue">Прав: {data.permissions.length}</Badge>
                            </HStack>
                            {isSuperAdmin && (
                                <Text fontSize="sm" color="orange.500">
                                    ⚠️ Роль «super-admin» нельзя изменить. У неё автоматически полный доступ.
                                </Text>
                            )}
                        </Stack>
                    </Card.Body>
                </Card.Root>

                {!isSuperAdmin && (
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
                                submitLabel="Сохранить изменения"
                            />
                        </Card.Footer>
                    </Card.Root>
                )}
            </form>
        </>
    );
}

Edit.layout = (page) => <AdminLayout>{page}</AdminLayout>;
