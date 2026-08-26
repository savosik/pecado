import React from "react";
import RowActions from "@/shared/Panel/RowActions";
import {
    Box,
    Card,
    HStack,
    VStack,
    Text,
    Badge,
    Flex,
} from "@chakra-ui/react";
import { LuShoppingBag, LuMapPin, LuMessageSquare } from "react-icons/lu";
import { ORDER_STATUS_COLORS as STATUS_COLORS } from "@/constants/orderStatus";

const TYPE_COLORS = {
    preorder: "purple",
    order: "teal",
};

const fmt = (v) =>
    parseFloat(v || 0).toLocaleString("ru-RU", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

const pluralize = (n, forms) => {
    const num = Math.abs(n) % 100;
    const n1 = num % 10;
    if (num > 10 && num < 20) return forms[2];
    if (n1 > 1 && n1 < 5) return forms[1];
    if (n1 === 1) return forms[0];
    return forms[2];
};

export function RelatedOrdersSection({ orders }) {
    if (!orders || orders.length === 0) {
        return null;
    }

    return (
        <Card.Root>
            <Card.Header>
                <HStack gap={2}>
                    <LuShoppingBag size={20} />
                    <Text fontWeight="semibold" fontSize="lg">
                        Связанные заказы ({orders.length})
                    </Text>
                </HStack>
            </Card.Header>
            <Card.Body>
                <VStack gap={3} align="stretch">
                    {orders.map((order) => {
                        const displayNumber =
                            order.erp_number || order.number || `#${order.id}`;

                        return (
                            <Box key={order.id}>
                                <Box
                                    p={4}
                                    borderRadius="lg"
                                    border="1px solid"
                                    borderColor="border.subtle"
                                    bg="bg.subtle"
                                    _hover={{
                                        borderColor: "blue.300",
                                        shadow: "sm",
                                    }}
                                    transition="all 0.15s"
                                >
                                    <Flex
                                        gap={4}
                                        align="start"
                                        justify="space-between"
                                    >
                                        <Box flex="1" minW="0">
                                            <Flex
                                                gap={2}
                                                align="center"
                                                flexWrap="wrap"
                                                mb={2}
                                            >
                                                <Text
                                                    fontWeight="700"
                                                    fontSize="md"
                                                    fontFamily="mono"
                                                    color="blue.600"
                                                    whiteSpace="nowrap"
                                                >
                                                    {displayNumber}
                                                </Text>
                                                {order.erp_number &&
                                                    order.number && (
                                                        <Text
                                                            fontSize="xs"
                                                            color="fg.muted"
                                                            fontFamily="mono"
                                                        >
                                                            (внутр.: {order.number})
                                                        </Text>
                                                    )}
                                                <Badge
                                                    colorPalette={
                                                        TYPE_COLORS[order.type] ||
                                                        "gray"
                                                    }
                                                    variant="subtle"
                                                    fontSize="xs"
                                                >
                                                    {order.type_label}
                                                </Badge>
                                                <Badge
                                                    colorPalette={
                                                        STATUS_COLORS[order.status] ||
                                                        "gray"
                                                    }
                                                    variant="subtle"
                                                    fontSize="xs"
                                                >
                                                    {order.status_label}
                                                </Badge>
                                                {order.created_at && (
                                                    <Text
                                                        fontSize="xs"
                                                        color="fg.muted"
                                                    >
                                                        {order.created_at}
                                                    </Text>
                                                )}
                                            </Flex>

                                            <HStack
                                                gap={3}
                                                fontSize="sm"
                                                color="fg.muted"
                                                flexWrap="wrap"
                                                mb={
                                                    order.delivery_address ||
                                                    order.comment
                                                        ? 2
                                                        : 0
                                                }
                                            >
                                                {order.company && (
                                                    <Text fontWeight="500">
                                                        {order.company.name}
                                                    </Text>
                                                )}
                                                {order.user && (
                                                    <Text>
                                                        {order.user.name}
                                                        {order.user.email
                                                            ? ` (${order.user.email})`
                                                            : ""}
                                                    </Text>
                                                )}
                                                <Text>
                                                    {order.items_count}{" "}
                                                    {pluralize(
                                                        order.items_count,
                                                        [
                                                            "позиция",
                                                            "позиции",
                                                            "позиций",
                                                        ]
                                                    )}
                                                </Text>
                                                {order.shipments_count > 0 && (
                                                    <Text>
                                                        {order.shipments_count}{" "}
                                                        {pluralize(
                                                            order.shipments_count,
                                                            [
                                                                "реализация",
                                                                "реализации",
                                                                "реализаций",
                                                            ]
                                                        )}
                                                    </Text>
                                                )}
                                            </HStack>

                                            {order.delivery_address && (
                                                <HStack
                                                    gap={1.5}
                                                    fontSize="xs"
                                                    color="fg.muted"
                                                    mb={1}
                                                    minW="0"
                                                >
                                                    <Box
                                                        flexShrink="0"
                                                        color="gray.400"
                                                    >
                                                        <LuMapPin size={12} />
                                                    </Box>
                                                    <Text noOfLines={1}>
                                                        {order.delivery_address}
                                                    </Text>
                                                </HStack>
                                            )}

                                            {order.comment && (
                                                <HStack
                                                    gap={1.5}
                                                    fontSize="xs"
                                                    color="fg.muted"
                                                    minW="0"
                                                >
                                                    <Box flexShrink="0">
                                                        <LuMessageSquare size={12} />
                                                    </Box>
                                                    <Text
                                                        noOfLines={1}
                                                        fontStyle="italic"
                                                    >
                                                        {order.comment}
                                                    </Text>
                                                </HStack>
                                            )}
                                        </Box>

                                        <VStack
                                            gap={0}
                                            align="end"
                                            flexShrink="0"
                                        >
                                            <Text
                                                fontWeight="700"
                                                fontSize="lg"
                                                fontFamily="mono"
                                                whiteSpace="nowrap"
                                            >
                                                {fmt(order.total_amount)}{" "}
                                                {order.currency_code || "₽"}
                                            </Text>
                                            <RowActions
                                                size="xs"
                                                view={{ href: route("admin.orders.show", order.id) }}
                                            />
                                        </VStack>
                                    </Flex>
                                </Box>
                            </Box>
                        );
                    })}
                </VStack>
            </Card.Body>
        </Card.Root>
    );
}
