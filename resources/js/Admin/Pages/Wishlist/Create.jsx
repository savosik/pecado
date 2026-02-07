import React from "react";
import {
    Box,
    Card,
    HStack,
    VStack,
    Heading,
    Button,
} from "@chakra-ui/react";
import { Head, router, useForm } from "@inertiajs/react";
import { LuArrowLeft, LuSave } from "react-icons/lu";
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader } from "@/Admin/Components/PageHeader";
import { FormField } from "@/Admin/Components/FormField";
import { ProductSelector } from "@/Admin/Components/ProductSelector";
import { EntitySelector } from "@/Admin/Components/EntitySelector";
import { toaster } from "@/components/ui/toaster";

const WishlistCreate = () => {
    const { data, setData, post, processing, errors } = useForm({
        user_id: null,
        user: null,
        product_id: null,
        product: null,
    });

    const handleSubmit = (e) => {
        e.preventDefault();

        if (!data.user_id) {
            toaster.create({
                description: "Выберите пользователя",
                type: "error",
            });
            return;
        }

        if (!data.product_id) {
            toaster.create({
                description: "Выберите товар",
                type: "error",
            });
            return;
        }

        post(route("admin.wishlist.store"), {
            onSuccess: () => {
                toaster.create({
                    description: "Товар добавлен в список желаний",
                    type: "success",
                });
            },
            onError: (errors) => {
                if (errors.product_id) {
                    toaster.create({
                        description: errors.product_id,
                        type: "error",
                    });
                }
            },
        });
    };

    const handleUserChange = (user) => {
        setData({
            ...data,
            user_id: user?.id || null,
            user: user,
        });
    };

    const handleProductChange = (product) => {
        setData({
            ...data,
            product_id: product?.id || null,
            product: product,
        });
    };

    return (
        <>
            <Head title="Добавление в список желаний" />

            <PageHeader
                title="Добавление в список желаний"
                actions={
                    <HStack>
                        <Button
                            variant="ghost"
                            onClick={() => router.visit(route("admin.wishlist.index"))}
                        >
                            <LuArrowLeft /> Отмена
                        </Button>
                        <Button
                            colorPalette="blue"
                            onClick={handleSubmit}
                            loading={processing}
                            disabled={!data.user_id || !data.product_id}
                        >
                            <LuSave /> Добавить
                        </Button>
                    </HStack>
                }
            />

            <form onSubmit={handleSubmit} noValidate>
                <VStack gap={6} align="stretch">
                    <Card.Root>
                        <Card.Header>
                            <Heading size="md">Выберите пользователя и товар</Heading>
                        </Card.Header>
                        <Card.Body>
                            <VStack gap={4} align="stretch">
                                <Box maxW="500px">
                                    <FormField
                                        label="Пользователь"
                                        required
                                        error={errors.user_id}
                                    >
                                        <EntitySelector
                                            value={data.user}
                                            onChange={handleUserChange}
                                            searchUrl="admin.wishlist.search-users"
                                            placeholder="Поиск по имени или email..."
                                            displayField="name"
                                        />
                                    </FormField>
                                </Box>
                                <Box maxW="500px">
                                    <FormField
                                        label="Товар"
                                        required
                                        error={errors.product_id}
                                    >
                                        <ProductSelector
                                            mode="single"
                                            value={data.product}
                                            onChange={handleProductChange}
                                        />
                                    </FormField>
                                </Box>
                            </VStack>
                        </Card.Body>
                    </Card.Root>

                    {/* Кнопки действий */}
                    <HStack justify="flex-end" gap={4}>
                        <Button
                            variant="outline"
                            onClick={() => router.visit(route("admin.wishlist.index"))}
                        >
                            Отмена
                        </Button>
                        <Button
                            colorPalette="blue"
                            type="submit"
                            loading={processing}
                            disabled={!data.user_id || !data.product_id}
                        >
                            <LuSave /> Добавить в список желаний
                        </Button>
                    </HStack>
                </VStack>
            </form>
        </>
    );
};

WishlistCreate.layout = (page) => <AdminLayout>{page}</AdminLayout>;

export default WishlistCreate;
