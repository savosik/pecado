import React from "react";
import { Card, Input, SimpleGrid, Stack, Text } from "@chakra-ui/react";
import { FormField } from "@/Admin/Components/FormField";
import { Checkbox } from "@/components/ui/checkbox";

/**
 * Форма организации — общая для создания и редактирования.
 *
 * Реквизиты банка 1С не присылает: их вводит админ, и клиент видит их в разрезе
 * взаиморасчётов (долг перед конкретным юрлицом, org-06).
 */
export function OrganizationForm({ data, setData, errors }) {
    return (
        <Stack gap={4} maxW="3xl">
            <Card.Root>
                <Card.Header>
                    <Text fontWeight="semibold">Основное</Text>
                </Card.Header>
                <Card.Body>
                    <Stack gap={4}>
                        <FormField label="Краткое название" error={errors.name} required>
                            <Input
                                value={data.name ?? ""}
                                onChange={(e) => setData("name", e.target.value)}
                                placeholder="ООО «Пекадо»"
                            />
                            <Text fontSize="xs" color="fg.muted" mt={1}>
                                Это название клиент видит как продавца в заказах и накладных.
                            </Text>
                        </FormField>

                        <FormField label="Полное юридическое наименование" error={errors.legal_name}>
                            <Input
                                value={data.legal_name ?? ""}
                                onChange={(e) => setData("legal_name", e.target.value)}
                                placeholder="Общество с ограниченной ответственностью «Пекадо»"
                            />
                        </FormField>

                        <FormField label="UUID в 1С" error={errors.external_id}>
                            <Input
                                value={data.external_id ?? ""}
                                onChange={(e) => setData("external_id", e.target.value)}
                                placeholder="Например: 3d0a3eb9-0c23-11ee-8ddc-ee348b24c7ce"
                            />
                            <Text fontSize="xs" color="fg.muted" mt={1}>
                                1С указывает в своих сообщениях только UUID организации. Без него документы
                                этого юрлица приедут как «не заведённая организация». UUID запрашивается
                                у стороны 1С.
                            </Text>
                        </FormField>

                        <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                            <FormField label="ИНН" error={errors.tax_id}>
                                <Input
                                    value={data.tax_id ?? ""}
                                    onChange={(e) => setData("tax_id", e.target.value)}
                                    placeholder="10 или 12 цифр"
                                />
                            </FormField>

                            <FormField label="КПП" error={errors.tax_code}>
                                <Input
                                    value={data.tax_code ?? ""}
                                    onChange={(e) => setData("tax_code", e.target.value)}
                                    placeholder="9 цифр"
                                />
                            </FormField>
                        </SimpleGrid>

                        <FormField label="Порядок отображения" error={errors.sort_order}>
                            <Input
                                type="number"
                                min={0}
                                value={data.sort_order ?? 0}
                                onChange={(e) => setData("sort_order", e.target.value)}
                            />
                            <Text fontSize="xs" color="fg.muted" mt={1}>
                                Меньше — выше в списках и фильтрах отчётов.
                            </Text>
                        </FormField>

                        <FormField label="Активна" error={errors.is_active}>
                            <Checkbox
                                checked={!!data.is_active}
                                onCheckedChange={(e) => setData("is_active", !!e.checked)}
                            >
                                Используется в текущих документах
                            </Checkbox>
                            <Text fontSize="xs" color="fg.muted" mt={1}>
                                Неактивную организацию не предлагаем в фильтрах по умолчанию, но её документы
                                и история остаются на месте.
                            </Text>
                        </FormField>
                    </Stack>
                </Card.Body>
            </Card.Root>

            <Card.Root>
                <Card.Header>
                    <Text fontWeight="semibold">Реквизиты для оплаты</Text>
                    <Text fontSize="xs" color="fg.muted">
                        Клиент видит их рядом с долгом перед этой организацией.
                    </Text>
                </Card.Header>
                <Card.Body>
                    <Stack gap={4}>
                        <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                            <FormField label="Банк" error={errors.bank_name}>
                                <Input
                                    value={data.bank_name ?? ""}
                                    onChange={(e) => setData("bank_name", e.target.value)}
                                />
                            </FormField>

                            <FormField label="БИК" error={errors.bank_bik}>
                                <Input
                                    value={data.bank_bik ?? ""}
                                    onChange={(e) => setData("bank_bik", e.target.value)}
                                />
                            </FormField>

                            <FormField label="Расчётный счёт" error={errors.account_number}>
                                <Input
                                    value={data.account_number ?? ""}
                                    onChange={(e) => setData("account_number", e.target.value)}
                                />
                            </FormField>

                            <FormField label="Корреспондентский счёт" error={errors.correspondent_account}>
                                <Input
                                    value={data.correspondent_account ?? ""}
                                    onChange={(e) => setData("correspondent_account", e.target.value)}
                                />
                            </FormField>
                        </SimpleGrid>
                    </Stack>
                </Card.Body>
            </Card.Root>
        </Stack>
    );
}

export default OrganizationForm;
