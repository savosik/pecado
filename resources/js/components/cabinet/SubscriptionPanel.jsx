import { useCallback, useEffect, useState } from 'react';
import axios from 'axios';
import { Box, Flex, HStack, Text, Heading, Wrap, WrapItem, Input, InputGroup } from '@chakra-ui/react';
import { LuBell, LuMail, LuPlus, LuX } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';

/**
 * SubscriptionPanel — универсальная форма подписки на изменения сущностей
 * раздела кабинета. Дроп-ин: достаточно вставить <SubscriptionPanel
 * section="orders" /> под списком на Index-странице раздела.
 *
 * Компонент самодостаточен — сам ходит на /cabinet/subscriptions/{section}
 * (GET/POST/DELETE), контроллеры разделов править не нужно. Раздел должен
 * быть зарегистрирован в config/subscriptions.php.
 *
 * @param {{ section: string, title?: string, description?: string }} props
 */
export default function SubscriptionPanel({ section, title = 'Подписка на изменения', description }) {
    const [subscriptions, setSubscriptions] = useState([]);
    const [email, setEmail] = useState('');
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const [max, setMax] = useState(5);

    const reload = useCallback(async () => {
        setLoading(true);
        try {
            const { data } = await axios.get(`/cabinet/subscriptions/${section}`);
            setSubscriptions((data.data || []).filter((s) => s.channel === 'email'));
            if (data.max) setMax(data.max);
        } catch (e) {
            console.error('Не удалось загрузить подписки:', e);
            setSubscriptions([]);
        } finally {
            setLoading(false);
        }
    }, [section]);

    const limitReached = subscriptions.length >= max;

    useEffect(() => {
        reload();
    }, [reload]);

    const add = async (e) => {
        e?.preventDefault?.();
        const value = email.trim();
        if (!value) return;
        setSaving(true);
        setError('');
        try {
            await axios.post(`/cabinet/subscriptions/${section}`, { email: value });
            setEmail('');
            await reload();
        } catch (err) {
            const msg = err?.response?.data?.errors?.email?.[0]
                || err?.response?.data?.message
                || 'Не удалось добавить адрес.';
            setError(msg);
        } finally {
            setSaving(false);
        }
    };

    const remove = async (subscription) => {
        try {
            await axios.delete(`/cabinet/subscriptions/${subscription.id}`);
            setSubscriptions((prev) => prev.filter((s) => s.id !== subscription.id));
        } catch (e) {
            console.error('Не удалось удалить подписку:', e);
        }
    };

    return (
        <Box
            mt="8"
            bg="bg"
            borderRadius="xl"
            border="1px solid"
            borderColor="border.muted"
            p={{ base: '4', md: '5' }}
        >
            <HStack gap="2" mb="1">
                <Box color="pecado.500"><LuBell size={18} /></Box>
                <Heading size="md" fontWeight="700">{title}</Heading>
                <Text fontSize="sm" color="gray.500" fontWeight="500">
                    {subscriptions.length} из {max}
                </Text>
            </HStack>
            <Text color="gray.600" _dark={{ color: 'gray.400' }} fontSize="sm" mb="4">
                {description
                    || 'Добавьте email-адреса, которые будут получать письма об изменениях в этом разделе. Можно указать адреса коллег — например, бухгалтера или менеджера.'}
            </Text>

            {/* Список подписанных адресов */}
            {subscriptions.length > 0 && (
                <Wrap gap="2" mb="4">
                    {subscriptions.map((s) => (
                        <WrapItem key={s.id}>
                            <HStack
                                gap="2"
                                bg="bg.muted"
                                borderRadius="full"
                                pl="3"
                                pr="1.5"
                                py="1"
                                border="1px solid"
                                borderColor="border.muted"
                            >
                                <Box color="gray.500"><LuMail size={13} /></Box>
                                <Text fontSize="sm" fontWeight="500">{s.destination}</Text>
                                <Box
                                    as="button"
                                    type="button"
                                    onClick={() => remove(s)}
                                    color="gray.400"
                                    _hover={{ color: 'red.500' }}
                                    aria-label={`Удалить ${s.destination}`}
                                    display="flex"
                                    alignItems="center"
                                    justifyContent="center"
                                    w="5"
                                    h="5"
                                    borderRadius="full"
                                >
                                    <LuX size={14} />
                                </Box>
                            </HStack>
                        </WrapItem>
                    ))}
                </Wrap>
            )}

            {/* Форма добавления */}
            {limitReached ? (
                <Text fontSize="sm" color="gray.500">
                    Достигнут лимит в {max} адресов. Чтобы добавить новый, удалите один из существующих.
                </Text>
            ) : (
            <Box as="form" onSubmit={add}>
                <Flex gap="2" align="start" direction={{ base: 'column', sm: 'row' }}>
                    <Field flex="1" w="full" invalid={!!error} errorText={error || undefined}>
                        <InputGroup w="full" startElement={<LuMail size={16} />}>
                            <Input
                                type="email"
                                placeholder="email@example.com"
                                value={email}
                                onChange={(e) => { setEmail(e.target.value); setError(''); }}
                                size="sm"
                            />
                        </InputGroup>
                    </Field>
                    <Button
                        type="submit"
                        size="sm"
                        colorPalette="pecado"
                        loading={saving}
                        disabled={loading || !email.trim()}
                        w={{ base: 'full', sm: 'auto' }}
                        flexShrink="0"
                    >
                        <LuPlus size={16} />
                        Подписать email
                    </Button>
                </Flex>
            </Box>
            )}

            {subscriptions.length === 0 && !loading && (
                <Text mt="3" fontSize="xs" color="gray.500">
                    Пока никто не подписан на изменения этого раздела.
                </Text>
            )}
        </Box>
    );
}
