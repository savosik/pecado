import { useState } from 'react';
import { Badge, Box, HStack, Image, SimpleGrid, Stack, Text, VStack } from '@chakra-ui/react';
import { LuImageOff } from 'react-icons/lu';
import ImageLightbox from '@/components/common/ImageLightbox';
import DefectQuantityControl from './DefectQuantityControl';

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
                            <DefectQuantityControl defect={defect} />
                        </VStack>
                    </SimpleGrid>
                </Box>
            ))}
        </Stack>
    );
}
