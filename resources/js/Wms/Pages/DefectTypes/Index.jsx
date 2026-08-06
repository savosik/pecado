import { useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { Card, HStack, Input, Table, Text, VStack } from '@chakra-ui/react';
import { LuPlus, LuTrash2 } from 'react-icons/lu';
import WmsLayout from '@/Wms/Layouts/WmsLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { ConfirmDialog } from '@/Admin/Components/ConfirmDialog';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { useFlashToast } from '@/hooks/useFlashToast';
import { usePermission } from '@/shared/Panel/usePermission';

/**
 * Справочник дефектов в кабинете склада — тот же, что и в /admin.
 * Ведёт его начальник склада: в админку роль не пускает.
 */
export default function WmsDefectTypesIndex() {
    const { types } = usePage().props;
    const { can } = usePermission();
    const [newName, setNewName] = useState('');
    const [adding, setAdding] = useState(false);
    const [deleteTarget, setDeleteTarget] = useState(null);

    useFlashToast();

    const canCreate = can('wms-defect-types.create');
    const canEdit = can('wms-defect-types.edit');
    const canDelete = can('wms-defect-types.delete');

    const add = () => {
        if (!newName.trim()) return;
        setAdding(true);
        router.post('/wms/defect-types', { name: newName.trim() }, {
            preserveScroll: true,
            onSuccess: () => setNewName(''),
            onFinish: () => setAdding(false),
        });
    };

    const toggleActive = (type, checked) => {
        router.put(`/wms/defect-types/${type.id}`, { name: type.name, is_active: checked }, {
            preserveScroll: true,
        });
    };

    const remove = () => {
        if (!deleteTarget) return;
        router.delete(`/wms/defect-types/${deleteTarget.id}`, {
            preserveScroll: true,
            onFinish: () => setDeleteTarget(null),
        });
    };

    return (
        <>
            <Head title="Справочник дефектов — Склад" />
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
                                    h="44px"
                                />
                                <Button onClick={add} loading={adding} disabled={!newName.trim()} h="44px" flexShrink={0}>
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
                                                {canEdit ? (
                                                    <DefectTypeNameCell type={type} />
                                                ) : (
                                                    <Text fontSize="sm" color={type.is_active ? undefined : 'fg.muted'}>
                                                        {type.name}
                                                    </Text>
                                                )}
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
                                                    <Button
                                                        size="xs"
                                                        variant="ghost"
                                                        colorPalette="red"
                                                        onClick={() => setDeleteTarget(type)}
                                                        aria-label="Удалить дефект"
                                                    >
                                                        <LuTrash2 />
                                                    </Button>
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

/**
 * Название дефекта с правкой по месту: клик → инпут, Enter/blur сохраняет,
 * Esc отменяет. В админке правки названия нет — складу она нужна, чтобы
 * не заводить дубль ради опечатки.
 */
function DefectTypeNameCell({ type }) {
    const [editing, setEditing] = useState(false);
    const [draft, setDraft] = useState(type.name);

    const save = () => {
        const name = draft.trim();
        setEditing(false);

        if (!name || name === type.name) {
            setDraft(type.name);
            return;
        }

        router.put(`/wms/defect-types/${type.id}`, { name, is_active: type.is_active }, {
            preserveScroll: true,
            onError: () => setDraft(type.name),
        });
    };

    if (!editing) {
        return (
            <Text
                fontSize="sm"
                color={type.is_active ? undefined : 'fg.muted'}
                cursor="text"
                onClick={() => {
                    setDraft(type.name);
                    setEditing(true);
                }}
                title="Нажмите, чтобы изменить формулировку"
            >
                {type.name}
            </Text>
        );
    }

    return (
        <Input
            size="sm"
            autoFocus
            value={draft}
            onChange={(event) => setDraft(event.target.value)}
            onBlur={save}
            onKeyDown={(event) => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    save();
                }
                if (event.key === 'Escape') {
                    setDraft(type.name);
                    setEditing(false);
                }
            }}
        />
    );
}

WmsDefectTypesIndex.layout = (page) => <WmsLayout>{page}</WmsLayout>;
