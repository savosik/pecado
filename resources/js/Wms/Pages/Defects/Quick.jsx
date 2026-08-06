import { useCallback, useEffect, useRef, useState } from 'react';
import { Head, usePage } from '@inertiajs/react';
import {
    Badge,
    Box,
    Card,
    HStack,
    IconButton,
    Image,
    Input,
    NativeSelect,
    Text,
    VStack,
} from '@chakra-ui/react';
import { LuCheck, LuImageOff, LuScanBarcode, LuX } from 'react-icons/lu';
import WmsLayout from '@/Wms/Layouts/WmsLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { MultipleImageUploader } from '@/Admin/Components/MultipleImageUploader';
import { Button } from '@/components/ui/button';
import { toaster } from '@/components/ui/toaster';
import BarcodeCameraView from '@/components/common/BarcodeCameraView';
import { DefectDescriptionField } from '@/Wms/Components/DefectDescriptionField';
import { DefectStockWarning } from '@/Wms/Components/DefectStockWarning';
import { QuantityStepper } from '@/Wms/Components/QuantityStepper';

const emptyDraft = () => ({ product: null, quantity: 1, description: '', photos: [] });

const MAX_PHOTOS = 10;

export default function DefectsQuick() {
    const { warehouses, defectTypes = [] } = usePage().props;

    const [warehouseId, setWarehouseId] = useState(
        warehouses.length === 1 ? String(warehouses[0].id) : ''
    );
    const [draft, setDraft] = useState(emptyDraft);
    const [manualBarcode, setManualBarcode] = useState('');
    const [resolving, setResolving] = useState(false);
    const [saving, setSaving] = useState(false);
    const [accepted, setAccepted] = useState(0);

    // Свежий черновик в ref — колбэк камеры создаётся один раз, а решение
    // «новый товар / +1 / не терять начатое» зависит от актуального состояния.
    const draftRef = useRef(draft);
    draftRef.current = draft;

    // Поле ручного ввода держим в фокусе: проводной/Bluetooth-сканер печатает
    // как клавиатура в активное поле и жмёт Enter — так серия сканов идёт без
    // ручного тапа. Возвращаем фокус, только если он не в другом поле ввода,
    // чтобы не мешать печатать дефект или количество.
    const barcodeInputRef = useRef(null);

    const focusBarcode = useCallback(() => {
        const active = document.activeElement;
        const tag = active?.tagName;
        const busyElsewhere =
            active &&
            active !== barcodeInputRef.current &&
            (tag === 'INPUT' || tag === 'TEXTAREA' || active.isContentEditable);

        if (busyElsewhere) {
            return;
        }

        barcodeInputRef.current?.focus();
    }, []);

    // Фокус на старте и после каждого скана/сохранения — покрывает поток сканов.
    useEffect(() => {
        focusBarcode();
    }, [focusBarcode]);

    const canSave =
        !!draft.product &&
        draft.description.trim().length >= 3 &&
        draft.photos.length > 0 &&
        !!warehouseId &&
        !saving;

    const handleBarcode = useCallback(async (barcode) => {
        const code = String(barcode || '').trim();
        if (!code || resolving) return;

        const current = draftRef.current;

        setResolving(true);
        try {
            const { data } = await window.axios.get('/wms/defects/resolve-barcode', {
                params: { barcode: code },
            });

            if (!data.found) {
                toaster.create({ description: `Товар по штрихкоду ${code} не найден.`, type: 'warning' });
                return;
            }

            const product = data.product;

            // Тот же товар — быстрый набор количества сканом.
            if (current.product && current.product.id === product.id) {
                setDraft((d) => ({ ...d, quantity: d.quantity + 1 }));
                toaster.create({ description: `${product.name}: +1 (стало ${current.quantity + 1})`, type: 'info' });
                return;
            }

            // Другой товар, а текущая партия уже начата (есть дефект или фото) —
            // не теряем работу: просим сначала сохранить.
            if (current.product && (current.description.trim() || current.photos.length > 0)) {
                toaster.create({
                    description: 'Сначала сохраните текущую партию, потом сканируйте следующий товар.',
                    type: 'warning',
                });
                return;
            }

            // Пустой черновик — просто ставим новый товар.
            setDraft({ product, quantity: 1, description: '', photos: [] });
        } catch {
            toaster.create({ description: 'Не удалось определить товар.', type: 'error' });
        } finally {
            setResolving(false);
            // Готовы к следующему скану сразу.
            focusBarcode();
        }
    }, [resolving, focusBarcode]);

    // Кадр с камеры-сканера кладём в фото текущей партии.
    const addPhoto = useCallback((file) => {
        setDraft((d) => {
            if (d.photos.length >= MAX_PHOTOS) {
                toaster.create({ description: `Больше ${MAX_PHOTOS} фото на партию не нужно.`, type: 'warning' });
                return d;
            }

            return { ...d, photos: [...d.photos, file] };
        });
    }, []);

    const submitManual = (e) => {
        e.preventDefault();
        if (manualBarcode.trim()) {
            handleBarcode(manualBarcode.trim());
            setManualBarcode('');
        }
    };

    const save = async () => {
        if (!canSave) return;
        setSaving(true);

        const form = new FormData();
        form.append('product_id', draft.product.id);
        form.append('warehouse_id', warehouseId);
        form.append('defect_description', draft.description.trim());
        form.append('quantity', String(draft.quantity));
        draft.photos.forEach((file) => form.append('photos[]', file));

        try {
            const { data } = await window.axios.post('/wms/defects/quick', form, {
                headers: { 'Content-Type': 'multipart/form-data' },
            });
            setAccepted((n) => n + 1);
            toaster.create({ description: `Партия принята: ${draft.product.name} ×${draft.quantity}`, type: 'success' });

            // Остаток мог измениться, пока черновик заполняли, — предупреждаем по факту записи.
            if (data?.warning) {
                toaster.create({ description: data.warning, type: 'warning', duration: 8000 });
            }
            setDraft(emptyDraft());
        } catch (error) {
            const bag = error?.response?.data?.errors;
            const first = bag ? Object.values(bag)[0]?.[0] : null;
            toaster.create({ description: first || 'Не удалось сохранить партию.', type: 'error' });
        } finally {
            setSaving(false);
            // Черновик сброшен — снова готовы принимать сканы.
            focusBarcode();
        }
    };

    const noDefectWarehouse = warehouses.length === 0;

    return (
        <>
            <Head title="Быстрый приём некондиции — Склад" />
            <PageHeader
                title="Быстрый приём"
                description="Сканируй штрихкод → выбери дефект → сфотографируй → дальше."
                actions={
                    accepted > 0 ? (
                        <Badge colorPalette="green" variant="subtle" fontSize="sm" px={3} py={1}>
                            Принято за сессию: {accepted}
                        </Badge>
                    ) : null
                }
            />

            {noDefectWarehouse ? (
                <Card.Root>
                    <Card.Body>
                        <Text fontSize="sm" color="fg.muted">
                            Склад некондиции не заведён. Администратор отмечает его флагом «Склад некондиции»
                            в разделе «Склады».
                        </Text>
                    </Card.Body>
                </Card.Root>
            ) : (
                <VStack gap={4} align="stretch" maxW="2xl">
                    {warehouses.length > 1 && (
                        <NativeSelect.Root>
                            <NativeSelect.Field
                                value={warehouseId}
                                onChange={(e) => setWarehouseId(e.target.value)}
                            >
                                <option value="">Выберите склад некондиции</option>
                                {warehouses.map((w) => (
                                    <option key={w.id} value={w.id}>{w.name}</option>
                                ))}
                            </NativeSelect.Field>
                            <NativeSelect.Indicator />
                        </NativeSelect.Root>
                    )}

                    {/* Камера-сканер: на паузе, пока идёт резолв/сохранение */}
                    <Card.Root overflow="hidden">
                        <Box position="relative" bg="black" minH="220px">
                            <BarcodeCameraView
                                onScan={handleBarcode}
                                paused={resolving || saving}
                                height="240px"
                                // Фото снимаем кадром из этого же потока: камера у рабочего
                                // места одна, отдельный getUserMedia на неё браузер не даст.
                                // До выбора товара складывать снимок некуда — кнопки нет.
                                onCapture={draft.product ? addPhoto : null}
                                captureLabel="Сфотографировать дефект"
                            />
                            <Box position="absolute" top={2} left={2}>
                                <Badge colorPalette="blackAlpha" variant="solid">
                                    <LuScanBarcode style={{ display: 'inline', marginRight: 4 }} />
                                    Наведите на штрихкод
                                </Badge>
                            </Box>
                        </Box>
                        <Card.Body>
                            <form onSubmit={submitManual}>
                                <HStack gap={2}>
                                    <Input
                                        ref={barcodeInputRef}
                                        value={manualBarcode}
                                        onChange={(e) => setManualBarcode(e.target.value)}
                                        placeholder="Штрихкод: скан или ввод"
                                        inputMode="numeric"
                                        autoFocus
                                        // Не даём полю «залипнуть» пустым фокусом при
                                        // потере — фокус вернём осознанно после действий.
                                        h="44px"
                                    />
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        h="44px"
                                        flexShrink={0}
                                        disabled={!manualBarcode.trim()}
                                    >
                                        Найти
                                    </Button>
                                </HStack>
                            </form>
                        </Card.Body>
                    </Card.Root>

                    {/* Текущая партия */}
                    {draft.product ? (
                        <Card.Root>
                            <Card.Body>
                                <VStack gap={4} align="stretch">
                                    <HStack gap={3}>
                                        {draft.product.image_url ? (
                                            <Image src={draft.product.image_url} alt="" boxSize="56px" objectFit="cover" rounded="md" />
                                        ) : (
                                            <Box boxSize="56px" display="flex" alignItems="center" justifyContent="center" color="fg.muted" borderWidth="1px" borderColor="border" rounded="md">
                                                <LuImageOff size={18} />
                                            </Box>
                                        )}
                                        <Box flex="1" minW="0">
                                            <Text fontWeight="medium" lineClamp={2}>{draft.product.name}</Text>
                                            <Text fontSize="xs" color="fg.muted">{draft.product.sku || '—'}</Text>
                                        </Box>
                                        <IconButton
                                            aria-label="Сбросить"
                                            size="sm"
                                            variant="ghost"
                                            onClick={() => setDraft(emptyDraft())}
                                        >
                                            <LuX />
                                        </IconButton>
                                    </HStack>

                                    <HStack gap={3} justify="space-between">
                                        <Text fontSize="sm" color="fg.muted">Количество</Text>
                                        <QuantityStepper
                                            value={draft.quantity}
                                            onChange={(next) => setDraft((d) => ({ ...d, quantity: next }))}
                                            min={1}
                                        />
                                    </HStack>

                                    <DefectStockWarning
                                        product={draft.product}
                                        warehouseId={warehouseId}
                                        quantity={draft.quantity}
                                    />

                                    <Box>
                                        <Text fontSize="sm" color="fg.muted" mb={1}>Дефект</Text>
                                        <DefectDescriptionField
                                            value={draft.description}
                                            onChange={(text) => setDraft((d) => ({ ...d, description: text }))}
                                            types={defectTypes}
                                        />
                                    </Box>

                                    <Box>
                                        <Text fontSize="sm" color="fg.muted" mb={1}>
                                            Фото дефекта <Text as="span" color="red.500">*</Text>
                                        </Text>
                                        <Text fontSize="xs" color="fg.muted" mb={2}>
                                            Снимите кнопкой «Сфотографировать дефект» на камере выше
                                            или загрузите файлы с диска.
                                        </Text>
                                        <MultipleImageUploader
                                            name="photos"
                                            label=""
                                            value={draft.photos}
                                            onChange={(files) => setDraft((d) => ({ ...d, photos: files }))}
                                            maxFiles={MAX_PHOTOS}
                                            maxSize={20}
                                            capture="environment"
                                        />
                                    </Box>

                                    <Button size="lg" h="56px" onClick={save} loading={saving} disabled={!canSave}>
                                        <LuCheck /> Сохранить и дальше
                                    </Button>
                                    {!canSave && !saving && (
                                        <Text fontSize="xs" color="fg.muted" textAlign="center">
                                            Нужны дефект и хотя бы одно фото.
                                        </Text>
                                    )}
                                </VStack>
                            </Card.Body>
                        </Card.Root>
                    ) : (
                        <Card.Root>
                            <Card.Body>
                                <HStack gap={2} color="fg.muted" py={4} justify="center">
                                    <LuScanBarcode size={18} />
                                    <Text fontSize="sm">Отсканируйте штрихкод товара, чтобы начать.</Text>
                                </HStack>
                            </Card.Body>
                        </Card.Root>
                    )}
                </VStack>
            )}
        </>
    );
}

DefectsQuick.layout = (page) => <WmsLayout>{page}</WmsLayout>;
