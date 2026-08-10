import { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { Badge, Box, HStack, Input, Text, VStack } from '@chakra-ui/react';
import { LuSearch, LuX } from 'react-icons/lu';
import { toaster } from '@/components/ui/toaster';
import { formatMoney, formatWeight } from './deliveryFormat';

/**
 * Компактный мультивыбор реализаций: поле поиска, выпадающий список, чипы.
 *
 * Весь разбор «что вообще надо везти» живёт в разделе «Реализации к доставке» —
 * там фильтры, группировки и скрытие. Форме создания отправки нужен только способ
 * дособрать состав, не уходя со страницы.
 */
export function ShipmentSelector({ selected, onChange, exceptDeliveryId }) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState([]);
    const [open, setOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const boxRef = useRef(null);

    // Клиент задаётся первой выбранной реализацией: в одну отправку можно
    // включить только реализации одного клиента.
    const lockedUserId = selected[0]?.user_id ?? null;

    useEffect(() => {
        if (!open) {
            return undefined;
        }

        let cancelled = false;
        setLoading(true);

        const timer = setTimeout(() => {
            axios.get('/wms/deliveries/search-shipments', {
                params: {
                    search: query,
                    user_id: lockedUserId || undefined,
                    except_delivery_id: exceptDeliveryId || undefined,
                },
            })
                .then(({ data }) => {
                    if (!cancelled) {
                        setResults(data.shipments);
                    }
                })
                .catch(() => {
                    if (!cancelled) {
                        toaster.create({ title: 'Не удалось загрузить реализации', type: 'error' });
                    }
                })
                .finally(() => {
                    if (!cancelled) {
                        setLoading(false);
                    }
                });
        }, 300);

        return () => {
            cancelled = true;
            clearTimeout(timer);
        };
    }, [query, open, lockedUserId, exceptDeliveryId]);

    // Клик мимо закрывает список — иначе он перекрывает форму под собой.
    useEffect(() => {
        const onClickOutside = (event) => {
            if (boxRef.current && !boxRef.current.contains(event.target)) {
                setOpen(false);
            }
        };

        document.addEventListener('mousedown', onClickOutside);

        return () => document.removeEventListener('mousedown', onClickOutside);
    }, []);

    const add = (shipment) => {
        if (selected.some((item) => item.id === shipment.id)) {
            return;
        }

        onChange([...selected, shipment]);
        setQuery('');
    };

    const remove = (id) => onChange(selected.filter((item) => item.id !== id));

    const available = results.filter((item) => !selected.some((chosen) => chosen.id === item.id));

    return (
        <VStack align="stretch" gap={2} ref={boxRef} position="relative">
            {selected.length > 0 && (
                <HStack gap={2} flexWrap="wrap">
                    {selected.map((shipment) => (
                        <HStack
                            key={shipment.id}
                            gap={2}
                            px={2}
                            py={1}
                            borderWidth="1px"
                            borderColor="border"
                            borderRadius="md"
                            bg="bg.subtle"
                        >
                            <VStack align="start" gap={0}>
                                <Text fontSize="sm" fontWeight="medium">{shipment.number}</Text>
                                <Text fontSize="xs" color="fg.muted">
                                    {shipment.date_label} · {formatWeight(shipment.weight)} · {formatMoney(shipment.amount)}
                                </Text>
                            </VStack>
                            <Box
                                cursor="pointer"
                                color="fg.muted"
                                _hover={{ color: 'red.500' }}
                                onClick={() => remove(shipment.id)}
                            >
                                <LuX size={14} />
                            </Box>
                        </HStack>
                    ))}
                </HStack>
            )}

            <HStack gap={2}>
                <Box color="fg.muted"><LuSearch size={16} /></Box>
                <Input
                    size="sm"
                    value={query}
                    placeholder={selected.length > 0
                        ? 'Добавить ещё реализацию этого клиента...'
                        : 'Номер реализации или клиент...'}
                    onFocus={() => setOpen(true)}
                    onChange={(event) => {
                        setQuery(event.target.value);
                        setOpen(true);
                    }}
                />
            </HStack>

            {open && (
                <Box
                    position="absolute"
                    top="100%"
                    left={0}
                    right={0}
                    zIndex={10}
                    mt={1}
                    maxH="320px"
                    overflowY="auto"
                    bg="bg.panel"
                    borderWidth="1px"
                    borderColor="border"
                    borderRadius="md"
                    boxShadow="md"
                >
                    {loading ? (
                        <Text fontSize="sm" color="fg.muted" p={3}>Ищем...</Text>
                    ) : available.length === 0 ? (
                        <Text fontSize="sm" color="fg.muted" p={3}>
                            {lockedUserId
                                ? 'Других свободных реализаций этого клиента нет.'
                                : 'Ничего не найдено.'}
                        </Text>
                    ) : (
                        available.map((shipment) => (
                            <HStack
                                key={shipment.id}
                                align="start"
                                gap={3}
                                px={3}
                                py={2}
                                cursor="pointer"
                                _hover={{ bg: 'bg.subtle' }}
                                borderBottomWidth="1px"
                                borderColor="border"
                                onClick={() => add(shipment)}
                            >
                                <VStack align="start" gap={0} flex="1" minW={0}>
                                    <HStack gap={2} flexWrap="wrap">
                                        <Text fontSize="sm" fontWeight="medium">{shipment.number}</Text>
                                        <Text fontSize="xs" color="fg.muted">{shipment.date_label}</Text>
                                        {shipment.goods_issue && (
                                            <Badge size="sm" colorPalette={shipment.goods_issue.status_color}>
                                                {shipment.goods_issue.status_label}
                                            </Badge>
                                        )}
                                    </HStack>
                                    <Text fontSize="xs" color="fg.muted" lineClamp={1}>
                                        {shipment.client || 'Без клиента'}
                                        {shipment.delivery_address && ` · ${shipment.delivery_address}`}
                                    </Text>
                                </VStack>
                                <VStack align="end" gap={0} flexShrink={0}>
                                    <Text fontSize="sm">{formatMoney(shipment.amount)}</Text>
                                    <Text fontSize="xs" color="fg.muted">{formatWeight(shipment.weight)}</Text>
                                </VStack>
                            </HStack>
                        ))
                    )}
                </Box>
            )}
        </VStack>
    );
}
