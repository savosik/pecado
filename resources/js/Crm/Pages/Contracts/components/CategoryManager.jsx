import { useState } from 'react';
import axios from 'axios';
import { router } from '@inertiajs/react';
import { Badge, Box, HStack, Input, SimpleGrid, Table, Text, VStack } from '@chakra-ui/react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import RowActions from '@/shared/Panel/RowActions';
import { useConfirmDelete } from '@/shared/Panel/useConfirmDelete';
import { ConfirmDialog } from '@/shared/Panel/ConfirmDialog';
import { toastError, toastSuccess } from '@/utils/toast';
import { LuPlus } from 'react-icons/lu';

const controlStyle = {
    padding: '0.5rem',
    borderRadius: '0.375rem',
    border: '1px solid var(--chakra-colors-border)',
    width: '100%',
};

const emptyForm = { name: '', description: '', organization_id: '', sort_order: 0, is_active: true };

/**
 * Вкладки реестра — заводит и правит РОП.
 *
 * Удалить можно только пустую категорию; с договорами — отключить.
 */
export default function CategoryManager({ categories = [], organizations = [], canDelete = false, onClose }) {
    const [editing, setEditing] = useState(null);
    const [form, setForm] = useState(emptyForm);
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    const startCreate = () => { setEditing('new'); setForm({ ...emptyForm, sort_order: (categories.length + 1) * 10 }); setErrors({}); };
    const startEdit = (item) => {
        setEditing(item.id);
        setForm({
            name: item.name,
            description: item.description || '',
            organization_id: item.organization_id || '',
            sort_order: item.sort_order || 0,
            is_active: item.is_active !== false,
        });
        setErrors({});
    };

    const del = useConfirmDelete({
        title: 'Удалить категорию?',
        description: (row) => `Категория «${row?.name ?? ''}» будет удалена. Удалить можно только пустую категорию.`,
        onConfirm: async (row) => {
            try {
                await axios.delete(route('crm.contract-categories.destroy', row.id));
                toastSuccess('Категория удалена');
                router.reload();
            } catch (e) {
                toastError(e.response?.data?.errors?.category?.[0] || 'Не удалось удалить категорию');
            }
        },
    });

    const save = async () => {
        setSaving(true);
        setErrors({});
        const payload = { ...form, organization_id: form.organization_id || null };

        try {
            if (editing === 'new') {
                await axios.post(route('crm.contract-categories.store'), payload);
            } else {
                await axios.patch(route('crm.contract-categories.update', editing), payload);
            }
            toastSuccess('Категория сохранена');
            setEditing(null);
            router.reload();
        } catch (e) {
            if (e.response?.status === 422) {
                setErrors(e.response.data.errors || {});
            } else {
                toastError('Не удалось сохранить категорию');
            }
        } finally {
            setSaving(false);
        }
    };

    const errorOf = (key) => (errors[key] ? errors[key][0] : null);

    return (
        <Box borderWidth="1px" borderRadius="lg" p={4}>
            <HStack justify="space-between" mb={3}>
                <Text fontWeight="600">Категории реестра (вкладки)</Text>
                <HStack gap={2}>
                    <Button size="xs" variant="outline" onClick={startCreate}><LuPlus /> Новая категория</Button>
                    {onClose && <Button size="xs" variant="ghost" onClick={onClose}>Закрыть</Button>}
                </HStack>
            </HStack>

            <Table.Root size="sm" variant="line">
                <Table.Header>
                    <Table.Row>
                        <Table.ColumnHeader>Название</Table.ColumnHeader>
                        <Table.ColumnHeader>Наше юрлицо</Table.ColumnHeader>
                        <Table.ColumnHeader>Договоров</Table.ColumnHeader>
                        <Table.ColumnHeader>Порядок</Table.ColumnHeader>
                        <Table.ColumnHeader>Действия</Table.ColumnHeader>
                    </Table.Row>
                </Table.Header>
                <Table.Body>
                    {categories.map((item) => (
                        <Table.Row key={item.id}>
                            <Table.Cell>
                                <Text fontSize="sm" fontWeight="500">{item.name}</Text>
                                {item.description && <Text fontSize="xs" color="fg.muted">{item.description}</Text>}
                                {item.is_active === false && <Badge size="sm" colorPalette="gray" mt={1}>отключена</Badge>}
                            </Table.Cell>
                            <Table.Cell><Text fontSize="sm">{item.organization || '—'}</Text></Table.Cell>
                            <Table.Cell><Text fontSize="sm">{item.contracts_count}</Text></Table.Cell>
                            <Table.Cell><Text fontSize="sm">{item.sort_order}</Text></Table.Cell>
                            <Table.Cell>
                                <RowActions
                                    size="xs"
                                    edit={{ onClick: () => startEdit(item) }}
                                    delete={{ onClick: () => del.request(item), allowed: canDelete && item.contracts_count === 0 }}
                                />
                            </Table.Cell>
                        </Table.Row>
                    ))}
                </Table.Body>
            </Table.Root>

            {editing !== null && (
                <VStack align="stretch" gap={3} mt={4} pt={4} borderTopWidth="1px">
                    <SimpleGrid columns={{ base: 1, md: 3 }} gap={3}>
                        <Box>
                            <Text fontSize="xs" color="fg.muted" mb={1}>Название</Text>
                            <Input size="sm" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
                            {errorOf('name') && <Text fontSize="xs" color="red.500">{errorOf('name')}</Text>}
                        </Box>
                        <Box>
                            <Text fontSize="xs" color="fg.muted" mb={1}>Наше юрлицо (необязательно)</Text>
                            <select value={form.organization_id} onChange={(e) => setForm({ ...form, organization_id: e.target.value })} style={controlStyle}>
                                <option value="">Не указано</option>
                                {organizations.map((org) => <option key={org.id} value={org.id}>{org.name}</option>)}
                            </select>
                        </Box>
                        <Box>
                            <Text fontSize="xs" color="fg.muted" mb={1}>Порядок</Text>
                            <Input size="sm" type="number" value={form.sort_order} onChange={(e) => setForm({ ...form, sort_order: e.target.value })} />
                        </Box>
                    </SimpleGrid>
                    <Box>
                        <Text fontSize="xs" color="fg.muted" mb={1}>Пояснение</Text>
                        <Input size="sm" value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} placeholder="Какие договоры заводить в эту вкладку" />
                    </Box>
                    <Checkbox checked={!!form.is_active} onCheckedChange={(e) => setForm({ ...form, is_active: !!e.checked })}>
                        Категория активна
                    </Checkbox>
                    <HStack justify="flex-end" gap={2}>
                        <Button size="sm" variant="ghost" onClick={() => setEditing(null)} disabled={saving}>Отмена</Button>
                        <Button size="sm" onClick={save} loading={saving}>Сохранить</Button>
                    </HStack>
                </VStack>
            )}

            <ConfirmDialog {...del.dialogProps} />
        </Box>
    );
}
