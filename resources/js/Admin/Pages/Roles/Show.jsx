import { Head, usePage, router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader } from '@/Admin/Components';
import { Box, Badge, Card, SimpleGrid, Text, VStack } from '@chakra-ui/react';
import { LuPencil } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import { usePermission } from '@/Admin/hooks/usePermission';

function InfoRow({ label, value }) {
    return (
        <Box>
            <Text fontSize="xs" color="gray.500" mb="0.5">{label}</Text>
            <Text fontSize="sm" fontWeight="500">{value || '—'}</Text>
        </Box>
    );
}

export default function Show() {
    const { role, permissionGroups, actionLabels } = usePage().props;
    const { can } = usePermission();

    const rolePermissions = new Set(role.permissions || []);

    return (
        <>
            <Head title={`Роль: ${role.name}`} />
            <PageHeader
                title={role.name}
                backUrl={route('admin.roles.index')}
                backLabel="К списку ролей"
                actions={
                    can('roles.edit') && (
                        <Button size="sm" onClick={() => router.visit(route('admin.roles.edit', role.id))}>
                            <LuPencil /> Редактировать
                        </Button>
                    )
                }
            />
            <VStack gap={4} align="stretch">
                <Card.Root>
                    <Card.Header>
                        <Text fontWeight="semibold" fontSize="lg">Основная информация</Text>
                    </Card.Header>
                    <Card.Body>
                        <SimpleGrid columns={{ base: 2, md: 3 }} gap={4}>
                            <InfoRow label="ID" value={role.id?.toString()} />
                            <InfoRow label="Название" value={role.name} />
                            <InfoRow label="Пользователей" value={role.users_count?.toString()} />
                            <InfoRow label="Прав" value={role.permissions?.length?.toString()} />
                        </SimpleGrid>
                    </Card.Body>
                </Card.Root>

                {permissionGroups?.map((group) => (
                    <Card.Root key={group.group}>
                        <Card.Header>
                            <Text fontWeight="semibold" fontSize="lg">{group.group}</Text>
                        </Card.Header>
                        <Card.Body>
                            <VStack gap={3} align="stretch">
                                {group.items.map((item) => (
                                    <Box key={item.resource}>
                                        <Text fontSize="sm" fontWeight="500" mb={2}>{item.label}</Text>
                                        <Box display="flex" gap={2} flexWrap="wrap">
                                            {item.actions.map((action) => {
                                                const perm = `${item.resource}.${action}`;
                                                const hasPermission = rolePermissions.has(perm);
                                                return (
                                                    <Badge
                                                        key={action}
                                                        colorPalette={hasPermission ? 'green' : 'gray'}
                                                        variant={hasPermission ? 'solid' : 'subtle'}
                                                        size="sm"
                                                    >
                                                        {actionLabels?.[action] || action}
                                                    </Badge>
                                                );
                                            })}
                                        </Box>
                                    </Box>
                                ))}
                            </VStack>
                        </Card.Body>
                    </Card.Root>
                ))}
            </VStack>
        </>
    );
}

Show.layout = (page) => <AdminLayout>{page}</AdminLayout>;
