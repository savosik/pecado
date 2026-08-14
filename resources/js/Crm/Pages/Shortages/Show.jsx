import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import {
    Badge,
    Box,
    Dialog,
    Flex,
    HStack,
    Image,
    Input,
    Portal,
    SimpleGrid,
    Text,
    Textarea,
    VStack,
} from '@chakra-ui/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field } from '@/components/ui/field';
import { Alert } from '@/components/ui/alert';
import { ProductSelector } from '@/Admin/Components/ProductSelector';
import CallDialog from '@/Crm/Components/CallDialog';
import { toastError, toastSuccess } from '@/utils/toast';
import { LuArrowLeft, LuExternalLink, LuMailCheck, LuPhone, LuTimer, LuX } from 'react-icons/lu';

const money = (value) =>
    new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 }).format(value || 0);

/**
 * Карточка недобора: всё решение на одном экране.
 *
 * Слева — строки недобора с кандидатами (галочка = кандидат виден клиенту),
 * справа — контекст клиента и черновик письма. Четыре исхода одной кнопкой.
 */
const isEmail = (value) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);

export default function Show({ offer, draft, recipientOptions, outboundEnabled }) {
    const [subject, setSubject] = useState(draft?.subject || '');
    const [body, setBody] = useState(draft?.body_html || '');
    const [recipients, setRecipients] = useState(draft?.to || []);
    const [recipientInput, setRecipientInput] = useState('');
    const [recipientFocus, setRecipientFocus] = useState(false);
    const [busy, setBusy] = useState(false);
    const [callOpen, setCallOpen] = useState(false);
    const [dismissOpen, setDismissOpen] = useState(false);
    const [dismissReason, setDismissReason] = useState('');

    const reload = () => router.reload();

    // Состав кандидатов поменялся — письмо перегенерируется на сервере,
    // текст в карточке сразу отражает актуальную подборку.
    const refreshDraft = async () => {
        try {
            const { data } = await axios.post(`/crm/shortages/${offer.id}/draft/refresh`);
            setSubject(data.subject || '');
            setBody(data.body_html || '');
        } catch {
            // Не критично: после reload() черновик подтянется с пропсами.
        }
    };

    const toggleCandidate = async (candidate) => {
        try {
            if (candidate.removed) {
                await axios.post(`/crm/shortages/${offer.id}/candidates/${candidate.id}/restore`);
            } else {
                await axios.delete(`/crm/shortages/${offer.id}/candidates/${candidate.id}`);
            }
            await refreshDraft();
            reload();
        } catch (e) {
            toastError(e.response?.data?.message || 'Не удалось изменить кандидата');
        }
    };

    const addCandidate = async (line, product) => {
        try {
            await axios.post(`/crm/shortages/${offer.id}/candidates`, {
                source_order_item_id: line.id,
                product_id: product.id,
            });
            toastSuccess('Кандидат добавлен, связь записана в справочник');
            await refreshDraft();
            reload();
        } catch (e) {
            toastError(e.response?.data?.message || 'Не удалось добавить кандидата');
        }
    };

    const addRecipient = (value) => {
        const email = String(value || '').trim().toLowerCase().replace(/[,;]+$/, '');
        if (!email) {
            return;
        }
        if (!isEmail(email)) {
            toastError(`Адрес «${email}» не похож на email`);
            return;
        }
        setRecipients((prev) => (prev.includes(email) ? prev : [...prev, email]));
        setRecipientInput('');
    };

    const removeRecipient = (email) => {
        setRecipients((prev) => prev.filter((item) => item !== email));
    };

    const recipientSuggestions = (recipientOptions || []).filter(
        (option) => !recipients.includes(option.email)
            && (!recipientInput.trim() || option.email.includes(recipientInput.trim().toLowerCase())),
    );

    const submitOutcome = async (type, extra = {}) => {
        setBusy(true);
        try {
            await axios.post(`/crm/shortages/${offer.id}/outcome`, { type, ...extra });
            toastSuccess({
                send: 'Подборка отправлена клиенту',
                wait: 'Письмо с предложением подождать отправлено',
                call: 'Исход «позвонить» зафиксирован, задача закрыта',
                dismiss: 'Подборка закрыта без замены',
            }[type]);
            reload();
        } catch (e) {
            toastError(e.response?.data?.message || 'Не удалось выполнить действие');
        } finally {
            setBusy(false);
            setDismissOpen(false);
        }
    };

    const repeatShortage = offer.client.previous_shortages_quarter > 0;

    return (
        <>
            <Head title={`Недобор по заказу ${offer.order.number} — CRM`} />

            <HStack mb={2}>
                <Link href="/crm/shortages">
                    <Button size="xs" variant="ghost">
                        <LuArrowLeft /> К очереди
                    </Button>
                </Link>
            </HStack>

            <PageHeader
                title={`Недобор по заказу ${offer.order.number}`}
                description={`Отмена строк в 1С: ${offer.created_at}`}
                actions={(
                    <HStack>
                        <Badge colorPalette={offer.status_color} variant="subtle" size="lg">
                            {offer.status_label}
                        </Badge>
                        {offer.client_page_url && (
                            <a href={offer.client_page_url} target="_blank" rel="noreferrer">
                                <Button size="xs" variant="outline">
                                    <LuExternalLink /> Страница клиента
                                </Button>
                            </a>
                        )}
                    </HStack>
                )}
            />

            {offer.is_open && (
                <Alert status="warning" title="Посылка уже собрана — отгрузка скоро" mb={4}>
                    Письмо с подборкой лучше отправить сегодня: клиент узнает о недоборе из коробки,
                    а его покупатель уже внёс предоплату.
                </Alert>
            )}

            {offer.status === 'dismissed' && (
                <Alert status="neutral" title="Закрыто без замены" mb={4}>
                    Причина: {offer.dismiss_reason || 'не указана'}
                </Alert>
            )}

            {offer.status === 'confirmed' && (
                <Alert status="success" title="Клиент согласовал замену" mb={4}>
                    {offer.confirmed_at} — созданы заказы-замены
                    {(offer.result_order_ids || []).map((id) => (
                        <Text as="span" key={id} ml={1} fontWeight="medium">№{id}</Text>
                    ))}
                </Alert>
            )}

            <SimpleGrid columns={{ base: 1, xl: 2 }} gap={6} alignItems="start">
                {/* Левая колонка: строки недобора и кандидаты */}
                <VStack align="stretch" gap={4}>
                    {offer.lines.map((line) => (
                        <Box key={line.id} borderWidth="1px" borderRadius="lg" p={4}>
                            <Flex justify="space-between" gap={3} mb={3}>
                                <HStack gap={3}>
                                    {line.image_url && (
                                        <Image src={line.image_url} alt="" boxSize="48px" objectFit="cover" borderRadius="md" />
                                    )}
                                    <Box>
                                        <Text fontWeight="medium" textDecoration="line-through" color="fg.muted">
                                            {line.name}
                                        </Text>
                                        <Text fontSize="sm" color="fg.muted">
                                            {line.quantity} шт. · потеря {money(line.subtotal)} ₽
                                            {line.sku ? ` · ${line.sku}` : ''}
                                        </Text>
                                    </Box>
                                </HStack>
                            </Flex>

                            {line.preorder_stock > 0 && (
                                <Alert status="info" size="sm" title="Можно предложить подождать" mb={3}>
                                    На предзаказных складах региона клиента доступно {line.preorder_stock} шт.
                                </Alert>
                            )}

                            <VStack align="stretch" gap={2}>
                                {line.candidates.length === 0 && (
                                    <Text fontSize="sm" color="fg.muted">
                                        Кандидатов пока нет — добавьте через поиск ниже.
                                    </Text>
                                )}
                                {line.candidates.map((candidate) => (
                                    <HStack
                                        key={candidate.id}
                                        gap={3}
                                        p={2}
                                        borderWidth="1px"
                                        borderRadius="md"
                                        opacity={candidate.removed ? 0.5 : 1}
                                        bg={candidate.is_defect ? 'orange.subtle' : 'transparent'}
                                    >
                                        {offer.is_open ? (
                                            <Checkbox
                                                checked={!candidate.removed}
                                                onCheckedChange={() => toggleCandidate(candidate)}
                                                aria-label="Показывать кандидата клиенту"
                                            />
                                        ) : (
                                            candidate.chosen && <Badge colorPalette="green">выбран</Badge>
                                        )}
                                        {candidate.image_url && (
                                            <Image src={candidate.image_url} alt="" boxSize="40px" objectFit="cover" borderRadius="md" />
                                        )}
                                        <Box flex="1">
                                            <HStack gap={2}>
                                                <Text fontSize="sm" fontWeight="medium">{candidate.name}</Text>
                                                <Badge size="xs" variant="outline" colorPalette={candidate.is_defect ? 'orange' : 'gray'}>
                                                    {candidate.kind_label}
                                                </Badge>
                                            </HStack>
                                            <Text fontSize="xs" color="fg.muted">
                                                {candidate.reason}
                                                {candidate.is_defect && candidate.defect_description
                                                    ? ` — ${candidate.defect_description}`
                                                    : ''}
                                            </Text>
                                        </Box>
                                        <Box textAlign="right">
                                            <Text fontSize="sm" fontWeight="medium">{money(candidate.price)} ₽</Text>
                                            <Text fontSize="xs" color={candidate.available >= line.quantity ? 'fg.muted' : 'orange.fg'}>
                                                остаток {candidate.available}
                                            </Text>
                                        </Box>
                                    </HStack>
                                ))}
                            </VStack>

                            {offer.is_open && (
                                <Box mt={3}>
                                    <ProductSelector
                                        mode="search"
                                        searchRoute="crm.shortages.products.search"
                                        onSelect={(product) => addCandidate(line, product)}
                                        compactSelected
                                    />
                                </Box>
                            )}
                        </Box>
                    ))}
                </VStack>

                {/* Правая колонка: клиент и письмо */}
                <VStack align="stretch" gap={4}>
                    <Box borderWidth="1px" borderRadius="lg" p={4}>
                        <Text fontWeight="bold" mb={2}>Клиент</Text>
                        <VStack align="stretch" gap={1} fontSize="sm">
                            <HStack justify="space-between">
                                <Text color="fg.muted">Имя</Text>
                                <Text fontWeight="medium">{offer.client.name}</Text>
                            </HStack>
                            <HStack justify="space-between">
                                <Text color="fg.muted">Оборот за год</Text>
                                <Text>{money(offer.client.turnover_year)} ₽</Text>
                            </HStack>
                            <HStack justify="space-between">
                                <Text color="fg.muted">Менеджер</Text>
                                <Text>{offer.client.manager}</Text>
                            </HStack>
                            <HStack justify="space-between">
                                <Text color="fg.muted">Недоборы за квартал</Text>
                                {repeatShortage ? (
                                    <Badge colorPalette="orange" variant="subtle">
                                        {offer.client.previous_shortages_quarter + 1}-й — лучше позвонить
                                    </Badge>
                                ) : (
                                    <Text>первый</Text>
                                )}
                            </HStack>
                            <HStack justify="space-between">
                                <Text color="fg.muted">Доставка</Text>
                                <Text>{offer.order.delivery_method || '—'}</Text>
                            </HStack>
                        </VStack>
                    </Box>

                    <Box borderWidth="1px" borderRadius="lg" p={4}>
                        <HStack justify="space-between" mb={2}>
                            <Text fontWeight="bold">Письмо клиенту</Text>
                            {offer.sent_at && (
                                <Badge colorPalette="green" variant="subtle">отправлено {offer.sent_at}</Badge>
                            )}
                        </HStack>
                        {!outboundEnabled && (
                            <Alert status="warning" size="sm" mb={3} title="Отправка писем из CRM выключена">
                                Включается флагом MAIL_FEATURE_CRM_OUTBOUND. Черновик можно править, кнопка отправки вернёт ошибку.
                            </Alert>
                        )}
                        <VStack align="stretch" gap={3}>
                            <Field
                                label="Кому"
                                helperText={offer.is_open
                                    ? 'Подставлены адрес клиента и адреса из его подписок на изменения заказов. Добавить свой — Enter или запятая.'
                                    : undefined}
                            >
                                <Box width="100%">
                                    {recipients.length > 0 && (
                                        <HStack wrap="wrap" gap={1} mb={2}>
                                            {recipients.map((email) => (
                                                <Badge key={email} variant="subtle" colorPalette="blue" size="sm">
                                                    {email}
                                                    {offer.is_open && (
                                                        <Box
                                                            as="button"
                                                            type="button"
                                                            ml={1}
                                                            cursor="pointer"
                                                            aria-label={`Убрать ${email}`}
                                                            onClick={() => removeRecipient(email)}
                                                        >
                                                            <LuX size={10} />
                                                        </Box>
                                                    )}
                                                </Badge>
                                            ))}
                                        </HStack>
                                    )}
                                    {offer.is_open && (
                                        <Box position="relative">
                                            <Input
                                                size="sm"
                                                placeholder="Добавить адрес…"
                                                value={recipientInput}
                                                onChange={(e) => setRecipientInput(e.target.value)}
                                                onKeyDown={(e) => {
                                                    if (e.key === 'Enter' || e.key === ',' || e.key === ';') {
                                                        e.preventDefault();
                                                        addRecipient(recipientInput);
                                                    }
                                                }}
                                                onFocus={() => setRecipientFocus(true)}
                                                onBlur={() => {
                                                    setRecipientFocus(false);
                                                    if (recipientInput.trim()) {
                                                        addRecipient(recipientInput);
                                                    }
                                                }}
                                            />
                                            {recipientFocus && recipientSuggestions.length > 0 && (
                                                <Box
                                                    position="absolute"
                                                    top="100%"
                                                    left={0}
                                                    right={0}
                                                    zIndex={1000}
                                                    bg="bg"
                                                    boxShadow="lg"
                                                    borderRadius="md"
                                                    borderWidth="1px"
                                                    mt={1}
                                                    maxHeight="200px"
                                                    overflowY="auto"
                                                >
                                                    {recipientSuggestions.map((option) => (
                                                        <HStack
                                                            key={option.email}
                                                            px={3}
                                                            py={2}
                                                            justify="space-between"
                                                            cursor="pointer"
                                                            _hover={{ bg: 'bg.muted' }}
                                                            onMouseDown={(e) => {
                                                                e.preventDefault();
                                                                addRecipient(option.email);
                                                            }}
                                                        >
                                                            <Text fontSize="sm">{option.email}</Text>
                                                            <Text fontSize="xs" color="fg.muted">{option.label}</Text>
                                                        </HStack>
                                                    ))}
                                                </Box>
                                            )}
                                        </Box>
                                    )}
                                </Box>
                            </Field>
                            <Field label="Тема">
                                <Input
                                    size="sm"
                                    value={subject}
                                    onChange={(e) => setSubject(e.target.value)}
                                    disabled={!offer.is_open}
                                />
                            </Field>
                            <Field label="Текст (HTML)">
                                <Textarea
                                    rows={10}
                                    fontSize="sm"
                                    fontFamily="mono"
                                    value={body}
                                    onChange={(e) => setBody(e.target.value)}
                                    disabled={!offer.is_open}
                                />
                            </Field>
                        </VStack>
                    </Box>

                    {offer.is_open && (
                        <Box borderWidth="1px" borderRadius="lg" p={4}>
                            <Text fontWeight="bold" mb={3}>Исход</Text>
                            <SimpleGrid columns={{ base: 1, sm: 2 }} gap={2}>
                                <Button
                                    colorPalette="green"
                                    loading={busy}
                                    onClick={() => submitOutcome('send', { subject, body_html: body, to: recipients })}
                                >
                                    <LuMailCheck /> Отправить подборку
                                </Button>
                                <Button
                                    variant="outline"
                                    loading={busy}
                                    onClick={() => submitOutcome('wait', { subject, body_html: body, to: recipients })}
                                >
                                    <LuTimer /> Предложить подождать
                                </Button>
                                <Button variant="outline" onClick={() => setCallOpen(true)}>
                                    <LuPhone /> Позвонить
                                </Button>
                                <Button
                                    variant="outline"
                                    colorPalette="red"
                                    onClick={() => setDismissOpen(true)}
                                >
                                    <LuX /> Закрыть без замены
                                </Button>
                            </SimpleGrid>
                            <Text mt={2} fontSize="xs" color="fg.muted">
                                Любой исход закрывает задачу «Недобор по заказу…». «Позвонить» оставляет подборку открытой —
                                итог звонка фиксируется в диалоге звонка.
                            </Text>
                        </Box>
                    )}
                </VStack>
            </SimpleGrid>

            <CallDialog
                open={callOpen}
                client={offer.client.id ? {
                    id: offer.client.id,
                    name: offer.client.name,
                    phone: offer.client.phone,
                    phone_digits: null,
                } : null}
                onClose={() => setCallOpen(false)}
                onSaved={() => {
                    setCallOpen(false);
                    submitOutcome('call');
                }}
            />

            <Dialog.Root open={dismissOpen} onOpenChange={(e) => { if (!e.open) setDismissOpen(false); }}>
                <Portal>
                    <Dialog.Backdrop />
                    <Dialog.Positioner>
                        <Dialog.Content>
                            <Dialog.Header>
                                <Dialog.Title>Закрыть без замены</Dialog.Title>
                            </Dialog.Header>
                            <Dialog.Body>
                                <Field label="Причина (обязательно)">
                                    <Textarea
                                        rows={3}
                                        value={dismissReason}
                                        onChange={(e) => setDismissReason(e.target.value)}
                                        placeholder="Например: клиент отказался от замены, вернём деньги"
                                    />
                                </Field>
                            </Dialog.Body>
                            <Dialog.Footer>
                                <Button variant="outline" onClick={() => setDismissOpen(false)}>Отмена</Button>
                                <Button
                                    colorPalette="red"
                                    disabled={!dismissReason.trim()}
                                    loading={busy}
                                    onClick={() => submitOutcome('dismiss', { reason: dismissReason })}
                                >
                                    Закрыть подборку
                                </Button>
                            </Dialog.Footer>
                        </Dialog.Content>
                    </Dialog.Positioner>
                </Portal>
            </Dialog.Root>
        </>
    );
}

Show.layout = (page) => <CrmLayout>{page}</CrmLayout>;
