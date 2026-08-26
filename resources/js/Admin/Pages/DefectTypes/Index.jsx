import { useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { Box, Card, HStack, Input, Table, Text, VStack } from '@chakra-ui/react';
import { LuPlus } from 'react-icons/lu';
import RowActions from '@/shared/Panel/RowActions';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { ConfirmDialog } from '@/Admin/Components/ConfirmDialog';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { useFlashToast } from '@/hooks/useFlashToast';
import { usePermission } from '@/Admin/hooks/usePermission';

export default function DefectTypesIndex() {
    const { types } = usePage().props;
    const { can } = usePermission();
    const [newName, setNewName] = useState('');
    const [adding, setAdding] = useState(false);
    const [deleteTarget, setDeleteTarget] = useState(null);

    useFlashToast();

    const canCreate = can('defect-types.create');
    const canEdit = can('defect-types.edit');
    const canDelete = can('defect-types.delete');

    const add = () => {
        if (!newName.trim()) return;
        setAdding(true);
        router.post('/admin/defect-types', { name: newName.trim() }, {
            preserveScroll: true,
            onSuccess: () => setNewName(''),
            onFinish: () => setAdding(false),
        });
    };

    const toggleActive = (type, checked) => {
        router.put(`/admin/defect-types/${type.id}`, { name: type.name, is_active: checked }, {
            preserveScroll: true,
        });
    };

    const remove = () => {
        if (!deleteTarget) return;
        router.delete(`/admin/defect-types/${deleteTarget.id}`, {
            preserveScroll: true,
            onFinish: () => setDeleteTarget(null),
        });
    };

    return (
        <>
            <Head title="Справочник дефектов" />
            <PageHeader
                title="Справочник дефектов"
                description="Типовые формулировки дефектов. Кладовщик выбирает их чипами при заведении некондиции."
            />

            <VStack gap={4} align="stretch" maxW="2xl">
                {canCreate && (
                    <Card.Root>
                        <Card.Body>
                            <HStack gap={2}>
                                <Input
                                    value={newName}
                                    onChange={(event) => setNewName(event.target.value)}
                                    onKeyDown={(event) => event.key === 'Enter' && add()}
                                    placeholder="Новый дефект, например «Помята упаковка»"
                                />
                                <Button onClick={add} loading={adding} disabled={!newName.trim()}>
                                    <LuPlus /> Добавить
                                </Button>
                            </HStack>
                        </Card.Body>
                    </Card.Root>
                )}

                <Card.Root>
                    <Card.Body>
                        {types.length === 0 ? (
                            <Text fontSize="sm" color="fg.muted" py={4} textAlign="center">
                                Справочник пуст. Добавьте первую формулировку.
                            </Text>
                        ) : (
                            <Table.Root size="sm" variant="line">
                                <Table.Header>
                                    <Table.Row>
                                        <Table.ColumnHeader w="64px">ID</Table.ColumnHeader>
                                        <Table.ColumnHeader>Дефект</Table.ColumnHeader>
                                        <Table.ColumnHeader w="120px" textAlign="center">Активен</Table.ColumnHeader>
                                        <Table.ColumnHeader w="60px" />
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {types.map((type) => (
                                        <Table.Row key={type.id}>
                                            <Table.Cell>
                                                <Text fontSize="sm" color="fg.muted" fontVariantNumeric="tabular-nums">
                                                    {type.id}
                                                </Text>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Text fontSize="sm" color={type.is_active ? undefined : 'fg.muted'}>
                                                    {type.name}
                                                </Text>
                                            </Table.Cell>
                                            <Table.Cell textAlign="center">
                                                <Switch
                                                    checked={type.is_active}
                                                    disabled={!canEdit}
                                                    onCheckedChange={(e) => toggleActive(type, e.checked)}
                                                />
                                            </Table.Cell>
                                            <Table.Cell>
                                                {canDelete && (
                                                    <RowActions size="xs" delete={{ onClick: () => setDeleteTarget(type) }} />
                                                )}
                                            </Table.Cell>
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </Table.Root>
                        )}

                        <Text fontSize="xs" color="fg.muted" mt={3}>
                            Скрытый дефект не показывается кладовщику, но остаётся в уже заведённых партиях.
                            Удаление формулировку не меняет в существующих партиях (там хранится снимок текста).
                        </Text>
                    </Card.Body>
                </Card.Root>
            </VStack>

            <ConfirmDialog
                open={deleteTarget !== null}
                onClose={() => setDeleteTarget(null)}
                onConfirm={remove}
                title="Удалить дефект из справочника?"
                description={deleteTarget ? `«${deleteTarget.name}» перестанет предлагаться кладовщику.` : ''}
                confirmLabel="Удалить"
            />
        </>
    );
}

DefectTypesIndex.layout = (page) => <AdminLayout>{page}</AdminLayout>;
