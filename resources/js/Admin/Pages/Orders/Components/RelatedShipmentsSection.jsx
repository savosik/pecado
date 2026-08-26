import React from "react";
import { Link } from "@inertiajs/react";
import {
    Box,
    Card,
    HStack,
    VStack,
    Text,
    Badge,
    Table,
} from "@chakra-ui/react";
import { LuTruck } from "react-icons/lu";
import RowActions from "@/shared/Panel/RowActions";

const STATUS_COLORS = {
    new: "blue",
    completed: "green",
    cancelled: "red",
    in_progress: "orange",
};

const fmt = (v) =>
    parseFloat(v || 0).toLocaleString("ru-RU", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

export function RelatedShipmentsSection({ shipments }) {
    if (!shipments || shipments.length === 0) {
        return null;
    }

    const totals = shipments.reduce(
        (acc, s) => {
            acc.items += parseInt(s.items_count || 0, 10);
            acc.amount += parseFloat(s.total_amount || 0);
            return acc;
        },
        { items: 0, amount: 0 }
    );

    const currency = shipments[0]?.currency_code || "₽";

    return (
        <Card.Root>
            <Card.Header>
                <HStack gap={2}>
                    <LuTruck size={20} />
                    <Text fontWeight="semibold" fontSize="lg">
                        Реализации по заказу ({shipments.length})
                    </Text>
                </HStack>
            </Card.Header>
            <Card.Body p={0}>
                <Box overflowX="auto">
                    <Table.Root size="sm">
                        <Table.Header>
                            <Table.Row>
                                <Table.ColumnHeader>Номер</Table.ColumnHeader>
                                <Table.ColumnHeader>Дата</Table.ColumnHeader>
                                <Table.ColumnHeader>Статус</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="center">Позиций</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="right">Сумма</Table.ColumnHeader>
                                <Table.ColumnHeader>Создано в 1С</Table.ColumnHeader>
                                <Table.ColumnHeader w="80px" />
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {shipments.map((shipment) => {
                                const displayNumber =
                                    shipment.erp_number ||
                                    shipment.number ||
                                    `#${shipment.id}`;

                                return (
                                    <Table.Row key={shipment.id}>
                                        <Table.Cell>
                                            <Link
                                                href={route(
                                                    "admin.shipments.show",
                                                    shipment.id
                                                )}
                                            >
                                                <VStack align="start" gap={0}>
                                                    <Text
                                                        fontFamily="mono"
                                                        fontWeight="600"
                                                        fontSize="sm"
                                                        color="blue.600"
                                                        _hover={{
                                                            textDecoration:
                                                                "underline",
                                                        }}
                                                    >
                                                        {displayNumber}
                                                    </Text>
                                                    {shipment.erp_number &&
                                                        shipment.number && (
                                                            <Text
                                                                fontSize="xs"
                                                                color="fg.muted"
                                                            >
                                                                Внутр.: {shipment.number}
                                                            </Text>
                                                        )}
                                                </VStack>
                                            </Link>
                                        </Table.Cell>
                                        <Table.Cell>
                                            <Text fontSize="sm">
                                                {shipment.date
                                                    ? new Date(shipment.date).toLocaleDateString("ru-RU")
                                                    : "—"}
                                            </Text>
                                        </Table.Cell>
                                        <Table.Cell>
                                            <Badge
                                                colorPalette={
                                                    STATUS_COLORS[shipment.status] || "gray"
                                                }
                                                variant="subtle"
                                            >
                                                {shipment.status_label}
                                            </Badge>
                                        </Table.Cell>
                                        <Table.Cell textAlign="center">
                                            <Text fontFamily="mono" fontSize="sm">
                                                {shipment.items_count ?? "—"}
                                            </Text>
                                        </Table.Cell>
                                        <Table.Cell textAlign="right">
                                            <Text
                                                fontFamily="mono"
                                                fontWeight="600"
                                                fontSize="sm"
                                            >
                                                {fmt(shipment.total_amount)}{" "}
                                                {shipment.currency_code || "₽"}
                                            </Text>
                                        </Table.Cell>
                                        <Table.Cell>
                                            <Text fontSize="xs" color="fg.muted">
                                                {shipment.erp_created_at || "—"}
                                            </Text>
                                        </Table.Cell>
                                        <Table.Cell>
                                            <RowActions
                                                size="xs"
                                                view={{ href: route("admin.shipments.show", shipment.id) }}
                                            />
                                        </Table.Cell>
                                    </Table.Row>
                                );
                            })}
                        </Table.Body>
                    </Table.Root>
                </Box>
            </Card.Body>
            {shipments.length > 1 && (
                <Card.Footer>
                    <HStack justify="space-between" width="100%">
                        <Text color="fg.muted" fontSize="sm">
                            Итого по реализациям: {totals.items} позиций
                        </Text>
                        <Text fontFamily="mono" fontWeight="bold">
                            {fmt(totals.amount)} {currency}
                        </Text>
                    </HStack>
                </Card.Footer>
            )}
        </Card.Root>
    );
}
