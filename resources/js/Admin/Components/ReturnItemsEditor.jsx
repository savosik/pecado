import React, { useCallback, useEffect, useMemo, useRef, useState } from "react";
import {
    Box,
    Button,
    Card,
    HStack,
    VStack,
    Text,
    Image,
    Table,
    Heading,
    IconButton,
    Input,
    Badge,
    Textarea,
    Spinner,
    createListCollection,
} from "@chakra-ui/react";
import axios from "axios";
import {
    LuPlus,
    LuTrash2,
    LuPackage,
    LuMessageSquare,
    LuSearch,
    LuCheck,
    LuX,
} from "react-icons/lu";
import { Select } from "@/components/ui/select";
import { Field } from "@/components/ui/field";
import { Checkbox } from "@/components/ui/checkbox";

/**
 * ReturnItemsEditor v2 — выбор реализации, затем мультивыбор позиций к возврату.
 *
 * Публикуемые позиции хранят shipment_item_id + snapshot цены/валюты,
 * полученные из getShipmentItems.
 */
const ReturnItemsEditor = ({
    items = [],
    onChange,
    reasons = [],
    userId = null,
    searchShipmentsRoute = "admin.returns.search-shipments",
    shipmentItemsRoute = "admin.returns.shipment-items",
    accentColor = "blue",
    requireUser = true,
}) => {
    const [localItems, setLocalItems] = useState(items);
    const [isAdding, setIsAdding] = useState(false);

    useEffect(() => {
        setLocalItems(items);
    }, [items]);

    useEffect(() => {
        const changed = JSON.stringify(localItems) !== JSON.stringify(items);
        if (changed && onChange) {
            onChange(localItems);
        }
    }, [localItems]);

    const handleAddItems = (newItems) => {
        setLocalItems([...localItems, ...newItems]);
        setIsAdding(false);
    };

    const handleRemoveItem = (index) => {
        setLocalItems(localItems.filter((_, i) => i !== index));
    };

    const handleUpdateField = (index, field, value) => {
        const next = [...localItems];
        next[index] = { ...next[index], [field]: value };
        if (field === "quantity") {
            const qty = Math.max(1, Math.min(parseInt(value) || 1, next[index].available_quantity || Number.MAX_SAFE_INTEGER));
            next[index].quantity = qty;
            next[index].subtotal = +(qty * next[index].price).toFixed(2);
        }
        setLocalItems(next);
    };

    const totalAmount = localItems.reduce((sum, item) => sum + (parseFloat(item.subtotal) || 0), 0);

    const getReasonLabel = (reasonValue) => {
        const reason = reasons.find((r) => r.value === reasonValue);
        return reason?.label || reasonValue || "—";
    };

    const addDisabled = requireUser && !userId;

    return (
        <VStack align="stretch" gap={4}>
            {!isAdding ? (
                <HStack justify="space-between">
                    <Text fontSize="sm" color="fg.muted">
                        {addDisabled
                            ? "Сначала выберите пользователя"
                            : "Выберите реализацию и отметьте позиции, которые нужно вернуть"}
                    </Text>
                    <Button
                        colorPalette={accentColor}
                        onClick={() => setIsAdding(true)}
                        disabled={addDisabled}
                    >
                        <LuPlus /> Выбрать реализацию
                    </Button>
                </HStack>
            ) : (
                <AddFromShipmentForm
                    onSave={handleAddItems}
                    onCancel={() => setIsAdding(false)}
                    reasons={reasons}
                    userId={userId}
                    searchShipmentsRoute={searchShipmentsRoute}
                    shipmentItemsRoute={shipmentItemsRoute}
                    accentColor={accentColor}
                    existingShipmentItemIds={localItems.map((i) => i.shipment_item_id)}
                />
            )}

            {localItems.length > 0 && (
                <Card.Root>
                    <Card.Header>
                        <Heading size="sm">Позиции возврата ({localItems.length})</Heading>
                    </Card.Header>
                    <Card.Body p={0}>
                        <Table.Root size="sm">
                            <Table.Header>
                                <Table.Row>
                                    <Table.ColumnHeader width="60px">Фото</Table.ColumnHeader>
                                    <Table.ColumnHeader>Товар</Table.ColumnHeader>
                                    <Table.ColumnHeader>Реализация</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Цена</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="center" width="100px">Кол-во</Table.ColumnHeader>
                                    <Table.ColumnHeader>Причина</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Сумма</Table.ColumnHeader>
                                    <Table.ColumnHeader width="60px"></Table.ColumnHeader>
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {localItems.map((item, index) => (
                                    <Table.Row key={`${item.shipment_item_id}-${index}`}>
                                        <Table.Cell>
                                            {item.product?.image_url ? (
                                                <Image src={item.product.image_url} alt={item.product?.name} boxSize="40px" objectFit="cover" borderRadius="md" />
                                            ) : (
                                                <Box boxSize="40px" bg="bg.muted" borderRadius="md" display="flex" alignItems="center" justifyContent="center">
                                                    <LuPackage />
                                                </Box>
                                            )}
                                        </Table.Cell>
                                        <Table.Cell>
                                            <VStack align="start" gap={0}>
                                                <Text fontWeight="medium" lineClamp={1}>{item.product?.name || "Товар"}</Text>
                                                {item.product?.sku && (
                                                    <Text fontSize="xs" color="fg.muted">SKU: {item.product.sku}</Text>
                                                )}
                                            </VStack>
                                        </Table.Cell>
                                        <Table.Cell>
                                            {item.shipment?.number ? (
                                                <Badge colorPalette="blue">{item.shipment.number}</Badge>
                                            ) : (
                                                <Text color="fg.muted">—</Text>
                                            )}
                                        </Table.Cell>
                                        <Table.Cell textAlign="right">
                                            {parseFloat(item.price).toFixed(2)} {item.currency_code || "₽"}
                                        </Table.Cell>
                                        <Table.Cell>
                                            <Input
                                                size="xs"
                                                type="number"
                                                value={item.quantity}
                                                min={1}
                                                max={item.available_quantity || undefined}
                                                onChange={(e) => handleUpdateField(index, "quantity", e.target.value)}
                                                textAlign="center"
                                            />
                                            {item.available_quantity !== undefined && (
                                                <Text fontSize="xs" color="fg.muted" textAlign="center">
                                                    из {item.available_quantity}
                                                </Text>
                                            )}
                                        </Table.Cell>
                                        <Table.Cell>
                                            <VStack align="start" gap={0}>
                                                <Badge colorPalette="orange" size="sm">{getReasonLabel(item.reason)}</Badge>
                                                {item.reason_comment && (
                                                    <Text fontSize="xs" color="fg.muted" lineClamp={1}>
                                                        <LuMessageSquare style={{ display: "inline" }} /> {item.reason_comment}
                                                    </Text>
                                                )}
                                            </VStack>
                                        </Table.Cell>
                                        <Table.Cell textAlign="right" fontWeight="medium">
                                            {parseFloat(item.subtotal).toFixed(2)} {item.currency_code || "₽"}
                                        </Table.Cell>
                                        <Table.Cell>
                                            <IconButton size="sm" variant="ghost" colorPalette="red" onClick={() => handleRemoveItem(index)}>
                                                <LuTrash2 />
                                            </IconButton>
                                        </Table.Cell>
                                    </Table.Row>
                                ))}
                            </Table.Body>
                        </Table.Root>
                    </Card.Body>
                    <Card.Footer>
                        <HStack justify="space-between" w="full">
                            <Text fontWeight="medium">Итого:</Text>
                            <Text fontSize="xl" fontWeight="bold" color={`${accentColor}.600`}>
                                {totalAmount.toFixed(2)} ₽
                            </Text>
                        </HStack>
                    </Card.Footer>
                </Card.Root>
            )}

            {localItems.length === 0 && !isAdding && (
                <Card.Root>
                    <Card.Body>
                        <VStack py={8} gap={4}>
                            <LuPackage size={48} style={{ opacity: 0.3 }} />
                            <Text color="fg.muted">Нет позиций в возврате</Text>
                            <Text fontSize="sm" color="fg.muted">
                                Нажмите «Выбрать реализацию», чтобы добавить позиции
                            </Text>
                        </VStack>
                    </Card.Body>
                </Card.Root>
            )}
        </VStack>
    );
};

/**
 * Шаги: 1) выбрать реализацию, 2) отметить позиции + quantity/reason.
 */
const AddFromShipmentForm = ({
    onSave,
    onCancel,
    reasons,
    userId,
    searchShipmentsRoute,
    shipmentItemsRoute,
    accentColor = "blue",
    existingShipmentItemIds = [],
}) => {
    const [step, setStep] = useState(1);
    const [query, setQuery] = useState("");
    const [shipments, setShipments] = useState([]);
    const [shipmentsLoading, setShipmentsLoading] = useState(false);
    const [selectedShipment, setSelectedShipment] = useState(null);
    const [shipmentItems, setShipmentItems] = useState([]);
    const [itemsLoading, setItemsLoading] = useState(false);
    const searchDebounceRef = useRef(null);

    const reasonsCollection = useMemo(
        () =>
            createListCollection({
                items: reasons?.map((r) => ({ label: r.label, value: r.value })) || [],
            }),
        [reasons],
    );

    const loadShipments = useCallback(
        async (q) => {
            setShipmentsLoading(true);
            try {
                const response = await axios.get(route(searchShipmentsRoute), {
                    params: {
                        query: q,
                        ...(userId && userId !== "current" ? { user_id: userId } : {}),
                    },
                });
                setShipments(response.data || []);
            } catch (error) {
                console.error("Shipments fetch error:", error);
                setShipments([]);
            } finally {
                setShipmentsLoading(false);
            }
        },
        [searchShipmentsRoute, userId],
    );

    useEffect(() => {
        if (step !== 1) return;
        clearTimeout(searchDebounceRef.current);
        searchDebounceRef.current = setTimeout(() => loadShipments(query), 250);
        return () => clearTimeout(searchDebounceRef.current);
    }, [query, loadShipments, step]);

    const handleSelectShipment = async (shipment) => {
        setSelectedShipment(shipment);
        setStep(2);
        setItemsLoading(true);
        try {
            const response = await axios.get(route(shipmentItemsRoute), {
                params: { shipment_id: shipment.id },
            });
            const items = response.data?.items || [];
            const defaultReason = reasons?.[0]?.value || "";
            const prepared = items.map((it) => ({
                ...it,
                selected: existingShipmentItemIds.includes(it.shipment_item_id) ? false : false,
                quantity: it.available_quantity > 0 ? 1 : 0,
                reason: defaultReason,
                reason_comment: "",
                already_added: existingShipmentItemIds.includes(it.shipment_item_id),
            }));
            setShipmentItems(prepared);
        } catch (error) {
            console.error("Shipment items fetch error:", error);
            setShipmentItems([]);
        } finally {
            setItemsLoading(false);
        }
    };

    const toggleSelect = (index) => {
        const next = [...shipmentItems];
        if (next[index].already_added) return;
        if (next[index].available_quantity <= 0) return;
        next[index].selected = !next[index].selected;
        setShipmentItems(next);
    };

    const updateItem = (index, field, value) => {
        const next = [...shipmentItems];
        if (field === "quantity") {
            const parsed = Math.max(1, Math.min(parseInt(value) || 1, next[index].available_quantity));
            next[index].quantity = parsed;
        } else {
            next[index][field] = value;
        }
        setShipmentItems(next);
    };

    const selectedCount = shipmentItems.filter((i) => i.selected).length;

    // C-3.2: точные совпадения по номеру/erp_number — сверху, fuzzy по составу — ниже.
    const sortedShipments = useMemo(() => {
        const rank = (s) => (s.match_source === "composition" ? 1 : 0);
        return [...shipments].sort((a, b) => rank(a) - rank(b));
    }, [shipments]);

    const handleSave = () => {
        const picked = shipmentItems.filter((i) => i.selected);
        if (picked.length === 0) return;

        const shipmentInfo = {
            id: selectedShipment.id,
            uuid: selectedShipment.uuid,
            number: selectedShipment.number,
            date: selectedShipment.date,
        };

        const prepared = picked.map((it) => ({
            shipment_item_id: it.shipment_item_id,
            product: it.product,
            shipment: shipmentInfo,
            currency_code: it.currency_code,
            available_quantity: it.available_quantity,
            quantity: it.quantity,
            price: it.price,
            subtotal: +(it.price * it.quantity).toFixed(2),
            reason: it.reason,
            reason_comment: it.reason_comment,
        }));

        onSave(prepared);
    };

    return (
        <Card.Root variant="elevated" shadow="md" borderRadius="lg" overflow="hidden">
            <Box bg="gray.50" px={6} py={4} borderBottomWidth="1px" borderColor={{ base: "gray.100", _dark: "gray.700" }}>
                <HStack justify="space-between" mb={4}>
                    <Heading size="sm" color="gray.700">
                        {step === 1 ? "Выбор реализации" : `Позиции реализации ${selectedShipment?.number || ""}`}
                    </Heading>
                    <IconButton size="xs" variant="ghost" colorPalette="gray" onClick={onCancel}>
                        <LuX />
                    </IconButton>
                </HStack>
            </Box>

            <Card.Body p={6}>
                {step === 1 && (
                    <VStack align="stretch" gap={4}>
                        <Field label="Поиск по номеру, товару или артикулу">
                            <HStack>
                                <Box flex={1} position="relative">
                                    <Box position="absolute" left="3" top="50%" transform="translateY(-50%)" color="gray.400">
                                        <LuSearch />
                                    </Box>
                                    <Input
                                        value={query}
                                        onChange={(e) => setQuery(e.target.value)}
                                        placeholder="Номер реализации, товар, артикул, штрихкод…"
                                        pl="10"
                                    />
                                </Box>
                            </HStack>
                        </Field>

                        {shipmentsLoading ? (
                            <Box p={8} textAlign="center">
                                <Spinner size="md" color={`${accentColor}.500`} />
                                <Text fontSize="sm" color="gray.500" mt={3}>Загрузка...</Text>
                            </Box>
                        ) : sortedShipments.length > 0 ? (
                            <VStack align="stretch" gap={3}>
                                {sortedShipments.map((s) => (
                                    <Box
                                        key={s.id}
                                        p={4}
                                        borderWidth="1px"
                                        borderRadius="lg"
                                        cursor="pointer"
                                        bg={{ base: "white", _dark: "gray.800" }}
                                        transition="all 0.2s"
                                        _hover={{ borderColor: `${accentColor}.400`, shadow: "sm" }}
                                        onClick={() => handleSelectShipment(s)}
                                    >
                                        <HStack justify="space-between">
                                            <VStack align="start" gap={1}>
                                                <HStack flexWrap="wrap">
                                                    <Badge colorPalette="blue" variant="solid">{s.number}</Badge>
                                                    {s.date && <Text fontSize="sm" color="gray.500">от {s.date}</Text>}
                                                    {s.open_returns_count > 0 && (
                                                        <Badge colorPalette="orange" variant="subtle">
                                                            ⚠ {s.open_returns_count}{" "}
                                                            {pluralizeOpenReturns(s.open_returns_count)}
                                                        </Badge>
                                                    )}
                                                </HStack>
                                                <Text fontSize="sm" color="gray.600">
                                                    Позиций: <b>{s.items_count}</b>, сумма: <b>{parseFloat(s.total_amount).toFixed(2)} {s.currency_code || "₽"}</b>
                                                </Text>
                                                {s.match_source === "composition" && s.match_product?.name && (
                                                    <Text fontSize="xs" color={`${accentColor}.700`}>
                                                        …содержит: {s.match_product.name}
                                                        {s.match_product.sku ? ` (${s.match_product.sku})` : ""}
                                                    </Text>
                                                )}
                                                {s.user && (
                                                    <Text fontSize="xs" color="gray.500">{s.user.name} ({s.user.email})</Text>
                                                )}
                                            </VStack>
                                            <Button size="sm" variant="ghost" colorPalette={accentColor}>Выбрать</Button>
                                        </HStack>
                                    </Box>
                                ))}
                            </VStack>
                        ) : (
                            <Box p={6} textAlign="center" bg="orange.50/50" borderRadius="lg" borderWidth="1px" borderColor="orange.200">
                                <LuPackage size={32} style={{ margin: "0 auto", opacity: 0.5, marginBottom: 8 }} />
                                <Text fontWeight="medium" color="orange.800">Реализации не найдены</Text>
                                <Text fontSize="sm" color="orange.600">Нет отгрузок, удовлетворяющих условиям поиска</Text>
                            </Box>
                        )}
                    </VStack>
                )}

                {step === 2 && (
                    <VStack align="stretch" gap={4}>
                        <HStack p={4} bg={`${accentColor}.50/50`} borderRadius="lg" borderWidth="1px" borderColor={`${accentColor}.100`} justify="space-between">
                            <VStack align="start" gap={0}>
                                <Text fontWeight="bold">Реализация {selectedShipment?.number}</Text>
                                <Text fontSize="sm" color="gray.500">
                                    {selectedShipment?.date ? `от ${selectedShipment.date}` : ""} · {selectedShipment?.currency_code}
                                </Text>
                            </VStack>
                            <Button size="sm" variant="ghost" colorPalette={accentColor} onClick={() => { setSelectedShipment(null); setShipmentItems([]); setStep(1); }}>
                                Сменить реализацию
                            </Button>
                        </HStack>

                        {itemsLoading ? (
                            <Box p={8} textAlign="center">
                                <Spinner size="md" color={`${accentColor}.500`} />
                                <Text fontSize="sm" color="gray.500" mt={3}>Загрузка позиций...</Text>
                            </Box>
                        ) : shipmentItems.length === 0 ? (
                            <Box p={6} textAlign="center" color="fg.muted">
                                В этой реализации нет позиций
                            </Box>
                        ) : (
                            <Table.Root size="sm">
                                <Table.Header>
                                    <Table.Row>
                                        <Table.ColumnHeader width="40px"></Table.ColumnHeader>
                                        <Table.ColumnHeader>Товар</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="right">Цена</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="center">Доступно</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="center" width="120px">Кол-во</Table.ColumnHeader>
                                        <Table.ColumnHeader>Причина</Table.ColumnHeader>
                                        <Table.ColumnHeader>Комментарий</Table.ColumnHeader>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {shipmentItems.map((it, index) => {
                                        const disabled = it.already_added || it.available_quantity <= 0;
                                        return (
                                            <Table.Row key={it.shipment_item_id} opacity={disabled ? 0.55 : 1}>
                                                <Table.Cell>
                                                    <Checkbox
                                                        checked={!!it.selected}
                                                        disabled={disabled}
                                                        onCheckedChange={() => toggleSelect(index)}
                                                    />
                                                </Table.Cell>
                                                <Table.Cell>
                                                    <HStack>
                                                        {it.product?.image_url ? (
                                                            <Image src={it.product.image_url} boxSize="36px" objectFit="cover" borderRadius="md" />
                                                        ) : (
                                                            <Box boxSize="36px" bg="bg.muted" borderRadius="md" display="flex" alignItems="center" justifyContent="center"><LuPackage /></Box>
                                                        )}
                                                        <VStack align="start" gap={0}>
                                                            <Text fontWeight="medium" lineClamp={1}>{it.product?.name || "—"}</Text>
                                                            {it.product?.sku && <Text fontSize="xs" color="fg.muted">SKU: {it.product.sku}</Text>}
                                                            {it.already_added && <Badge size="xs" colorPalette="gray">уже добавлено</Badge>}
                                                        </VStack>
                                                    </HStack>
                                                </Table.Cell>
                                                <Table.Cell textAlign="right">{parseFloat(it.price).toFixed(2)} {it.currency_code}</Table.Cell>
                                                <Table.Cell textAlign="center">
                                                    <Text fontSize="sm">
                                                        {it.available_quantity} / {it.shipped_quantity}
                                                    </Text>
                                                </Table.Cell>
                                                <Table.Cell>
                                                    <Input
                                                        size="xs"
                                                        type="number"
                                                        min={1}
                                                        max={it.available_quantity}
                                                        value={it.quantity}
                                                        disabled={!it.selected}
                                                        onChange={(e) => updateItem(index, "quantity", e.target.value)}
                                                        textAlign="center"
                                                        width="80px"
                                                    />
                                                </Table.Cell>
                                                <Table.Cell>
                                                    <Select.Root
                                                        size="xs"
                                                        collection={reasonsCollection}
                                                        value={it.reason ? [it.reason] : []}
                                                        disabled={!it.selected}
                                                        onValueChange={(e) => updateItem(index, "reason", e.value[0])}
                                                    >
                                                        <Select.Trigger>
                                                            <Select.ValueText placeholder="Причина" />
                                                        </Select.Trigger>
                                                        <Select.Content>
                                                            {reasonsCollection.items.map((item) => (
                                                                <Select.Item key={item.value} item={item}>{item.label}</Select.Item>
                                                            ))}
                                                        </Select.Content>
                                                    </Select.Root>
                                                </Table.Cell>
                                                <Table.Cell>
                                                    <Textarea
                                                        size="xs"
                                                        rows={1}
                                                        placeholder="Опционально"
                                                        value={it.reason_comment}
                                                        disabled={!it.selected}
                                                        onChange={(e) => updateItem(index, "reason_comment", e.target.value)}
                                                    />
                                                </Table.Cell>
                                            </Table.Row>
                                        );
                                    })}
                                </Table.Body>
                            </Table.Root>
                        )}
                    </VStack>
                )}
            </Card.Body>

            <Card.Footer bg="gray.50" borderTopWidth="1px" borderColor={{ base: "gray.100", _dark: "gray.700" }} py={4}>
                <HStack justify="space-between" w="full" gap={3}>
                    <Text fontSize="sm" color="fg.muted">
                        {step === 2 && selectedCount > 0 ? `Выбрано позиций: ${selectedCount}` : ""}
                    </Text>
                    <HStack gap={3}>
                        <Button variant="subtle" colorPalette="gray" onClick={onCancel} size="md">Отмена</Button>
                        {step === 2 && (
                            <Button
                                colorPalette={accentColor}
                                size="md"
                                onClick={handleSave}
                                disabled={selectedCount === 0}
                            >
                                <LuCheck /> Добавить выбранные
                            </Button>
                        )}
                    </HStack>
                </HStack>
            </Card.Footer>
        </Card.Root>
    );
};

function pluralizeOpenReturns(n) {
    const mod10 = n % 10;
    const mod100 = n % 100;
    if (mod10 === 1 && mod100 !== 11) return "открытый возврат";
    if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) return "открытых возврата";
    return "открытых возвратов";
}

export default ReturnItemsEditor;
