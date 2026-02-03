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

    // Добавление товара в позиции
    const handleAddProduct = async (product) => {
        const existingItemIndex = value.findIndex(item => item.product_id === product.id);

        if (existingItemIndex !== -1) {
            const newItems = [...value];
            const item = { ...newItems[existingItemIndex] };

            item.quantity = (Number(item.quantity) || 0) + 1;
            item.subtotal = item.price * item.quantity;

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

            const newItem = {
                product_id: product.id,
                name: product.name,
                sku: product.sku,
                image_url: product.image_url,
                brand_name: product.brand_name,
                price: priceData.price || 0,
                quantity: 1,
                subtotal: priceData.price || 0,
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
                price: product.price || 0, // from ProductSelector
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
        const qty = parseInt(quantity) || 1;
        newItems[index].quantity = qty;
        newItems[index].subtotal = newItems[index].price * qty;
        onChange(newItems);
    };

    // Обновление price
    const handleUpdatePrice = (index, price) => {
        const newItems = [...value];
        const p = parseFloat(price) || 0;
        newItems[index].price = p;
        newItems[index].subtotal = p * newItems[index].quantity;
        onChange(newItems);
    };

    // Подсчёт общей суммы
    const totalAmount = value.reduce((sum, item) => sum + (item.subtotal || 0), 0);

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
                        <Table.Root size="sm">
                            <Table.Header>
                                <Table.Row>
                                    <Table.ColumnHeader width="60px">Фото</Table.ColumnHeader>
                                    <Table.ColumnHeader>Товар</Table.ColumnHeader>
                                    <Table.ColumnHeader width="150px">
                                        Цена ({currencyCode}) <Text as="span" color="red.500">*</Text>
                                    </Table.ColumnHeader>
                                    <Table.ColumnHeader width="120px">
                                        Кол-во <Text as="span" color="red.500">*</Text>
                                    </Table.ColumnHeader>
                                    <Table.ColumnHeader width="150px">Сумма</Table.ColumnHeader>
                                    <Table.ColumnHeader width="80px"></Table.ColumnHeader>
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {value.map((item, index) => {
                                    // Check for nested errors: items.0.price, items.0.quantity
                                    const priceError = errors[`items.${index}.price`];
                                    const quantityError = errors[`items.${index}.quantity`];

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
                                                        value={item.price}
                                                        onChange={(e) => handleUpdatePrice(index, e.target.value)}
                                                        size="sm"
                                                        invalid={!!priceError}
                                                    />
                                                    {priceError && (
                                                        <Text fontSize="xs" color="red.500" truncate maxW="150px" title={priceError}>
                                                            {priceError}
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
                                                        <Text fontSize="xs" color="red.500" truncate maxW="120px" title={quantityError}>
                                                            {quantityError}
                                                        </Text>
                                                    )}
                                                </VStack>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Text fontWeight="medium">{item.subtotal?.toFixed(2)} </Text>
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
                    </Card.Body>
                    <Card.Footer>
                        <HStack justify="space-between" width="100%">
                            <Text fontSize="lg" fontWeight="bold">Итого:</Text>
                            <Text fontSize="xl" fontWeight="bold" colorPalette="blue">
                                {totalAmount.toFixed(2)} {currencyCode}
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
