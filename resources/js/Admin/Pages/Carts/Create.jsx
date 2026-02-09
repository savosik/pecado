import React, { useState, useEffect , useRef } from "react";
import {
    Box,
    Card,
    HStack,
    VStack,
    Text,
    Image,
    Table,
    Heading,
    Button,
    Badge,
    IconButton,
    Input,
    NativeSelect,
} from "@chakra-ui/react";
import axios from "axios";
import { Head, router, useForm } from "@inertiajs/react";
import { LuArrowLeft, LuPackage, LuTrash2, LuMinus, LuPlus, LuSave } from "react-icons/lu";
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader } from "@/Admin/Components/PageHeader";
import { FormField } from "@/Admin/Components/FormField";
import { ProductSelector } from "@/Admin/Components/ProductSelector";
import { EntitySelector } from "@/Admin/Components/EntitySelector";
import { toaster } from "@/components/ui/toaster";

const CartCreate = ({ currencies = [] }) => {
    const { data, setData, post, processing, errors , transform } = useForm({
        name: "",
        user_id: null,
        user: null,
        items: [],
    });

    const closeAfterSaveRef = useRef(false);

    transform((data) => ({
        ...data,
        _close: closeAfterSaveRef.current ? 1 : 0,
    }));

    const [currencyCode, setCurrencyCode] = useState("RUB");
    const [currencySymbol, setCurrencySymbol] = useState("₽");

    useEffect(() => {
        if (currencies && currencies.length > 0) {
            const currentCurrency = currencies.find(c => c.code === currencyCode);
            if (currentCurrency) {
                setCurrencySymbol(currentCurrency.symbol);
            }
        }
    }, [currencyCode, currencies]);

    const handleSubmit = (e, shouldClose = false) => {
        e.preventDefault();
        closeAfterSaveRef.current = shouldClose;

        if (!data.user_id) {
            toaster.create({
                description: "Выберите пользователя",
                type: "error",
            });
            return;
        }

        if (data.items.length === 0) {
            toaster.create({
                description: "Добавьте хотя бы один товар",
                type: "error",
            });
            return;
        }

        post(route("admin.carts.store"), {
            onSuccess: () => {
                toaster.create({
                    description: "Корзина успешно создана",
                    type: "success",
                });
            },
        });
    };

    const updateItemQuantity = (index, newQuantity) => {
        if (newQuantity < 1) return;
        const newItems = [...data.items];
        newItems[index].quantity = newQuantity;
        setData("items", newItems);
    };

    const removeItem = (index) => {
        const newItems = data.items.filter((_, i) => i !== index);
        setData("items", newItems);
    };

    const handleProductSelect = async (product) => {
        // Проверяем, есть ли уже такой товар
        const existingIndex = data.items.findIndex(item => item.product_id === product.id);

        if (existingIndex >= 0) {
            // Увеличиваем количество
            const newItems = [...data.items];
            newItems[existingIndex].quantity += 1;
            setData("items", newItems);
            toaster.create({
                description: `Количество товара "${product.name}" увеличено`,
                type: "info",
            });
        } else {
            // Рассчитываем цену
            let price = parseFloat(product.base_price || product.price || 0);

            if (data.user_id || currencyCode !== 'RUB') {
                try {
                    const response = await axios.post(route('admin.carts.calculate-price'), {
                        product_id: product.id,
                        user_id: data.user_id,
                        currency_code: currencyCode
                    });
                    price = response.data.price;
                } catch (e) {
                    console.error("Ошибка при расчете цены", e);
                }
            }

            // Добавляем новый
            setData("items", [
                ...data.items,
                {
                    product_id: product.id,
                    quantity: 1,
                    product: {
                        id: product.id,
                        name: product.name,
                        base_price: price,
                        sku: product.sku,
                        image_url: product.image_url,
                        brand_name: product.brand_name,
                    },
                },
            ]);
            toaster.create({
                description: `Товар "${product.name}" добавлен в корзину`,
                type: "success",
            });
        }
    };

    const handleUserChange = async (user) => {
        const newUserId = user?.id || null;
        recalculatePrices(newUserId, currencyCode, data.items);
        setData({
            ...data,
            user_id: newUserId,
            user: user,
        });
    };

    const handleCurrencyChange = async (e) => {
        const newCurrencyCode = e.target.value;
        setCurrencyCode(newCurrencyCode);
        recalculatePrices(data.user_id, newCurrencyCode, data.items);
    };

    const recalculatePrices = async (userId, currency, currentItems) => {
        if (currentItems.length > 0) {
            const promises = currentItems.map(async (item) => {
                try {
                    const response = await axios.post(route('admin.carts.calculate-price'), {
                        product_id: item.product_id,
                        user_id: userId,
                        currency_code: currency
                    });
                    return {
                        ...item,
                        product: {
                            ...item.product,
                            base_price: response.data.price
                        }
                    };
                } catch (e) {
                    console.error("Price calc error", e);
                    return item;
                }
            });

            const newItems = await Promise.all(promises);
            setData("items", newItems);
            toaster.create({ description: "Цены пересчитаны", type: "info" });
        }
    };

    const calculateTotal = () => {
        return data.items.reduce((sum, item) => {
            return sum + (Number(item.product?.base_price) || 0) * item.quantity;
        }, 0);
    };

    const totalQuantity = data.items.reduce((sum, item) => sum + item.quantity, 0);

    const handleSaveAndClose = (e) => {
        handleSubmit(e, true);
    };

    return (
        <>
            <Head title="Создание корзины" />

            <PageHeader
                title="Создание корзины"
                actions={
                    <HStack>
                        <Button
                            variant="ghost"
                            onClick={() => router.visit(route("admin.carts.index"))}
                        >
                            <LuArrowLeft /> Отмена
                        </Button>
                        <Button
                            colorPalette="blue"
                            onClick={handleSubmit}
                            loading={processing}
                            disabled={!data.user_id || data.items.length === 0}
                        >
                            <LuSave /> Создать
                        </Button>
                    </HStack>
                }
            />

            <form onSubmit={handleSubmit}>
                <VStack gap={6} align="stretch">
                    {/* Основная информация */}
                    <Card.Root>
                        <Card.Header>
                            <Heading size="md">Основная информация</Heading>
                        </Card.Header>
                        <Card.Body>
                            <VStack gap={4} align="stretch">
                                <HStack gap={4} flexWrap="wrap" align="start">
                                    <Box flex="1" minW="200px">
                                        <FormField
                                            label="Название корзины"
                                            required
                                            error={errors.name}
                                        >
                                            <Input
                                                value={data.name}
                                                onChange={(e) => setData("name", e.target.value)}
                                                placeholder="Например: Основная корзина"
                                            />
                                        </FormField>
                                    </Box>
                                    <Box flex="1" minW="300px">
                                        <FormField
                                            label="Пользователь"
                                            required
                                            error={errors.user_id}
                                        >
                                            <EntitySelector
                                                value={data.user}
                                                onChange={handleUserChange}
                                                searchUrl="admin.carts.search-users"
                                                placeholder="Поиск по имени или email..."
                                                displayField="name"
                                            />
                                        </FormField>
                                    </Box>
                                    <Box width="150px">
                                        <FormField label="Валюта (справочно)">
                                            <NativeSelect.Root>
                                                <NativeSelect.Field value={currencyCode} onChange={handleCurrencyChange}>
                                                    {currencies?.map(currency => (
                                                        <option key={currency.code} value={currency.code}>
                                                            {currency.code}
                                                        </option>
                                                    ))}
                                                </NativeSelect.Field>
                                                <NativeSelect.Indicator />
                                            </NativeSelect.Root>
                                        </FormField>
                                    </Box>
                                </HStack>
                            </VStack>
                        </Card.Body>
                    </Card.Root>

                    {/* Добавление товаров */}
                    <Card.Root>
                        <Card.Header>
                            <Heading size="md">Добавить товар</Heading>
                        </Card.Header>
                        <Card.Body>
                            <ProductSelector
                                mode="search"
                                onSelect={handleProductSelect}
                            />
                            <Text fontSize="sm" color="fg.muted" mt={2}>
                                Введите название, артикул или штрихкод товара и нажмите Enter
                            </Text>
                        </Card.Body>
                    </Card.Root>

                    {/* Товары */}
                    <Card.Root>
                        <Card.Header>
                            <HStack justify="space-between">
                                <HStack gap={3}>
                                    <Heading size="md">
                                        Товары в корзине
                                        <Text as="span" color="red.500" ml={1}>*</Text>
                                    </Heading>
                                    <Badge colorPalette={data.items.length > 0 ? "blue" : "gray"} size="lg">
                                        {data.items.length} позиций
                                    </Badge>
                                </HStack>

                                {errors.items && (
                                    <Text color="red.500" fontSize="sm">
                                        {errors.items}
                                    </Text>
                                )}
                            </HStack>
                        </Card.Header>
                        <Card.Body p={0}>
                            {data.items.length > 0 ? (
                                <Table.Root size="sm">
                                    <Table.Header>
                                        <Table.Row>
                                            <Table.ColumnHeader width="80px">Фото</Table.ColumnHeader>
                                            <Table.ColumnHeader>Товар</Table.ColumnHeader>
                                            <Table.ColumnHeader textAlign="right">Цена</Table.ColumnHeader>
                                            <Table.ColumnHeader textAlign="center" width="160px">Кол-во</Table.ColumnHeader>
                                            <Table.ColumnHeader textAlign="right">Сумма</Table.ColumnHeader>
                                            <Table.ColumnHeader textAlign="center" width="80px"></Table.ColumnHeader>
                                        </Table.Row>
                                    </Table.Header>
                                    <Table.Body>
                                        {data.items.map((item, index) => (
                                            <Table.Row key={`item-${index}`}>
                                                <Table.Cell>
                                                    {item.product?.image_url ? (
                                                        <Image
                                                            src={item.product.image_url}
                                                            alt={item.product.name}
                                                            boxSize="50px"
                                                            objectFit="cover"
                                                            borderRadius="md"
                                                        />
                                                    ) : (
                                                        <Box
                                                            boxSize="50px"
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
                                                    {item.product ? (
                                                        <VStack align="start" gap={0}>
                                                            <Text fontWeight="medium">{item.product.name}</Text>
                                                            {item.product.sku && (
                                                                <Text color="fg.muted" fontSize="xs">
                                                                    SKU: {item.product.sku}
                                                                </Text>
                                                            )}
                                                            {item.product.brand_name && (
                                                                <Badge size="sm" colorPalette="purple">
                                                                    {item.product.brand_name}
                                                                </Badge>
                                                            )}
                                                        </VStack>
                                                    ) : (
                                                        <Text color="red.500">Товар не найден</Text>
                                                    )}
                                                </Table.Cell>
                                                <Table.Cell textAlign="right">
                                                    {item.product?.base_price ? (
                                                        `${Number(item.product.base_price).toFixed(2)} ${currencySymbol}`
                                                    ) : "—"}
                                                </Table.Cell>
                                                <Table.Cell textAlign="center">
                                                    <HStack justify="center" gap={1}>
                                                        <IconButton
                                                            size="xs"
                                                            variant="ghost"
                                                            onClick={() => updateItemQuantity(index, item.quantity - 1)}
                                                            disabled={item.quantity <= 1}
                                                        >
                                                            <LuMinus />
                                                        </IconButton>
                                                        <Input
                                                            size="sm"
                                                            width="60px"
                                                            textAlign="center"
                                                            type="number"
                                                            min="1"
                                                            value={item.quantity}
                                                            onChange={(e) => updateItemQuantity(index, parseInt(e.target.value) || 1)}
                                                        />
                                                        <IconButton
                                                            size="xs"
                                                            variant="ghost"
                                                            onClick={() => updateItemQuantity(index, item.quantity + 1)}
                                                        >
                                                            <LuPlus />
                                                        </IconButton>
                                                    </HStack>
                                                </Table.Cell>
                                                <Table.Cell textAlign="right" fontWeight="medium">
                                                    {item.product
                                                        ? `${(Number(item.product.base_price) * item.quantity).toFixed(2)} ${currencySymbol}`
                                                        : "—"}
                                                </Table.Cell>
                                                <Table.Cell textAlign="center">
                                                    <IconButton
                                                        size="sm"
                                                        variant="ghost"
                                                        colorPalette="red"
                                                        onClick={() => removeItem(index)}
                                                    >
                                                        <LuTrash2 />
                                                    </IconButton>
                                                </Table.Cell>
                                            </Table.Row>
                                        ))}
                                    </Table.Body>
                                </Table.Root>
                            ) : (
                                <Box p={8} textAlign="center">
                                    <LuPackage size={48} style={{ margin: "0 auto 16px", opacity: 0.3 }} />
                                    <Text color="fg.muted" mb={4}>Корзина пуста</Text>
                                    <Text color="fg.muted" fontSize="sm">
                                        Используйте поле поиска выше, чтобы добавить товары
                                    </Text>
                                </Box>
                            )}
                        </Card.Body>

                        {/* Итого */}
                        {data.items.length > 0 && (
                            <Card.Footer borderTopWidth="1px">
                                <HStack justify="space-between" width="100%">
                                    <HStack gap={6}>
                                        <Box>
                                            <Text fontSize="sm" color="fg.muted">Позиций</Text>
                                            <Text fontWeight="bold">{data.items.length}</Text>
                                        </Box>
                                        <Box>
                                            <Text fontSize="sm" color="fg.muted">Единиц</Text>
                                            <Text fontWeight="bold">{totalQuantity}</Text>
                                        </Box>
                                    </HStack>
                                    <Box textAlign="right">
                                        <Text fontSize="sm" color="fg.muted">Итого</Text>
                                        <Text fontWeight="bold" fontSize="xl" color="green.600">
                                            {calculateTotal().toFixed(2)} {currencySymbol}
                                        </Text>
                                    </Box>
                                </HStack>
                            </Card.Footer>
                        )}
                    </Card.Root>

                    {/* Кнопки действий */}
                    <HStack justify="flex-end" gap={4}>
                        <Button
                            variant="outline"
                            onClick={() => router.visit(route("admin.carts.index"))}
                        >
                            Отмена
                        </Button>
                        <Button
                            colorPalette="blue"
                            type="submit"
                            loading={processing}
                            disabled={!data.name || !data.user_id || data.items.length === 0}
                        >
                            <LuSave /> Создать корзину
                        </Button>
                    </HStack>
                </VStack>
            </form>
        </>
    );
};

CartCreate.layout = (page) => <AdminLayout>{page}</AdminLayout>;

export default CartCreate;
