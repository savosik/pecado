import { useCallback, useEffect, useMemo, useState } from 'react';
import axios from 'axios';
import { Box, Flex, HStack, Text, Heading, VStack, Wrap, WrapItem, Input, InputGroup, Spinner } from '@chakra-ui/react';
import { LuBell, LuMail, LuPlus, LuX } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field } from '@/components/ui/field';
import { toaster } from '@/components/ui/toaster';

/**
 * SubscriptionPanel — универсальная форма подписки на изменения сущностей
 * раздела кабинета. Дроп-ин: достаточно вставить <SubscriptionPanel
 * section="orders" /> на странице раздела.
 *
 * Компонент самодостаточен — сам ходит на /cabinet/subscriptions/{section}
 * (GET/POST) и /cabinet/subscriptions/{id} (PATCH/DELETE), контроллеры
 * разделов править не нужно. Раздел должен быть зарегистрирован в
 * config/subscriptions.php.
 *
 * Если у раздела заведены типы событий (sections.{section}.events), для
 * каждого адреса можно выбрать, какие именно уведомления он получает.
 * Пустой `events` у подписки = все типы, включая будущие.
 *
 * @param {{ section: string, title?: string, description?: string }} props
 */
export default function SubscriptionPanel({ section, title = 'Подписка на изменения', description }) {
    const [subscriptions, setSubscriptions] = useState([]);
    const [eventCatalog, setEventCatalog] = useState([]);
    const [email, setEmail] = useState('');
    const [newEvents, setNewEvents] = useState(null); // null — все типы
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [savingId, setSavingId] = useState(null);
    const [error, setError] = useState('');
    const [max, setMax] = useState(5);

    const reload = useCallback(async () => {
        setLoading(true);
        try {
            const { data } = await axios.get(`/cabinet/subscriptions/${section}`);
            setSubscriptions((data.data || []).filter((s) => s.channel === 'email'));
            setEventCatalog(data.events || []);
            if (data.max) setMax(data.max);
        } catch (e) {
            console.error('Не удалось загрузить подписки:', e);
            setSubscriptions([]);
        } finally {
            setLoading(false);
        }
    }, [section]);

    const limitReached = subscriptions.length >= max;
    const allEventKeys = useMemo(() => eventCatalog.map((e) => e.value), [eventCatalog]);
    const hasEvents = eventCatalog.length > 0;

    useEffect(() => {
        reload();
    }, [reload]);

    /** Пустой список у подписки означает «все типы». */
    const eventsOf = (subscription) => (
        Array.isArray(subscription.events) && subscription.events.length > 0
            ? subscription.events
            : allEventKeys
    );

    const selectedNewEvents = newEvents ?? allEventKeys;

    const toggleNewEvent = (value, checked) => {
        const next = checked
            ? [...selectedNewEvents, value].filter((v, i, arr) => arr.indexOf(v) === i)
            : selectedNewEvents.filter((v) => v !== value);

        if (next.length === 0) {
            setError('Выберите хотя бы один тип уведомлений.');
            return;
        }
        setError('');
        setNewEvents(next);
    };

    const add = async (e) => {
        e?.preventDefault?.();
        const value = email.trim();
        if (!value) return;
        setSaving(true);
        setError('');
        try {
            await axios.post(`/cabinet/subscriptions/${section}`, {
                email: value,
                ...(hasEvents ? { events: selectedNewEvents } : {}),
            });
            setEmail('');
            setNewEvents(null);
            await reload();
        } catch (err) {
            const msg = err?.response?.data?.errors?.email?.[0]
                || err?.response?.data?.errors?.events?.[0]
                || err?.response?.data?.message
                || 'Не удалось добавить адрес.';
            setError(msg);
        } finally {
            setSaving(false);
        }
    };

    const toggleEvent = async (subscription, value, checked) => {
        const current = eventsOf(subscription);
        const next = checked
            ? allEventKeys.filter((k) => current.includes(k) || k === value)
            : current.filter((v) => v !== value);

        if (next.length === 0) {
            toaster.create({
                title: 'Выберите хотя бы один тип уведомлений',
                description: 'Чтобы адрес совсем не получал писем, удалите его из списка.',
                type: 'warning',
            });
            return;
        }

        const previous = subscriptions;
        // Оптимистично — чекбокс должен реагировать мгновенно.
        setSubscriptions((prev) => prev.map((s) => (s.id === subscription.id ? { ...s, events: next } : s)));
        setSavingId(subscription.id);

        try {
            const { data } = await axios.patch(`/cabinet/subscriptions/${subscription.id}`, { events: next });
            setSubscriptions((prev) => prev.map((s) => (s.id === subscription.id ? { ...s, ...data.data } : s)));
        } catch (err) {
            setSubscriptions(previous);
            toaster.create({
                title: err?.response?.data?.errors?.events?.[0] || 'Не удалось сохранить типы уведомлений',
                type: 'error',
            });
        } finally {
            setSavingId(null);
        }
    };

    const remove = async (subscription) => {
        try {
            await axios.delete(`/cabinet/subscriptions/${subscription.id}`);
            setSubscriptions((prev) => prev.filter((s) => s.id !== subscription.id));
        } catch (e) {
            console.error('Не удалось удалить подписку:', e);
            toaster.create({ title: 'Не удалось удалить адрес', type: 'error' });
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

            {/* Список подписанных адресов — у каждого свой набор типов уведомлений */}
            {subscriptions.length > 0 && (
                <VStack align="stretch" gap="2" mb="4">
                    {subscriptions.map((s) => {
                        const selected = eventsOf(s);
                        return (
                            <Box
                                key={s.id}
                                bg="bg.muted"
                                borderRadius="lg"
                                border="1px solid"
                                borderColor="border.muted"
                                px="3"
                                py="2.5"
                            >
                                <Flex align="center" gap="2">
                                    <Box color="gray.500"><LuMail size={14} /></Box>
                                    <Text fontSize="sm" fontWeight="600" flex="1" wordBreak="break-all">
                                        {s.destination}
                                    </Text>
                                    {savingId === s.id && <Spinner size="xs" color="gray.400" />}
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
                                        flexShrink="0"
                                    >
                                        <LuX size={14} />
                                    </Box>
                                </Flex>

                                {hasEvents && (
                                    <Wrap gap="3" mt="2" pl="6">
                                        {eventCatalog.map((event) => (
                                            <WrapItem key={event.value}>
                                                <Checkbox
                                                    size="sm"
                                                    colorPalette="pecado"
                                                    checked={selected.includes(event.value)}
                                                    disabled={savingId === s.id}
                                                    onCheckedChange={(e) => toggleEvent(s, event.value, !!e.checked)}
                                                    title={event.description || undefined}
                                                >
                                                    <Text fontSize="xs">{event.label}</Text>
                                                </Checkbox>
                                            </WrapItem>
                                        ))}
                                    </Wrap>
                                )}
                            </Box>
                        );
                    })}
                </VStack>
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

                {hasEvents && (
                    <Box mt="3">
                        <Text fontSize="xs" color="gray.500" mb="1.5">
                            Какие уведомления получает этот адрес:
                        </Text>
                        <Wrap gap="3">
                            {eventCatalog.map((event) => (
                                <WrapItem key={event.value}>
                                    <Checkbox
                                        size="sm"
                                        colorPalette="pecado"
                                        checked={selectedNewEvents.includes(event.value)}
                                        onCheckedChange={(e) => toggleNewEvent(event.value, !!e.checked)}
                                        title={event.description || undefined}
                                    >
                                        <Text fontSize="xs">{event.label}</Text>
                                    </Checkbox>
                                </WrapItem>
                            ))}
                        </Wrap>
                    </Box>
                )}
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
