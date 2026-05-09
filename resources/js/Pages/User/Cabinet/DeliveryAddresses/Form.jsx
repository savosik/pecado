import { useMemo, useState } from 'react';
import {
    Box, Flex, VStack, Text, Input, Textarea, Button, Card, Field,
} from '@chakra-ui/react';
import { Head, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import CabinetLayout from '../CabinetLayout';
import { LuSave, LuArrowLeft } from 'react-icons/lu';
import { toaster } from '@/components/ui/toaster';
import { AddressSuggest } from '@/components/common/AddressSuggest';
import { YandexAddressMap } from '@/components/common/YandexAddressMap';

const parseCoords = (data) => {
    if (!data) return null;
    const lat = parseFloat(data.geo_lat);
    const lon = parseFloat(data.geo_lon);
    if (!Number.isFinite(lat) || !Number.isFinite(lon)) return null;
    return [lat, lon];
};

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
    const [reverseLoading, setReverseLoading] = useState(false);

    const coords = useMemo(() => parseCoords(form.address_data), [form.address_data]);

    const handleChange = (field, value) => {
        setForm(prev => ({ ...prev, [field]: value }));
        setErrors(prev => ({ ...prev, [field]: undefined }));
    };

    const handleAddressSelected = (suggestion) => {
        setForm(prev => ({
            ...prev,
            address: suggestion?.unrestricted_value || suggestion?.value || prev.address,
            address_data: suggestion?.data ?? null,
        }));
        setErrors(prev => ({ ...prev, address: undefined }));
    };

    const handleMapPick = async ([lat, lon]) => {
        if (reverseLoading) return;
        setReverseLoading(true);
        try {
            const { data } = await axios.post('/api/dadata/geolocate/address', {
                lat,
                lon,
                count: 1,
                radius_meters: 200,
            });
            const item = data?.suggestions?.[0];
            if (!item) {
                toaster.create({
                    title: 'Адрес рядом не найден',
                    description: 'Попробуйте кликнуть ближе к нужному дому.',
                    type: 'info',
                });
                return;
            }
            setForm(prev => ({
                ...prev,
                address: item.unrestricted_value || item.value || prev.address,
                address_data: item.data ?? null,
            }));
            setErrors(prev => ({ ...prev, address: undefined }));
        } catch (err) {
            const status = err?.response?.status;
            toaster.create({
                title: status === 503 ? 'Сервис подсказок временно недоступен' : 'Не удалось определить адрес',
                type: 'error',
            });
        } finally {
            setReverseLoading(false);
        }
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
                                <AddressSuggest
                                    value={form.address}
                                    onChange={(val) => handleChange('address', val)}
                                    onAddressSelected={handleAddressSelected}
                                    invalid={!!errors.address}
                                    placeholder="Введите город, улицу, дом"
                                />
                                <Field.HelperText fontSize="xs">
                                    Подсказки с привязкой к ФИАС, индексу и координатам. Уточнить точку можно кликом или перетаскиванием метки на карте.
                                </Field.HelperText>
                                {errors.address && <Field.ErrorText>{errors.address}</Field.ErrorText>}
                            </Field.Root>

                            <Box>
                                <YandexAddressMap
                                    coords={coords}
                                    onCoordsPicked={handleMapPick}
                                    height={320}
                                    disabled={reverseLoading}
                                />
                            </Box>

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
