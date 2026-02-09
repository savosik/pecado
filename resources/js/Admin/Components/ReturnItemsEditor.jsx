import React, { useState, useEffect } from "react";
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
    createListCollection,
} from "@chakra-ui/react";
import axios from "axios";
import { LuPlus, LuTrash2, LuMinus, LuPackage, LuMessageSquare, LuX, LuCheck } from "react-icons/lu";
import { Select } from "@/components/ui/select";
import { Field } from "@/components/ui/field";
import { ProductSelector } from "@/Admin/Components/ProductSelector";

/**
 * ReturnItemsEditor - редактор позиций возврата
 * 
 * Workflow (inline-форма): Товар → Заказ → Цена (автозаполнение) → Причина
 */
const ReturnItemsEditor = ({ items = [], onChange, reasons = [], userId }) => {
    const [localItems, setLocalItems] = useState(items);
    const [isAdding, setIsAdding] = useState(false);

    // Синхронизация с внешними items
    useEffect(() => {
        setLocalItems(items);
    }, [items]);

    // Обновление родителя при изменении локальных items
    useEffect(() => {
        const itemsChanged = JSON.stringify(localItems) !== JSON.stringify(items);
        if (itemsChanged && onChange) {
            onChange(localItems);
        }
    }, [localItems]);

    const handleAddItem = (itemData) => {
        setLocalItems([...localItems, itemData]);
        setIsAdding(false);
    };

    const handleRemoveItem = (index) => {
        const newItems = localItems.filter((_, i) => i !== index);
        setLocalItems(newItems);
    };

    const handleUpdateQuantity = (index, delta) => {
        const newItems = [...localItems];
        const newQty = Math.max(1, newItems[index].quantity + delta);
        newItems[index].quantity = newQty;
        newItems[index].subtotal = newQty * newItems[index].price;
        setLocalItems(newItems);
    };

    const totalAmount = localItems.reduce((sum, item) => sum + (parseFloat(item.subtotal) || 0), 0);

    const getReasonLabel = (reasonValue) => {
        const reason = reasons.find(r => r.value === reasonValue);
        return reason?.label || reasonValue;
    };

    return (
        <VStack align="stretch" gap={4}>
            {/* Кнопка добавления или inline-форма */}
            {!isAdding ? (
                <HStack justify="space-between">
                    <Text fontSize="sm" color="fg.muted">
                        {userId ? "Поиск товаров из заказов пользователя" : "Сначала выберите пользователя"}
                    </Text>
                    <Button
                        colorPalette="blue"
                        onClick={() => setIsAdding(true)}
                        disabled={!userId}
                    >
                        <LuPlus /> Добавить товар
                    </Button>
                </HStack>
            ) : (
                <AddItemForm
                    onSave={handleAddItem}
                    onCancel={() => setIsAdding(false)}
                    reasons={reasons}
                    userId={userId}
                />
            )}

            {/* Таблица позиций */}
            {localItems.length > 0 && (
                <Card.Root>
                    <Card.Header>
                        <HStack justify="space-between">
                            <Heading size="sm">Позиции возврата ({localItems.length})</Heading>
                        </HStack>
                    </Card.Header>
                    <Card.Body p={0}>
                        <Table.Root size="sm">
                            <Table.Header>
                                <Table.Row>
                                    <Table.ColumnHeader width="60px">Фото</Table.ColumnHeader>
                                    <Table.ColumnHeader>Товар</Table.ColumnHeader>
                                    <Table.ColumnHeader>Заказ</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Цена</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="center" width="120px">Кол-во</Table.ColumnHeader>
                                    <Table.ColumnHeader>Причина</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Сумма</Table.ColumnHeader>
                                    <Table.ColumnHeader width="60px"></Table.ColumnHeader>
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {localItems.map((item, index) => (
                                    <Table.Row key={index}>
                                        <Table.Cell>
                                            {item.product?.image_url ? (
                                                <Image
                                                    src={item.product.image_url}
                                                    alt={item.product?.name}
                                                    boxSize="40px"
                                                    objectFit="cover"
                                                    borderRadius="md"
                                                />
                                            ) : (
                                                <Box
                                                    boxSize="40px"
                                                    bg="bg.muted"
                                                    borderRadius="md"
                                                    display="flex"
                                                    alignItems="center"
                                                    justifyContent="center"
                                                >
                                                    <LuPackage />
                                                </Box>
                                            )}
                                        </Table.Cell>
                                        <Table.Cell>
                                            <VStack align="start" gap={0}>
                                                <Text fontWeight="medium" lineClamp={1}>
                                                    {item.product?.name || "Товар"}
                                                </Text>
                                                {item.product?.sku && (
                                                    <Text fontSize="xs" color="fg.muted">
                                                        SKU: {item.product.sku}
                                                    </Text>
                                                )}
                                            </VStack>
                                        </Table.Cell>
                                        <Table.Cell>
                                            {item.order_id ? (
                                                <Badge colorPalette="blue">#{item.order_id}</Badge>
                                            ) : (
                                                <Text color="fg.muted">—</Text>
                                            )}
                                        </Table.Cell>
                                        <Table.Cell textAlign="right">
                                            {parseFloat(item.price).toFixed(2)} ₽
                                        </Table.Cell>
                                        <Table.Cell>
                                            <HStack justify="center" gap={1}>
                                                <IconButton
                                                    size="xs"
                                                    variant="ghost"
                                                    onClick={() => handleUpdateQuantity(index, -1)}
                                                    disabled={item.quantity <= 1}
                                                >
                                                    <LuMinus />
                                                </IconButton>
                                                <Text fontWeight="medium" minW="30px" textAlign="center">
                                                    {item.quantity}
                                                </Text>
                                                <IconButton
                                                    size="xs"
                                                    variant="ghost"
                                                    onClick={() => handleUpdateQuantity(index, 1)}
                                                >
                                                    <LuPlus />
                                                </IconButton>
                                            </HStack>
                                        </Table.Cell>
                                        <Table.Cell>
                                            <VStack align="start" gap={0}>
                                                <Badge colorPalette="orange" size="sm">
                                                    {getReasonLabel(item.reason)}
                                                </Badge>
                                                {item.reason_comment && (
                                                    <Text fontSize="xs" color="fg.muted" lineClamp={1}>
                                                        <LuMessageSquare style={{ display: "inline" }} /> {item.reason_comment}
                                                    </Text>
                                                )}
                                            </VStack>
                                        </Table.Cell>
                                        <Table.Cell textAlign="right" fontWeight="medium">
                                            {parseFloat(item.subtotal).toFixed(2)} ₽
                                        </Table.Cell>
                                        <Table.Cell>
                                            <IconButton
                                                size="sm"
                                                variant="ghost"
                                                colorPalette="red"
                                                onClick={() => handleRemoveItem(index)}
                                            >
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
                            <Text fontSize="xl" fontWeight="bold" color="blue.600">
                                {totalAmount.toFixed(2)} ₽
                            </Text>
                        </HStack>
                    </Card.Footer>
                </Card.Root>
            )}

            {/* Пустое состояние */}
            {localItems.length === 0 && !isAdding && (
                <Card.Root>
                    <Card.Body>
                        <VStack py={8} gap={4}>
                            <LuPackage size={48} style={{ opacity: 0.3 }} />
                            <Text color="fg.muted">Нет позиций в возврате</Text>
                            <Text fontSize="sm" color="fg.muted">
                                Нажмите "Добавить товар" для добавления позиции
                            </Text>
                        </VStack>
                    </Card.Body>
                </Card.Root>
            )}
        </VStack>
    );
};

/**
 * Inline-форма добавления позиции (пошаговая)
 */
const AddItemForm = ({ onSave, onCancel, reasons, userId }) => {
    const [step, setStep] = useState(1);
    const [selectedProduct, setSelectedProduct] = useState(null);
    const [selectedOrder, setSelectedOrder] = useState(null);
    const [quantity, setQuantity] = useState(1);
    const [reason, setReason] = useState(reasons?.[0]?.value || "");
    const [reasonComment, setReasonComment] = useState("");
    const [price, setPrice] = useState(0);

    // Заказы
    const [orders, setOrders] = useState([]);
    const [ordersLoading, setOrdersLoading] = useState(false);

    const reasonsCollection = createListCollection({
        items: reasons?.map(r => ({ label: r.label, value: r.value })) || [],
    });

    const handleSelectProduct = async (product) => {
        setSelectedProduct(product);
        setStep(2);

        // Загружаем заказы для этого товара
        setOrdersLoading(true);
        try {
            const response = await axios.get(route("admin.returns.product-orders"), {
                params: { product_id: product.id, user_id: userId },
            });
            setOrders(response.data);
        } catch (error) {
            console.error("Orders fetch error:", error);
        } finally {
            setOrdersLoading(false);
        }
    };

    const handleSelectOrder = (order) => {
        setSelectedOrder(order);
        setPrice(parseFloat(order.price));
        setStep(3);
    };

    const handleSave = () => {
        if (!selectedProduct || !selectedOrder || !reason) {
            return;
        }

        onSave({
            product_id: selectedProduct.id,
            product: selectedProduct,
            order_id: selectedOrder.id,
            quantity,
            price,
            subtotal: quantity * price,
            reason,
            reason_comment: reasonComment,
        });
    };

    return (
        <Card.Root variant="elevated" shadow="md" borderRadius="lg" overflow="hidden">
            <Box bg="gray.50" px={6} py={4} borderBottomWidth="1px" borderColor="gray.100">
                <HStack justify="space-between" mb={4}>
                    <Heading size="sm" color="gray.700">Добавление позиции</Heading>
                    <IconButton size="xs" variant="ghost" colorPalette="gray" onClick={onCancel}>
                        <LuX />
                    </IconButton>
                </HStack>
                {/* Улучшенный Stepper */}
                <HStack w="full" px={2}>
                    <StepIndicator step={1} currentStep={step} label="Товар" isMobile={false} />
                    <Box flex={1} h="2px" bg={step >= 2 ? "blue.500" : "gray.200"} mx={2} />
                    <StepIndicator step={2} currentStep={step} label="Заказ" isMobile={false} />
                    <Box flex={1} h="2px" bg={step >= 3 ? "blue.500" : "gray.200"} mx={2} />
                    <StepIndicator step={3} currentStep={step} label="Детали" isMobile={false} />
                </HStack>
            </Box>

            <Card.Body p={6}>
                {/* Шаг 1: Поиск товара */}
                {step === 1 && (
                    <VStack align="stretch" gap={4} py={4}>
                        <Field label="Поиск товара" helperText="Введите название, артикул (SKU) или отсканируйте штрихкод">
                            <ProductSelector
                                mode="search"
                                onSelect={handleSelectProduct}
                                searchRoute="admin.returns.search-products"
                                searchParams={{ user_id: userId }}
                            />
                        </Field>
                    </VStack>
                )}

                {/* Шаг 2: Выбор заказа */}
                {step === 2 && (
                    <VStack align="stretch" gap={6}>
                        {/* Selected Product Summary */}
                        <HStack p={4} bg="blue.50/50" borderRadius="lg" borderWidth="1px" borderColor="blue.100">
                            <Image
                                src={selectedProduct?.image_url}
                                boxSize="56px"
                                objectFit="cover"
                                borderRadius="md"
                                fallbackSrc="https://via.placeholder.com/56"
                            />
                            <VStack align="start" gap={0} flex={1}>
                                <Text fontWeight="bold" color="gray.800">{selectedProduct?.name}</Text>
                                <Text fontSize="sm" color="gray.500">{selectedProduct?.sku}</Text>
                            </VStack>
                            <Button size="sm" variant="ghost" colorPalette="blue" onClick={() => { setSelectedProduct(null); setStep(1); }}>
                                Изменить товар
                            </Button>
                        </HStack>

                        <Field label="В каком заказе был куплен товар?">
                            {ordersLoading ? (
                                <Box p={8} textAlign="center">
                                    <Spinner size="md" color="blue.500" />
                                    <Text fontSize="sm" color="gray.500" mt={3}>Поиск заказов...</Text>
                                </Box>
                            ) : orders.length > 0 ? (
                                <VStack align="stretch" gap={3}>
                                    {orders.map((order) => (
                                        <Box
                                            key={order.id}
                                            p={4}
                                            borderWidth="1px"
                                            borderRadius="lg"
                                            cursor="pointer"
                                            bg="white"
                                            transition="all 0.2s"
                                            _hover={{ borderColor: "blue.400", shadow: "sm", transform: "translateY(-1px)" }}
                                            onClick={() => handleSelectOrder(order)}
                                        >
                                            <HStack justify="space-between">
                                                <VStack align="start" gap={1}>
                                                    <HStack>
                                                        <Badge colorPalette="purple" variant="solid">#{order.id}</Badge>
                                                        <Text fontSize="sm" color="gray.500">от {order.created_at}</Text>
                                                    </HStack>
                                                    <Text fontSize="sm" fontWeight="medium">
                                                        Куплено: <Text as="span" fontWeight="bold">{order.quantity} шт.</Text> по цене <Text as="span" fontWeight="bold">{parseFloat(order.price).toFixed(2)} ₽</Text>
                                                    </Text>
                                                </VStack>
                                                <LuChevronRight size={20} color="gray" />
                                            </HStack>
                                        </Box>
                                    ))}
                                </VStack>
                            ) : (
                                <Box p={6} textAlign="center" bg="orange.50/50" borderRadius="lg" borderWidth="1px" borderColor="orange.200">
                                    <LuPackage size={32} style={{ margin: "0 auto", opacity: 0.5, marginBottom: 8 }} />
                                    <Text fontWeight="medium" color="orange.800">Заказы не найдены</Text>
                                    <Text fontSize="sm" color="orange.600">Пользователь не покупал этот товар ранее</Text>
                                </Box>
                            )}
                        </Field>
                    </VStack>
                )}

                {/* Шаг 3: Детали */}
                {step === 3 && (
                    <VStack align="stretch" gap={6}>
                        <HStack p={4} bg="gray.50" borderRadius="lg" borderWidth="1px" borderColor="gray.100">
                            <Image src={selectedProduct?.image_url} boxSize="48px" objectFit="cover" borderRadius="md" fallbackSrc="https://via.placeholder.com/48" />
                            <Box flex={1}>
                                <Text fontWeight="medium" lineClamp={1}>{selectedProduct?.name}</Text>
                                <HStack gap={2} mt={1}>
                                    <Badge size="xs" variant="outline">Заказ #{selectedOrder?.id}</Badge>
                                    <Text fontSize="xs" color="gray.500">Цена: {price.toFixed(2)} ₽</Text>
                                </HStack>
                            </Box>
                            <Button size="xs" variant="ghost" onClick={() => setStep(2)}>Сменить заказ</Button>
                        </HStack>

                        <HStack align="start" gap={6} wrap="wrap">
                            <Field label="Количество" width="auto">
                                <HStack>
                                    <IconButton
                                        variant="outline"
                                        size="md"
                                        onClick={() => setQuantity(Math.max(1, quantity - 1))}
                                        disabled={quantity <= 1}
                                    >
                                        <LuMinus />
                                    </IconButton>
                                    <Input
                                        size="md"
                                        type="number"
                                        value={quantity}
                                        onChange={(e) => setQuantity(Math.max(1, parseInt(e.target.value) || 1))}
                                        textAlign="center"
                                        width="80px"
                                        min={1}
                                        fontWeight="bold"
                                    />
                                    <IconButton
                                        variant="outline"
                                        size="md"
                                        onClick={() => setQuantity(quantity + 1)}
                                    >
                                        <LuPlus />
                                    </IconButton>
                                </HStack>
                            </Field>

                            <Box flex={1}>
                                <Field label="Причина возврата" required>
                                    <Select.Root
                                        size="md"
                                        collection={reasonsCollection}
                                        value={reason ? [reason] : []}
                                        onValueChange={(e) => setReason(e.value[0])}
                                    >
                                        <Select.Trigger>
                                            <Select.ValueText placeholder="Укажите причину..." />
                                        </Select.Trigger>
                                        <Select.Content>
                                            {reasonsCollection.items.map((item) => (
                                                <Select.Item key={item.value} item={item}>
                                                    {item.label}
                                                </Select.Item>
                                            ))}
                                        </Select.Content>
                                    </Select.Root>
                                </Field>
                            </Box>
                        </HStack>

                        <Field label="Комментарий">
                            <Textarea
                                placeholder="Опишите дефект или причину возврата..."
                                value={reasonComment}
                                onChange={(e) => setReasonComment(e.target.value)}
                                rows={3}
                                resize="none"
                            />
                        </Field>

                        <HStack justify="flex-end" pt={2}>
                            <VStack align="end" gap={0}>
                                <Text fontSize="sm" color="gray.500">Итоговая сумма</Text>
                                <Text fontSize="2xl" fontWeight="bold" color="blue.600">
                                    {(quantity * price).toFixed(2)} ₽
                                </Text>
                            </VStack>
                        </HStack>
                    </VStack>
                )}
            </Card.Body>

            <Card.Footer bg="gray.50" borderTopWidth="1px" borderColor="gray.100" py={4}>
                <HStack justify="flex-end" w="full" gap={3}>
                    <Button variant="subtle" colorPalette="gray" onClick={onCancel} size="md">
                        Отмена
                    </Button>
                    {step === 3 && (
                        <Button
                            colorPalette="blue"
                            size="md"
                            onClick={handleSave}
                            disabled={!selectedProduct || !selectedOrder || !reason}
                        >
                            <LuCheck /> Добавить позицию
                        </Button>
                    )}
                </HStack>
            </Card.Footer>
        </Card.Root>
    );
};

/**
 * Индикатор шага
 */
const StepIndicator = ({ step, currentStep, label }) => {
    const isActive = currentStep === step;
    const isCompleted = currentStep > step;
    const isPending = currentStep < step;

    return (
        <HStack gap={2}>
            <Box
                w="28px"
                h="28px"
                borderRadius="full"
                bg={isCompleted ? "green.500" : isActive ? "blue.600" : "white"}
                borderColor={isCompleted ? "green.500" : isActive ? "blue.600" : "gray.300"}
                borderWidth={isPending ? "2px" : "0px"}
                color={isCompleted || isActive ? "white" : "gray.400"}
                display="flex"
                alignItems="center"
                justifyContent="center"
                fontSize="sm"
                fontWeight="bold"
                shadow={isActive ? "sm" : "none"}
            >
                {isCompleted ? <LuCheck size={16} /> : step}
            </Box>
            <Text
                fontSize="sm"
                fontWeight={isActive ? "bold" : "medium"}
                color={isActive ? "gray.800" : "gray.500"}
            >
                {label}
            </Text>
        </HStack>
    );
};

export default ReturnItemsEditor;
