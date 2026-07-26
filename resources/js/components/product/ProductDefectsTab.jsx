import { useEffect, useRef, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { Badge, Box, HStack, Image, SimpleGrid, Spinner, Stack, Text, VStack } from '@chakra-ui/react';
import { LuImageOff } from 'react-icons/lu';
import QuantityControl from '@/components/common/QuantityControl';
import ImageLightbox from '@/components/common/ImageLightbox';
import { useCartStore } from '@/stores/useCartStore';

const formatPrice = (value, currency) => {
    const symbol = currency?.symbol ?? '₽';
    return `${new Intl.NumberFormat('ru-RU').format(value)} ${symbol}`;
};

/** Сколько превью показываем; на последнем — «+N», если фото больше. */
const MAX_VISIBLE_PHOTOS = 4;
const THUMB_SIZE = '56px';

/**
 * Компактные одинаковые превью дефекта (квадрат), чтобы много фото не
 * распухали в карточке. Если фотографий больше MAX_VISIBLE_PHOTOS, последняя
 * плитка показывает «+N». Клик по любой открывает полный набор в галерее
 * (ImageLightbox) — там пользователь и рассматривает дефекты крупно.
 */
function DefectPhotos({ photos, alt }) {
    const [lightboxIndex, setLightboxIndex] = useState(null);

    if (!photos || photos.length === 0) {
        return (
            <Box
                boxSize={THUMB_SIZE}
                borderRadius="md"
                borderWidth="1px"
                borderColor="border"
                display="flex"
                alignItems="center"
                justifyContent="center"
                color="fg.muted"
                flexShrink={0}
            >
                <LuImageOff size={18} />
            </Box>
        );
    }

    const hasOverflow = photos.length > MAX_VISIBLE_PHOTOS;
    // При переполнении оставляем место под плитку «+N».
    const visible = hasOverflow ? photos.slice(0, MAX_VISIBLE_PHOTOS - 1) : photos;
    const overflowCount = photos.length - visible.length;

    return (
        <>
            <HStack gap={2} flexWrap="wrap">
                {visible.map((photo, i) => (
                    <Image
                        key={photo.id}
                        src={photo.thumb_url}
                        alt={alt}
                        boxSize={THUMB_SIZE}
                        objectFit="cover"
                        borderRadius="md"
                        cursor="zoom-in"
                        borderWidth="1px"
                        borderColor="border"
                        onClick={() => setLightboxIndex(i)}
                    />
                ))}

                {hasOverflow && (
                    <Box
                        position="relative"
                        boxSize={THUMB_SIZE}
                        borderRadius="md"
                        borderWidth="1px"
                        borderColor="border"
                        overflow="hidden"
                        cursor="zoom-in"
                        flexShrink={0}
                        onClick={() => setLightboxIndex(visible.length)}
                    >
                        <Image
                            src={photos[visible.length].thumb_url}
                            alt={alt}
                            boxSize="full"
                            objectFit="cover"
                        />
                        <Box
                            position="absolute"
                            inset="0"
                            bg="blackAlpha.600"
                            display="flex"
                            alignItems="center"
                            justifyContent="center"
                        >
                            <Text color="white" fontWeight="bold" fontSize="sm">
                                +{overflowCount}
                            </Text>
                        </Box>
                    </Box>
                )}
            </HStack>

            <ImageLightbox
                images={photos.map((p) => ({ url: p.url, alt }))}
                initialIndex={lightboxIndex ?? 0}
                open={lightboxIndex !== null}
                onClose={() => setLightboxIndex(null)}
                title={alt}
            />
        </>
    );
}

/**
 * Контрол уценки в корзине — идентичен товарному (CartQuantityControl):
 * счётчик [−] N [+], привязанный к стору корзины. Изменение сразу оптимистично
 * применяется и дебаунсом синкается на сервер (0 = удалить), без отдельной
 * кнопки «В корзину». N = количество ЭТОЙ партии, уже лежащее в корзине.
 * Только для активных пользователей (данные с ценами приходят только им).
 */
function DefectCartControl({ defect }) {
    const { auth } = usePage().props;
    const user = auth?.user && (auth.user.status === 'active' || auth.user.is_staff) ? auth.user : null;

    const [qty, setQty] = useState(0);
    const [syncing, setSyncing] = useState(false);
    const initRef = useRef(false);

    useEffect(() => {
        if (!user) return undefined;

        const store = useCartStore.getState();
        if (!initRef.current) {
            store.init(user);
            initRef.current = true;
        }

        const did = Number(defect.id);
        setQty(store.getDefectQuantity(did));
        setSyncing(store.isSyncingDefect(did));

        return useCartStore.subscribe((state) => {
            setQty(state.defectQuantities[did] || 0);
            setSyncing(state.syncingDefects.has(did));
        });
    }, [defect.id, user]);

    if (!user) return null;

    // Потолок — свободный остаток партии + уже лежащее в корзине (иначе своя же
    // позиция «съела» бы остаток и потолок оказался бы ниже текущего qty).
    const max = Math.max(0, Number(defect.available_quantity || 0)) + qty;

    return (
        <Box position="relative" display="inline-block">
            <QuantityControl
                value={qty}
                onChange={(value) => useCartStore.getState().setDefectQuantity(defect.id, value)}
                min={0}
                max={max > 0 ? max : undefined}
                size="sm"
            />
            {syncing && (
                <Box position="absolute" top="50%" right="-6" transform="translateY(-50%)" pointerEvents="none" aria-hidden="true">
                    <Spinner size="xs" color="pecado.500" />
                </Box>
            )}
        </Box>
    );
}

/**
 * Таб «Уценка» на карточке товара.
 *
 * Список партий некондиции этого товара, допущенных в продажу: описание дефекта,
 * фотографии, цена и остаток. Данные приходят только тем, кто видит цены
 * (см. ProductController::canViewPrices) — гостю таб не показывается вообще.
 *
 * @param {Array} defects - Партии [{id, defect_description, price, available_quantity, photos}]
 * @param {Object} currency - Валюта витрины {symbol}
 */
export default function ProductDefectsTab({ defects = [], currency = null }) {
    if (!Array.isArray(defects) || defects.length === 0) {
        return null;
    }

    return (
        <Stack gap="3">
            <Text fontSize="sm" color="fg.muted">
                Товары с уценкой — с дефектами, но по сниженной цене. Каждая позиция ниже — отдельный
                экземпляр со своим дефектом; фотографии показывают его состояние.
            </Text>

            {defects.map((defect) => (
                <Box
                    key={defect.id}
                    borderWidth="1px"
                    borderColor="border"
                    borderRadius="md"
                    p="4"
                >
                    <SimpleGrid columns={{ base: 1, md: 2 }} gap="4" alignItems="start">
                        <HStack align="start" gap="3">
                            <DefectPhotos photos={defect.photos} alt={defect.defect_description} />
                            <Text fontSize="sm">{defect.defect_description}</Text>
                        </HStack>

                        <VStack align={{ base: 'start', md: 'end' }} gap="2">
                            <Text fontSize="xl" fontWeight="bold">
                                {formatPrice(defect.price, currency)}
                            </Text>
                            <Badge colorPalette={defect.available_quantity > 0 ? 'green' : 'gray'} variant="subtle">
                                {defect.available_quantity > 0
                                    ? `Осталось: ${defect.available_quantity} шт`
                                    : 'Нет в наличии'}
                            </Badge>
                            <DefectCartControl defect={defect} />
                        </VStack>
                    </SimpleGrid>
                </Box>
            ))}
        </Stack>
    );
}
