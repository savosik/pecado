import { useState } from 'react';
import { Badge, Box, Flex, HStack, Image, Text } from '@chakra-ui/react';
import { LuBadgePercent, LuImageOff, LuMinus, LuPlus, LuTrash2 } from 'react-icons/lu';
import { usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { toaster } from '@/components/ui/toaster';

const fmt = (value) => new Intl.NumberFormat('ru-RU').format(Number(value || 0));

/**
 * Одна строка уценки в корзине: фото, дефект, цена, количество (по остатку партии), удаление.
 *
 * Количество меняем через общий PATCH /api/cart/items/{id} — контроллер сам
 * направляет defect-строку в setDefectQuantity. Удаление — общий DELETE.
 */
function DefectRow({ item, currencySymbol, onChanged }) {
    const [qty, setQty] = useState(Number(item.quantity || 0));
    const [busy, setBusy] = useState(false);

    const max = Number(item.max_total ?? item.available_quantity ?? qty);
    const price = Number(item.price ?? 0);

    const sync = async (nextQty) => {
        setBusy(true);
        try {
            if (nextQty <= 0) {
                await window.axios.delete(`/api/cart/items/${item.id}`);
                toaster.create({ description: 'Позиция удалена.', type: 'success' });
            } else {
                await window.axios.patch(`/api/cart/items/${item.id}`, { quantity: nextQty });
            }
            window.dispatchEvent(new CustomEvent('cart:changed'));
            onChanged?.();
        } catch (error) {
            const message = error?.response?.data?.message || 'Не удалось обновить позицию.';
            toaster.create({ description: message, type: 'error' });
            setQty(Number(item.quantity || 0));
        } finally {
            setBusy(false);
        }
    };

    const change = (next) => {
        const clamped = Math.max(0, Math.min(next, max));
        setQty(clamped);
        sync(clamped);
    };

    return (
        <Flex
            align="center"
            gap="3"
            borderWidth="1px"
            borderColor="border"
            rounded="md"
            p="3"
            opacity={item.is_unavailable ? 0.6 : 1}
        >
            {item.defect?.photo_url ? (
                <Image src={item.defect.photo_url} alt="" boxSize="56px" objectFit="cover" rounded="md" flexShrink={0} />
            ) : (
                <Flex boxSize="56px" align="center" justify="center" color="fg.muted" borderWidth="1px" borderColor="border" rounded="md" flexShrink={0}>
                    <LuImageOff size={18} />
                </Flex>
            )}

            <Box flex="1" minW="0">
                <Text fontWeight="medium" lineClamp={1}>{item.product?.name || 'Товар'}</Text>
                <Text fontSize="xs" color="fg.muted">{item.product?.sku || '—'}</Text>
                {item.defect?.description && (
                    <Text fontSize="xs" color="purple.500" mt="0.5" lineClamp={2}>
                        Дефект: {item.defect.description}
                    </Text>
                )}
                {item.is_unavailable && (
                    <Badge colorPalette="red" variant="subtle" mt="1">Недоступно</Badge>
                )}
            </Box>

            <HStack gap="1" flexShrink={0}>
                <Button size="xs" variant="outline" onClick={() => change(qty - 1)} disabled={busy || qty <= 0}>
                    <LuMinus />
                </Button>
                <Text minW="28px" textAlign="center">{qty}</Text>
                <Button size="xs" variant="outline" onClick={() => change(qty + 1)} disabled={busy || qty >= max}>
                    <LuPlus />
                </Button>
            </HStack>

            <Box minW="90px" textAlign="right" flexShrink={0}>
                <Text fontWeight="600">{fmt(price * qty)} {currencySymbol}</Text>
                <Text fontSize="xs" color="fg.muted">{fmt(price)} {currencySymbol}/шт</Text>
            </Box>

            <Button size="xs" variant="ghost" colorPalette="red" onClick={() => change(0)} disabled={busy} flexShrink={0}>
                <LuTrash2 />
            </Button>
        </Flex>
    );
}

/**
 * Секция «Товары с уценкой» в корзине — отдельный поток от обычных товаров.
 */
export default function CartDefectSection({ items = [], onChanged }) {
    const { currency } = usePage().props;
    const currencySymbol = currency?.symbol ?? '₽';

    if (items.length === 0) {
        return null;
    }

    return (
        <Box
            borderWidth={{ base: '0', lg: '1px' }}
            borderColor="border"
            rounded={{ base: 'none', lg: 'lg' }}
            bg={{ base: 'transparent', lg: 'bg' }}
            p={{ base: '3', lg: '4' }}
        >
            <Flex align="center" gap="2" mb="3">
                <LuBadgePercent size={18} />
                <Text fontWeight="600">Товары с уценкой</Text>
                <Badge colorPalette="purple" variant="subtle">{items.length}</Badge>
            </Flex>

            <Box spaceY="2">
                {items.map((item) => (
                    <DefectRow key={item.id} item={item} currencySymbol={currencySymbol} onChanged={onChanged} />
                ))}
            </Box>
        </Box>
    );
}
