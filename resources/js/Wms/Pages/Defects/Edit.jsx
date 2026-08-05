import { useState } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import {
    Box,
    Card,
    Stack,
    SimpleGrid,
    Text,
    VStack,
} from '@chakra-ui/react';
import { LuBan, LuRotateCcw } from 'react-icons/lu';
import WmsLayout from '@/Wms/Layouts/WmsLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { MultipleImageUploader } from '@/Admin/Components/MultipleImageUploader';
import { ConfirmDialog } from '@/Admin/Components/ConfirmDialog';
import { Field } from '@/components/ui/field';
import { Button } from '@/components/ui/button';
import { usePermission } from '@/Admin/hooks/usePermission';
import { useFlashToast } from '@/hooks/useFlashToast';
import { DefectStatusBadge } from '@/Wms/Components/DefectStatusBadge';
import { DefectDescriptionField } from '@/Wms/Components/DefectDescriptionField';
import { QuantityStepper } from '@/Wms/Components/QuantityStepper';

const formatPrice = (value) =>
    value === null ? 'не назначена' : `${new Intl.NumberFormat('ru-RU').format(value)} ₽`;

function InfoRow({ label, children }) {
    return (
        <Box>
            <Text fontSize="xs" color="fg.muted">{label}</Text>
            <Box fontSize="sm">{children}</Box>
        </Box>
    );
}

export default function DefectsEdit() {
    const { defect, reserved, defectTypes = [] } = usePage().props;
    const { can } = usePermission();
    const [writeOffOpen, setWriteOffOpen] = useState(false);
    const [reopenOpen, setReopenOpen] = useState(false);

    useFlashToast();

    const { data, setData, post, processing, errors } = useForm({
        _method: 'put',
        defect_description: defect.defect_description,
        quantity: defect.quantity,
        photos: [],
        removed_media_ids: [],
    });

    const existingPhotos = defect.photos.filter(
        (photo) => !data.removed_media_ids.includes(photo.id)
    );

    const handleSubmit = (event) => {
        event.preventDefault();
        // Inertia не умеет слать файлы через put — идём через post + _method.
        post(`/wms/defects/${defect.id}`, { forceFormData: true });
    };

    const handleWriteOff = () => {
        router.post(`/wms/defects/${defect.id}/write-off`, {}, { preserveScroll: true });
    };

    const handleReopen = () => {
        router.post(`/wms/defects/${defect.id}/reopen`, {}, { preserveScroll: true });
    };

    return (
        <>
            <Head title={`Партия некондиции — ${defect.product.name}`} />
            <PageHeader
                title={defect.product.name}
                description={`Партия #${defect.id} · Артикул ${defect.product.sku || '—'} · склад «${defect.warehouse.name}»`}
            />

            <VStack gap={4} align="stretch" maxW="4xl">
                <Card.Root>
                    <Card.Body>
                        <SimpleGrid columns={{ base: 2, md: 4 }} gap={4}>
                            <InfoRow label="Состояние">
                                <DefectStatusBadge defect={defect} />
                            </InfoRow>
                            <InfoRow label="Цена уценки">
                                <Text>{formatPrice(defect.price)}</Text>
                            </InfoRow>
                            <InfoRow label="Зарезервировано">
                                <Text>{reserved} шт</Text>
                            </InfoRow>
                            <InfoRow label="Свободно">
                                <Text>{defect.available_quantity} шт</Text>
                            </InfoRow>
                        </SimpleGrid>

                        <Text fontSize="xs" color="fg.muted" mt={4}>
                            Цену и публикацию на сайте задаёт закупщик
                            {defect.priced_by_name ? ` (${defect.priced_by_name})` : ''}. Здесь они
                            показаны только для справки.
                        </Text>
                    </Card.Body>
                </Card.Root>

                {defect.closed_at ? (
                    <Card.Root>
                        <Card.Body>
                            <VStack gap={3} align="start">
                                <Text fontSize="sm" color="fg.muted">
                                    Партия закрыта ({defect.closed_reason_label?.toLowerCase()}) и больше
                                    не продаётся. Редактирование недоступно.
                                </Text>
                                {can('wms-defects.edit') && (
                                    <>
                                        <Text fontSize="sm" color="fg.muted">
                                            Если товар вернулся на склад — например, в 1С отменили
                                            реализацию или клиент сделал возврат — верните партию
                                            в продажу и укажите фактическое количество. Цена
                                            и публикация сохранятся.
                                        </Text>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            h="48px"
                                            onClick={() => setReopenOpen(true)}
                                        >
                                            <LuRotateCcw /> Вернуть в продажу
                                        </Button>
                                    </>
                                )}
                            </VStack>
                        </Card.Body>
                    </Card.Root>
                ) : (
                    <form onSubmit={handleSubmit}>
                        <VStack gap={4} align="stretch">
                            <Card.Root>
                                <Card.Body>
                                    <VStack gap={4} align="stretch">
                                        <Field
                                            label="Описание дефекта"
                                            required
                                            invalid={!!errors.defect_description}
                                            errorText={errors.defect_description}
                                        >
                                            <DefectDescriptionField
                                                value={data.defect_description}
                                                onChange={(text) => setData('defect_description', text)}
                                                types={defectTypes}
                                                error={errors.defect_description}
                                            />
                                        </Field>

                                        <Field
                                            label="Количество, шт"
                                            required
                                            invalid={!!errors.quantity}
                                            errorText={errors.quantity}
                                            helperText={
                                                reserved > 0
                                                    ? `Нельзя опустить ниже ${reserved} — столько уже в заказах`
                                                    : 'Товар и склад изменить нельзя: партию могли уже заказать'
                                            }
                                        >
                                            <QuantityStepper
                                                value={data.quantity}
                                                onChange={(next) => setData('quantity', next)}
                                                min={Math.max(1, reserved)}
                                                max={10000}
                                            />
                                        </Field>
                                    </VStack>
                                </Card.Body>
                            </Card.Root>

                            <Card.Root>
                                <Card.Body>
                                    <MultipleImageUploader
                                        name="photos"
                                        label="Фотографии дефекта"
                                        value={data.photos}
                                        onChange={(files) => setData('photos', files)}
                                        existingImages={existingPhotos.map((photo) => ({
                                            id: photo.id,
                                            url: photo.thumb_url,
                                        }))}
                                        onRemoveExisting={(id) =>
                                            setData('removed_media_ids', [...data.removed_media_ids, id])
                                        }
                                        error={errors.photos}
                                        maxFiles={10}
                                        maxSize={20}
                                        capture="environment"
                                    />
                                </Card.Body>
                            </Card.Root>

                            <Stack
                                direction={{ base: 'column', md: 'row' }}
                                gap={2}
                                justify="space-between"
                            >
                                <Stack direction={{ base: 'column', sm: 'row' }} gap={2}>
                                    <Button type="submit" h="48px" loading={processing}>
                                        Сохранить
                                    </Button>
                                    <Button
                                        variant="outline"
                                        type="button"
                                        h="48px"
                                        onClick={() => router.get('/wms/defects')}
                                    >
                                        К списку
                                    </Button>
                                </Stack>

                                {can('wms-defects.delete') && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        colorPalette="red"
                                        h="48px"
                                        disabled={reserved > 0}
                                        title={reserved > 0 ? 'По партии есть заказы — списать нельзя' : undefined}
                                        onClick={() => setWriteOffOpen(true)}
                                    >
                                        <LuBan /> Списать партию
                                    </Button>
                                )}
                            </Stack>
                        </VStack>
                    </form>
                )}
            </VStack>

            <ConfirmDialog
                open={writeOffOpen}
                onClose={() => setWriteOffOpen(false)}
                onConfirm={handleWriteOff}
                title="Списать партию?"
                description="Партия перестанет продаваться и уйдёт в закрытые. Используйте, если брак утилизирован."
                confirmLabel="Списать"
            />

            <ConfirmDialog
                open={reopenOpen}
                onClose={() => setReopenOpen(false)}
                onConfirm={handleReopen}
                title="Вернуть партию в продажу?"
                description="Партия снова станет доступна для правки и попадёт на витрину, если у неё есть цена и включена публикация. После возврата проверьте количество остатка."
                confirmLabel="Вернуть в продажу"
            />
        </>
    );
}

DefectsEdit.layout = (page) => <WmsLayout>{page}</WmsLayout>;
