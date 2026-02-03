import React, { useState } from "react";
import {
    Box,
    Button,
    Input,
    Table,
    HStack,
    VStack,
    IconButton,
    Text,
    Textarea,
    Heading,
    Card,
} from "@chakra-ui/react";
import { LuSearch, LuPlus, LuTrash2, LuChevronDown, LuChevronUp } from "react-icons/lu";
import { Field } from "@/components/ui/field";
import { Select } from "@/components/ui/select";

const ReturnItemsEditor = ({ items = [], onChange, reasons = [] }) => {
    const [localItems, setLocalItems] = useState(items);
    const [searchQuery, setSearchQuery] = useState("");
    const [searchResults, setSearchResults] = useState([]);
    const [isSearching, setIsSearching] = useState(false);
    const [expandedComments, setExpandedComments] = useState({});

    const handleSearch = async () => {
        if (!searchQuery.trim()) return;

        setIsSearching(true);
        try {
            const response = await fetch(
                `/admin/products/search?query=${encodeURIComponent(searchQuery)}`
            );
            const products = await response.json();
            setSearchResults(products);
        } catch (error) {
            console.error("Ошибка поиска:", error);
            setSearchResults([]);
        } finally {
            setIsSearching(false);
        }
    };

    const handleAddProduct = (product) => {
        const newItem = {
            product_id: product.id,
            product: {
                id: product.id,
                name: product.name,
            },
            order_id: null,
            quantity: 1,
            price: product.price || 0,
            subtotal: product.price || 0,
            reason: "",
            reason_comment: "",
        };

        const updated = [...localItems, newItem];
        setLocalItems(updated);
        onChange(updated);
        setSearchQuery("");
        setSearchResults([]);
    };

    const handleUpdateItem = (index, field, value) => {
        const updated = [...localItems];
        updated[index] = {
            ...updated[index],
            [field]: value,
        };

        // Автопересчёт subtotal при изменении price или quantity
        if (field === "price" || field === "quantity") {
            const price = field === "price" ? parseFloat(value) || 0 : parseFloat(updated[index].price) || 0;
            const quantity = field === "quantity" ? parseInt(value) || 0 : parseInt(updated[index].quantity) || 0;
            updated[index].subtotal = price * quantity;
        }

        setLocalItems(updated);
        onChange(updated);
    };

    const handleRemoveItem = (index) => {
        const updated = localItems.filter((_, i) => i !== index);
        setLocalItems(updated);
        onChange(updated);
    };

    const toggleCommentExpand = (index) => {
        setExpandedComments({
            ...expandedComments,
            [index]: !expandedComments[index],
        });
    };

    const totalAmount = localItems.reduce((sum, item) => sum + (parseFloat(item.subtotal) || 0), 0);

    return (
        <VStack align="stretch" gap={4}>
            {/* Поиск товаров */}
            <Card.Root>
                <Card.Header>
                    <Heading size="sm">Добавить товар</Heading>
                </Card.Header>
                <Card.Body>
                    <HStack>
                        <Field label="Поиск товара" flex={1}>
                            <Input
                                value={searchQuery}
                                onChange={(e) => setSearchQuery(e.target.value)}
                                placeholder="Введите название или артикул товара..."
                                onKeyPress={(e) => {
                                    if (e.key === "Enter") {
                                        handleSearch();
                                    }
                                }}
                            />
                        </Field>
                        <Button
                            onClick={handleSearch}
                            isLoading={isSearching}
                            mt={8}
                            colorPalette="blue"
                        >
                            <LuSearch /> Найти
                        </Button>
                    </HStack>

                    {searchResults.length > 0 && (
                        <Box mt={4} p={4} borderWidth="1px" borderRadius="md" maxH="300px" overflowY="auto">
                            <VStack align="stretch" gap={2}>
                                {searchResults.map((product) => (
                                    <HStack
                                        key={product.id}
                                        p={2}
                                        borderWidth="1px"
                                        borderRadius="md"
                                        justify="space-between"
                                        _hover={{ bg: "gray.50" }}
                                    >
                                        <Box flex={1}>
                                            <Text fontWeight="medium">{product.name}</Text>
                                            <Text fontSize="sm" color="fg.muted">
                                                ID: {product.id} • Артикул: {product.sku} • Цена: {product.price} ₽
                                            </Text>
                                        </Box>
                                        <Button
                                            size="sm"
                                            onClick={() => handleAddProduct(product)}
                                        >
                                            <LuPlus /> Добавить
                                        </Button>
                                    </HStack>
                                ))}
                            </VStack>
                        </Box>
                    )}
                </Card.Body>
            </Card.Root>

            {/* Таблица позиций возврата */}
            {localItems.length > 0 && (
                <Card.Root>
                    <Card.Header>
                        <Heading size="sm">Позиции возврата ({localItems.length})</Heading>
                    </Card.Header>
                    <Card.Body p={0}>
                        <Table.Root size="sm">
                            <Table.Header>
                                <Table.Row>
                                    <Table.ColumnHeader>Товар</Table.ColumnHeader>
                                    <Table.ColumnHeader>Заказ ID</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Цена</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Кол-во</Table.ColumnHeader>
                                    <Table.ColumnHeader>Причина</Table.ColumnHeader>
                                    <Table.ColumnHeader>Комментарий</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Сумма</Table.ColumnHeader>
                                    <Table.ColumnHeader></Table.ColumnHeader>
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {localItems.map((item, index) => (
                                    <React.Fragment key={index}>
                                        <Table.Row>
                                            <Table.Cell>
                                                <VStack align="start" gap={0}>
                                                    <Text fontSize="sm" fontWeight="medium">
                                                        {item.product?.name || `Товар #${item.product_id}`}
                                                    </Text>
                                                    <Text fontSize="xs" color="fg.muted">
                                                        ID: {item.product_id}
                                                    </Text>
                                                </VStack>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Input
                                                    type="number"
                                                    size="sm"
                                                    value={item.order_id || ""}
                                                    onChange={(e) =>
                                                        handleUpdateItem(
                                                            index,
                                                            "order_id",
                                                            e.target.value ? parseInt(e.target.value) : null
                                                        )
                                                    }
                                                    placeholder="—"
                                                    width="80px"
                                                />
                                            </Table.Cell>
                                            <Table.Cell textAlign="right">
                                                <Input
                                                    type="number"
                                                    step="0.01"
                                                    size="sm"
                                                    value={item.price}
                                                    onChange={(e) =>
                                                        handleUpdateItem(index, "price", e.target.value)
                                                    }
                                                    width="100px"
                                                />
                                            </Table.Cell>
                                            <Table.Cell textAlign="right">
                                                <Input
                                                    type="number"
                                                    size="sm"
                                                    value={item.quantity}
                                                    onChange={(e) =>
                                                        handleUpdateItem(index, "quantity", e.target.value)
                                                    }
                                                    min="1"
                                                    width="70px"
                                                />
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Select.Root
                                                    value={item.reason ? [item.reason] : []}
                                                    onValueChange={(e) =>
                                                        handleUpdateItem(index, "reason", e.value[0])
                                                    }
                                                    size="sm"
                                                >
                                                    <Select.Trigger width="200px">
                                                        <Select.ValueText placeholder="Выберите причину" />
                                                    </Select.Trigger>
                                                    <Select.Content>
                                                        {reasons.map((reason) => (
                                                            <Select.Item key={reason.value} item={reason.value}>
                                                                {reason.label}
                                                            </Select.Item>
                                                        ))}
                                                    </Select.Content>
                                                </Select.Root>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <IconButton
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() => toggleCommentExpand(index)}
                                                    title={
                                                        expandedComments[index]
                                                            ? "Свернуть комментарий"
                                                            : "Развернуть комментарий"
                                                    }
                                                >
                                                    {expandedComments[index] ? (
                                                        <LuChevronUp />
                                                    ) : (
                                                        <LuChevronDown />
                                                    )}
                                                </IconButton>
                                            </Table.Cell>
                                            <Table.Cell textAlign="right">
                                                <Text fontWeight="medium">
                                                    {(parseFloat(item.subtotal) || 0).toFixed(2)} ₽
                                                </Text>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <IconButton
                                                    size="sm"
                                                    variant="ghost"
                                                    colorPalette="red"
                                                    onClick={() => handleRemoveItem(index)}
                                                    title="Удалить"
                                                >
                                                    <LuTrash2 />
                                                </IconButton>
                                            </Table.Cell>
                                        </Table.Row>
                                        {expandedComments[index] && (
                                            <Table.Row>
                                                <Table.Cell colSpan={8}>
                                                    <Box p={2} bg="gray.50" borderRadius="md">
                                                        <Text fontSize="xs" fontWeight="medium" mb={1}>
                                                            Дополнительный комментарий к причине:
                                                        </Text>
                                                        <Textarea
                                                            size="sm"
                                                            value={item.reason_comment || ""}
                                                            onChange={(e) =>
                                                                handleUpdateItem(
                                                                    index,
                                                                    "reason_comment",
                                                                    e.target.value
                                                                )
                                                            }
                                                            placeholder="Опишите детали..."
                                                            rows={2}
                                                        />
                                                    </Box>
                                                </Table.Cell>
                                            </Table.Row>
                                        )}
                                    </React.Fragment>
                                ))}
                            </Table.Body>
                        </Table.Root>
                    </Card.Body>
                    <Card.Footer>
                        <HStack justify="space-between" w="full">
                            <Text fontWeight="medium">Итого:</Text>
                            <Text fontSize="xl" fontWeight="bold" colorPalette="blue">
                                {totalAmount.toFixed(2)} ₽
                            </Text>
                        </HStack>
                    </Card.Footer>
                </Card.Root>
            )}

            {localItems.length === 0 && (
                <Box p={8} textAlign="center" borderWidth="1px" borderRadius="md" borderStyle="dashed">
                    <Text color="fg.muted">
                        Нет позиций возврата. Используйте поиск выше чтобы добавить товары.
                    </Text>
                </Box>
            )}
        </VStack>
    );
};

export default ReturnItemsEditor;
