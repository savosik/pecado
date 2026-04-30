import { useState } from 'react';
import {
    Box, Flex, HStack, VStack, Text, Button, Card, Stack, Textarea,
    createListCollection,
} from '@chakra-ui/react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { LuArrowLeft, LuSave, LuRotateCcw } from 'react-icons/lu';
import CabinetLayout from '../CabinetLayout';
import { Field } from '@/components/ui/field';
import { toaster } from '@/components/ui/toaster';
import ReturnItemsEditor from '@/Admin/Components/ReturnItemsEditor';

export default function ReturnsCreate({ reasons }) {
    const { data, setData, post, processing, errors, transform } = useForm({
        comment: '',
        items: [],
    });

    transform((payload) => ({
        comment: payload.comment,
        items: payload.items.map((it) => ({
            shipment_item_id: it.shipment_item_id,
            quantity: it.quantity,
            reason: it.reason,
            reason_comment: it.reason_comment ?? null,
        })),
    }));

    const handleSubmit = (e) => {
        e.preventDefault();

        if (data.items.length === 0) {
            toaster.create({
                description: 'Добавьте хотя бы одну позицию возврата',
                type: 'error',
            });
            return;
        }

        const itemsWithoutReason = data.items.filter((item) => !item.reason);
        if (itemsWithoutReason.length > 0) {
            toaster.create({
                description: 'Укажите причину для всех позиций возврата',
                type: 'error',
            });
            return;
        }

        post(route('cabinet.returns.store'), {
            onSuccess: () => {
                toaster.create({
                    description: 'Заявка на возврат успешно создана',
                    type: 'success',
                });
            },
            onError: (errors) => {
                const errorMessage = errors.error
                    || Object.values(errors).find(e => typeof e === 'string')
                    || 'Ошибка при создании возврата';
                toaster.create({
                    description: errorMessage,
                    type: 'error',
                });
            },
        });
    };

    return (
        <CabinetLayout
            title="Создать возврат"
            actions={
                <Button asChild variant="outline" size="sm">
                    <Link href="/cabinet/returns">
                        <LuArrowLeft size={16} /> Назад
                    </Link>
                </Button>
            }
        >
            <Head title="Создать возврат — Pecado" />

            <form onSubmit={handleSubmit}>
                <Stack gap="5">
                    {/* Комментарий */}
                    <Card.Root bg="bg" borderRadius="xl" border="1px solid" borderColor="border.muted">
                        <Card.Header p="4" pb="2">
                            <Text fontWeight="700" fontSize="md">Комментарий к возврату</Text>
                        </Card.Header>
                        <Card.Body p="4" pt="0">
                            <Field
                                invalid={!!errors.comment}
                                errorText={errors.comment}
                            >
                                <Textarea
                                    value={data.comment || ''}
                                    onChange={(e) => setData('comment', e.target.value)}
                                    placeholder="Опишите причину возврата..."
                                    rows={3}
                                />
                            </Field>
                        </Card.Body>
                    </Card.Root>

                    {/* Позиции возврата */}
                    <Card.Root bg="bg" borderRadius="xl" border="1px solid" borderColor="border.muted">
                        <Card.Header p="4" pb="2">
                            <Text fontWeight="700" fontSize="md">
                                Позиции возврата
                                {data.items.length > 0 && ` (${data.items.length})`}
                            </Text>
                        </Card.Header>
                        <Card.Body p="4" pt="0">
                            <ReturnItemsEditor
                                items={data.items}
                                onChange={(items) => setData('items', items)}
                                reasons={reasons}
                                searchShipmentsRoute="cabinet.returns.search-shipments"
                                shipmentItemsRoute="cabinet.returns.shipment-items"
                                accentColor="gray"
                                requireUser={false}
                            />
                            {errors.items && (
                                <Box mt={4} p={4} bg="red.50" borderRadius="md">
                                    <Text color="red.600" fontSize="sm">{errors.items}</Text>
                                </Box>
                            )}
                        </Card.Body>
                    </Card.Root>

                    {/* Кнопка отправки */}
                    <Flex justify="flex-end" gap="3">
                        <Button asChild variant="outline" size="md">
                            <Link href="/cabinet/returns">Отмена</Link>
                        </Button>
                        <Button
                            type="submit"
                            bg="#9e1b32"
                            color="white"
                            _hover={{ bg: '#7a1527' }}
                            size="md"
                            loading={processing}
                        >
                            <LuSave size={16} /> Отправить заявку
                        </Button>
                    </Flex>
                </Stack>
            </form>
        </CabinetLayout>
    );
}
