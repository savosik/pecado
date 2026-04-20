import React, { useState } from "react";
import {
    Box,
    Button,
    HStack,
    VStack,
    Input,
    IconButton,
    Text,
    Card,
    Table,
    Image,
    Badge,
} from "@chakra-ui/react";
import { LuPlus, LuTrash2, LuSearch } from "react-icons/lu";
import { Field } from "@/components/ui/field";
import { toaster } from "@/components/ui/toaster";
import { ProductSelector } from "@/Admin/Components/ProductSelector";
import axios from "axios";

/**
 * OrderItemsEditor - компонент для управления позициями заказа
 * 
 * @param {Array} value - массив позиций заказа
 * @param {Function} onChange - callback при изменении позиций
 * @param {Object} errors - объект ошибок валидации из Inertia
 * @param {Number} userId - ID выбранного пользователя (для расчета скидок)
 * @param {String} currencyCode - Код валюты заказа (для конвертации)
 */
const OrderItemsEditor = ({ value = [], onChange, errors = {}, userId, currencyCode = 'RUB' }) => {
    const [calculating, setCalculating] = useState(false);

    const fmt = (v) =>
        parseFloat(v || 0).toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    // Добавление товара в позиции
    const handleAddProduct = async (product) => {
        const existingItemIndex = value.findIndex(item => item.product_id === product.id);

        if (existingItemIndex !== -1) {
            const newItems = [...value];
            const item = { ...newItems[existingItemIndex] };

            item.quantity = (Number(item.quantity) || 0) + 1;
            const effectivePrice = Number(item.final_price) || Number(item.price) || 0;
            item.subtotal = effectivePrice * item.quantity;

            newItems[existingItemIndex] = item;
            onChange(newItems);

            toaster.create({
                description: `Количество товара "${product.name}" увеличено`,
                type: "success",
            });
            return;
        }

        setCalculating(true);
        try {
            // Запрос цены с учетом пользователя и валюты
            const response = await axios.post(route('admin.orders.calculate-price'), {
                product_id: product.id,
                user_id: userId,
                currency_code: currencyCode,
            });

            const priceData = response.data;
            const individualPrice = priceData.price || 0;
            const basePrice = product.price || individualPrice;
            const discountPercent = basePrice > 0 && individualPrice < basePrice
                ? ((basePrice - individualPrice) / basePrice * 100)
                : 0;

            const newItem = {
                product_id: product.id,
                name: product.name,
                sku: product.sku,
                image_url: product.image_url,
                brand_name: product.brand_name,
                base_price: basePrice,
                price: individualPrice,
                discount_percent: parseFloat(discountPercent.toFixed(2)),
                final_price: individualPrice,
                quantity: 1,
                subtotal: individualPrice,
            };

            onChange([...value, newItem]);
            toaster.create({
                description: "Товар добавлен",
                type: "success",
            });
        } catch (error) {
            console.error(error);
            toaster.create({
                description: "Ошибка при расчете цены",
                type: "error",
                duration: 5000,
            });
            // Fallback to base price if API fails
            const newItem = {
                product_id: product.id,
                name: product.name,
                sku: product.sku,
                image_url: product.image_url,
                brand_name: product.brand_name,
                base_price: product.price || 0,
                price: product.price || 0,
                discount_percent: 0,
                final_price: product.price || 0,
                quantity: 1,
                subtotal: product.price || 0,
            };
            onChange([...value, newItem]);
        } finally {
            setCalculating(false);
        }
    };

    // Удаление позиции
    const handleRemoveItem = (index) => {
        const newItems = value.filter((_, i) => i !== index);
        onChange(newItems);
    };

    // Обновление quantity
    const handleUpdateQuantity = (index, quantity) => {
        const newItems = [...value];
        const item = { ...newItems[index] };
        const qty = parseInt(quantity) || 1;
        item.quantity = qty;
        const effectivePrice = Number(item.final_price) || Number(item.price) || 0;
        item.subtotal = effectivePrice * qty;
        newItems[index] = item;
        onChange(newItems);
    };

    // Обновление base_price (базовая цена)
    const handleUpdateBasePrice = (index, basePrice) => {
        const newItems = [...value];
        const item = { ...newItems[index] };
        const bp = parseFloat(basePrice) || 0;
        item.base_price = bp;
        // Пересчёт скидки на основе base_price и final_price (отрицательное значение = наценка)
        if (bp > 0) {
            item.discount_percent = parseFloat(((bp - Number(item.final_price)) / bp * 100).toFixed(2));
        } else {
            item.discount_percent = 0;
        }
        newItems[index] = item;
        onChange(newItems);
    };

    // Обновление price (индивидуальная цена)
    const handleUpdatePrice = (index, price) => {
        const newItems = [...value];
        const item = { ...newItems[index] };
        const p = parseFloat(price) || 0;
        item.price = p;
        item.final_price = p;
        // Пересчёт скидки (отрицательное значение = наценка)
        const bp = Number(item.base_price) || 0;
        if (bp > 0) {
            item.discount_percent = parseFloat(((bp - p) / bp * 100).toFixed(2));
        } else {
            item.discount_percent = 0;
        }
        item.subtotal = p * (Number(item.quantity) || 1);
        newItems[index] = item;
        onChange(newItems);
    };

    // Обновление discount_percent
    const handleUpdateDiscount = (index, discountPercent) => {
        const newItems = [...value];
        const item = { ...newItems[index] };
        const dp = discountPercent === '' || discountPercent === '-' ? 0 : (parseFloat(discountPercent) || 0);
        item.discount_percent = dp;
        // Пересчёт final_price на основе base_price и скидки
        const bp = Number(item.base_price) || Number(item.price) || 0;
        const newFinalPrice = parseFloat((bp * (1 - dp / 100)).toFixed(2));
        item.price = newFinalPrice;
        item.final_price = newFinalPrice;
        item.subtotal = newFinalPrice * (Number(item.quantity) || 1);
        newItems[index] = item;
        onChange(newItems);
    };

    // Подсчёт общей суммы
    const totalAmount = value.reduce((sum, item) => sum + Number(item.subtotal || 0), 0);

    // Global error for the items array itself (e.g. required|min:1)
    const itemsError = errors.items;

    return (
        <VStack align="stretch" gap={4}>
            {/* Поиск и добавление товара */}
            <Card.Root>
                <Card.Header>
                    <Text fontWeight="semibold">Добавить товар</Text>
                </Card.Header>
                <Card.Body>
                    <ProductSelector
                        mode="search"
                        onSelect={handleAddProduct}
                    />
                </Card.Body>
            </Card.Root>

            {/* Таблица позиций */}
            {value.length > 0 ? (
                <Card.Root>
                    <Card.Header>
                        <Text fontWeight="semibold">Позиции заказа ({value.length})</Text>
                    </Card.Header>
                    <Card.Body p={0}>
                        <Box overflowX="auto">
                            <Table.Root size="sm">
                                <Table.Header>
                                    <Table.Row>
                                        <Table.ColumnHeader width="60px">Фото</Table.ColumnHeader>
                                        <Table.ColumnHeader>Товар</Table.ColumnHeader>
                                        <Table.ColumnHeader width="130px">
                                            Баз. цена ({currencyCode})
                                        </Table.ColumnHeader>
                                        <Table.ColumnHeader width="130px">
                                            Инд. цена ({currencyCode}) <Text as="span" color="red.500">*</Text>
                                        </Table.ColumnHeader>
                                        <Table.ColumnHeader width="100px">
                                            Скидка %
                                        </Table.ColumnHeader>
                                        <Table.ColumnHeader width="100px">
                                            Кол-во <Text as="span" color="red.500">*</Text>
                                        </Table.ColumnHeader>
                                        <Table.ColumnHeader width="120px" textAlign="right">Сумма</Table.ColumnHeader>
                                        <Table.ColumnHeader width="60px"></Table.ColumnHeader>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {value.map((item, index) => {
                                        // Check for nested errors: items.0.price, items.0.quantity
                                        const basePriceError = errors[`items.${index}.base_price`];
                                        const priceError = errors[`items.${index}.price`];
                                        const discountError = errors[`items.${index}.discount_percent`];
                                        const quantityError = errors[`items.${index}.quantity`];

                                        const hasDiscount = Number(item.discount_percent) !== 0;

                                        return (
                                            <Table.Row key={index}>
                                                <Table.Cell>
                                                    {item.image_url ? (
                                                        <Image src={item.image_url} boxSize="40px" objectFit="cover" borderRadius="md" alt={item.name} />
                                                    ) : (
                                                        <Box boxSize="40px" bg="gray.100" borderRadius="md" />
                                                    )}
                                                </Table.Cell>
                                                <Table.Cell>
                                                    <VStack align="start" gap={0}>
                                                        <Text fontWeight="medium">{item.name}</Text>
                                                        <Text fontSize="xs" color="fg.muted">
                                                            SKU: {item.sku || '-'} | ID: {item.product_id}
                                                        </Text>
                                                        {item.brand_name && (
                                                            <Text fontSize="xs" color="blue.500">
                                                                {item.brand_name}
                                                            </Text>
                                                        )}
                                                    </VStack>
                                                </Table.Cell>
                                                <Table.Cell>
                                                    <VStack align="start" gap={1} w="full">
                                                        <Input
                                                            type="number"
                                                            step="0.01"
                                                            value={Number(item.base_price) || 0}
                                                            onChange={(e) => handleUpdateBasePrice(index, e.target.value)}
                                                            size="sm"
                                                            invalid={!!basePriceError}
                                                        />
                                                        {basePriceError && (
                                                            <Text fontSize="xs" color="red.500" truncate maxW="130px" title={basePriceError}>
                                                                {basePriceError}
                                                            </Text>
                                                        )}
                                                    </VStack>
                                                </Table.Cell>
                                                <Table.Cell>
                                                    <VStack align="start" gap={1} w="full">
                                                        <Input
                                                            type="number"
                                                            step="0.01"
                                                            value={Number(item.price) || 0}
                                                            onChange={(e) => handleUpdatePrice(index, e.target.value)}
                                                            size="sm"
                                                            invalid={!!priceError}
                                                        />
                                                        {priceError && (
                                                            <Text fontSize="xs" color="red.500" truncate maxW="130px" title={priceError}>
                                                                {priceError}
                                                            </Text>
                                                        )}
                                                    </VStack>
                                                </Table.Cell>
                                                <Table.Cell>
                                                    <VStack align="start" gap={1} w="full">
                                                        <Input
                                                            type="number"
                                                            step="0.01"
                                                            max="100"
                                                            value={Number(item.discount_percent) || 0}
                                                            onChange={(e) => handleUpdateDiscount(index, e.target.value)}
                                                            size="sm"
                                                            invalid={!!discountError}
                                                        />
                                                        {hasDiscount && (
                                                            <Badge colorPalette={Number(item.discount_percent) < 0 ? "orange" : "green"} size="sm">
                                                                {Number(item.discount_percent) < 0
                                                                    ? `+${Math.abs(parseFloat(item.discount_percent)).toFixed(1)}%`
                                                                    : `−${parseFloat(item.discount_percent).toFixed(1)}%`
                                                                }
                                                            </Badge>
                                                        )}
                                                        {discountError && (
                                                            <Text fontSize="xs" color="red.500" truncate maxW="100px" title={discountError}>
                                                                {discountError}
                                                            </Text>
                                                        )}
                                                    </VStack>
                                                </Table.Cell>
                                                <Table.Cell>
                                                    <VStack align="start" gap={1} w="full">
                                                        <Input
                                                            type="number"
                                                            min="1"
                                                            value={item.quantity}
                                                            onChange={(e) => handleUpdateQuantity(index, e.target.value)}
                                                            size="sm"
                                                            invalid={!!quantityError}
                                                        />
                                                        {quantityError && (
                                                            <Text fontSize="xs" color="red.500" truncate maxW="100px" title={quantityError}>
                                                                {quantityError}
                                                            </Text>
                                                        )}
                                                    </VStack>
                                                </Table.Cell>
                                                <Table.Cell textAlign="right">
                                                    <Text fontWeight="medium">{fmt(item.subtotal)}</Text>
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
                                        );
                                    })}
                                </Table.Body>
                            </Table.Root>
                        </Box>
                    </Card.Body>
                    <Card.Footer>
                        <HStack justify="space-between" width="100%">
                            <Text fontSize="lg" fontWeight="bold">Итого:</Text>
                            <Text fontSize="xl" fontWeight="bold" colorPalette="blue">
                                {fmt(totalAmount)} {currencyCode}
                            </Text>
                        </HStack>
                    </Card.Footer>
                </Card.Root>
            ) : (
                <Card.Root>
                    <Card.Body textAlign="center" py={8}>
                        <Text color="fg.muted">
                            Добавьте товары в заказ через поиск выше
                        </Text>
                    </Card.Body>
                </Card.Root>
            )}

            {itemsError && (
                <Text color="red.500" fontSize="sm">{itemsError}</Text>
            )}

            {calculating && (
                <Text fontSize="sm" color="blue.500">Расчет цены...</Text>
            )}
        </VStack>
    );
};

export default OrderItemsEditor;
