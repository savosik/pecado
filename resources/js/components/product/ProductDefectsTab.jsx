import { useState } from 'react';
import { usePage } from '@inertiajs/react';
import { Badge, Box, HStack, Image, SimpleGrid, Stack, Text, VStack } from '@chakra-ui/react';
import { LuImageOff } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import { toaster } from '@/components/ui/toaster';
import QuantityControl from '@/components/common/QuantityControl';
import ImageLightbox from '@/components/common/ImageLightbox';

const formatPrice = (value, currency) => {
    const symbol = currency?.symbol ?? '₽';
    return `${new Intl.NumberFormat('ru-RU').format(value)} ${symbol}`;
};

/**
 * Фото партии в пропорции 1:1.5 (как товарные превью). Клик открывает то же
 * полноэкранное увеличение, что и основное фото товара (ImageLightbox).
 */
function DefectPhotos({ photos, alt }) {
    const [lightboxIndex, setLightboxIndex] = useState(null);

    if (!photos || photos.length === 0) {
        return (
            <Box
                w="90px"
                aspectRatio={2 / 3}
                borderRadius="md"
                borderWidth="1px"
                borderColor="border"
                display="flex"
                alignItems="center"
                justifyContent="center"
                color="fg.muted"
                flexShrink={0}
            >
                <LuImageOff size={20} />
            </Box>
        );
    }

    return (
        <>
            <HStack gap={2} flexWrap="wrap">
                {photos.map((photo, i) => (
                    <Image
                        key={photo.id}
                        src={photo.thumb_url}
                        alt={alt}
                        w="90px"
                        aspectRatio={2 / 3}
                        objectFit="cover"
                        borderRadius="md"
                        cursor="zoom-in"
                        borderWidth="1px"
                        borderColor="border"
                        onClick={() => setLightboxIndex(i)}
                    />
                ))}
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
 * Одна партия: количество (стандартный контрол [−] N [+]) и кнопка «В корзину».
 *
 * Уценка — отдельная строка корзины на партию; добавляем адресно по defect_id,
 * серверный лимит совпадает с available. Только для активных пользователей
 * (данные с ценами приходят только им).
 */
function DefectAddToCart({ defect }) {
    const { auth } = usePage().props;
    const canBuy = auth?.user && (auth.user.status === 'active' || auth.user.is_staff);
    const [qty, setQty] = useState(1);
    const [loading, setLoading] = useState(false);

    if (!canBuy) {
        return null;
    }

    const max = defect.available_quantity;
    const disabled = loading || max <= 0;

    const add = async () => {
        const amount = Math.max(1, Math.min(Number(qty) || 1, max));
        setLoading(true);
        try {
            const { data } = await window.axios.post('/api/cart/add-defect', {
                defect_id: defect.id,
                quantity: amount,
            });

            if (data.status === 'success') {
                toaster.create({ description: 'Уценённый товар добавлен в корзину.', type: 'success' });
                window.dispatchEvent(new CustomEvent('cart:changed'));
            } else {
                toaster.create({ description: data.message || 'Позиция недоступна.', type: 'warning' });
            }
        } catch (error) {
            const message = error?.response?.data?.message || 'Не удалось добавить в корзину.';
            toaster.create({ description: message, type: 'error' });
        } finally {
            setLoading(false);
        }
    };

    return (
        <HStack gap={2}>
            <QuantityControl
                value={qty}
                onChange={setQty}
                min={1}
                max={max}
                size="sm"
                disabled={disabled}
            />
            <Button size="sm" onClick={add} loading={loading} disabled={disabled}>
                В корзину
            </Button>
        </HStack>
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
                            {defect.available_quantity > 0 && <DefectAddToCart defect={defect} />}
                        </VStack>
                    </SimpleGrid>
                </Box>
            ))}
        </Stack>
    );
}
