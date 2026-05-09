import { useState } from 'react';
import {
    Box, Flex, VStack, Input, Button, Card, Field,
} from '@chakra-ui/react';
import { Head, router, usePage } from '@inertiajs/react';
import CabinetLayout from '../CabinetLayout';
import { LuSave, LuArrowLeft } from 'react-icons/lu';
import { toaster } from '@/components/ui/toaster';
import { AddressFieldWithMap } from '@/components/common/AddressFieldWithMap';

export default function Form({ address }) {
    const { errors: serverErrors } = usePage().props;
    const isEditing = !!address;

    const [form, setForm] = useState({
        name: address?.name || '',
        address: address?.address || '',
        address_data: address?.address_data || null,
    });
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState(serverErrors || {});

    const handleChange = (field, value) => {
        setForm(prev => ({ ...prev, [field]: value }));
        setErrors(prev => ({ ...prev, [field]: undefined }));
    };

    const validate = () => {
        const errs = {};
        if (!form.name.trim()) errs.name = 'Название обязательно.';
        if (!form.address.trim()) errs.address = 'Адрес обязателен.';
        return errs;
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        const clientErrors = validate();
        if (Object.keys(clientErrors).length > 0) { setErrors(clientErrors); return; }

        setProcessing(true);
        setErrors({});

        const options = {
            preserveScroll: true,
            onSuccess: () => toaster.create({ title: isEditing ? 'Адрес обновлён' : 'Адрес создан', type: 'success' }),
            onError: (errs) => setErrors(errs),
            onFinish: () => setProcessing(false),
        };

        if (isEditing) {
            router.put(`/cabinet/delivery-addresses/${address.id}`, form, options);
        } else {
            router.post('/cabinet/delivery-addresses', form, options);
        }
    };

    return (
        <CabinetLayout title={isEditing ? `Редактирование: ${address.name}` : 'Новый адрес доставки'}>
            <Head title={`${isEditing ? 'Редактирование адреса' : 'Новый адрес доставки'} — Pecado`} />

            <Button
                as="a"
                href="/cabinet/delivery-addresses"
                variant="ghost"
                size="sm"
                mb="4"
                onClick={(e) => { e.preventDefault(); router.visit('/cabinet/delivery-addresses'); }}
            >
                <LuArrowLeft /> Назад к списку
            </Button>

            <Card.Root bg="bg" borderRadius="xl" border="1px solid" borderColor="border.muted">
                <Card.Body p="5">
                    <form onSubmit={handleSubmit}>
                        <VStack gap="4" align="stretch" maxW="600px">
                            <Field.Root invalid={!!errors.name}>
                                <Field.Label fontSize="sm" fontWeight="600">Название *</Field.Label>
                                <Input
                                    value={form.name}
                                    onChange={(e) => handleChange('name', e.target.value)}
                                    placeholder="Дом, Офис, Склад..."
                                />
                                {errors.name && <Field.ErrorText>{errors.name}</Field.ErrorText>}
                            </Field.Root>

                            <Field.Root invalid={!!errors.address}>
                                <Field.Label fontSize="sm" fontWeight="600">Адрес *</Field.Label>
                                <AddressFieldWithMap
                                    value={form.address}
                                    onChange={(val) => handleChange('address', val)}
                                    addressData={form.address_data}
                                    onAddressDataChange={(d) => setForm(prev => ({ ...prev, address_data: d }))}
                                    invalid={!!errors.address}
                                    placeholder="Введите город, улицу, дом"
                                />
                                <Field.HelperText fontSize="xs">
                                    Подсказки с привязкой к ФИАС, индексу и координатам. Если нужно уточнить точку — нажмите иконку карты справа.
                                </Field.HelperText>
                                {errors.address && <Field.ErrorText>{errors.address}</Field.ErrorText>}
                            </Field.Root>

                            <Flex justify="flex-start" pt="2">
                                <Button
                                    type="submit"
                                    bg="#9e1b32"
                                    color="white"
                                    _hover={{ bg: '#7a1527' }}
                                    size="md"
                                    loading={processing}
                                    loadingText="Сохранение..."
                                >
                                    <LuSave /> Сохранить
                                </Button>
                            </Flex>
                        </VStack>
                    </form>
                </Card.Body>
            </Card.Root>
        </CabinetLayout>
    );
}
