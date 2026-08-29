import { useEffect, useMemo, useRef, useState } from 'react';
import axios from 'axios';
import { Box, Flex, HStack, Image, Input, NativeSelect, SimpleGrid, Stack, Text, Badge } from '@chakra-ui/react';
import { LuArrowRight, LuDownload, LuFileCode2, LuSend } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Checkbox } from '@/components/ui/checkbox';
import { Alert } from '@/components/ui/alert';

const rub = (value) => `${Number(value || 0).toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ₽`;

const toQuery = (params) => new URLSearchParams(
    Object.entries(params).filter(([, v]) => v !== null && v !== undefined && v !== ''),
).toString();

/**
 * Платёжка «бери и плати» (pay-01) — одна форма для кабинета и CRM.
 *
 * Пара «контрагент × наше юрлицо» → сценарий суммы → предпросмотр
 * (назначение, реквизиты, QR) → скачать PDF / файл клиент-банка / отправить бухгалтеру.
 *
 * @param {object} options — из PaymentOrderService::options()
 * @param {(params: object) => string} previewUrl
 * @param {(params: object, format: 'pdf'|'txt') => string} downloadUrl
 * @param {(payload: object) => Promise<void>} onSend — payload: {...params, email, save_contact}
 * @param {boolean} compact — без внешних карточек (для диалога)
 * @param {{pairKey: string, scenario?: string, entryId?: number|string}|null} preset —
 *   предустановка из календаря: пара и документ выбраны заранее, форма их не сбрасывает.
 *   Если пары или документа среди непогашенных нет — форма открывается как обычно.
 */
export default function PaymentOrderForm({ options, previewUrl, downloadUrl, onSend, compact = false, preset = null }) {
    const pairs = options?.pairs || [];
    const scenarios = options?.scenarios || [];
    const contacts = options?.contacts || [];

    // Предустановка применяется один раз при первом рендере пары; дальше
    // пользователь волен переключать пару и сценарий, как обычно.
    const presetRef = useRef(preset && pairs.some((p) => p.key === preset.pairKey) ? preset : null);

    const [pairKey, setPairKey] = useState(presetRef.current?.pairKey || pairs[0]?.key || '');
    const pair = useMemo(() => pairs.find((p) => p.key === pairKey) || null, [pairs, pairKey]);
    const [scenario, setScenario] = useState(presetRef.current?.scenario || (pair?.overdue > 0 ? 'overdue' : 'all'));
    const [entryId, setEntryId] = useState(presetRef.current?.entryId ? String(presetRef.current.entryId) : '');
    const [amount, setAmount] = useState('');
    const [preview, setPreview] = useState(null);
    const [error, setError] = useState(null);
    const [loading, setLoading] = useState(false);

    const [email, setEmail] = useState('');
    const [contactId, setContactId] = useState('');
    const [saveContact, setSaveContact] = useState(true);
    const [sending, setSending] = useState(false);
    const [sent, setSent] = useState(null);

    useEffect(() => {
        if (!pair) return;
        const fixed = presetRef.current;
        presetRef.current = null;
        const presetDocument = fixed?.entryId
            && (pair.documents || []).some((d) => String(d.id) === String(fixed.entryId));

        if (fixed && presetDocument) {
            setScenario(fixed.scenario || 'document');
            setEntryId(String(fixed.entryId));
        } else if (fixed && fixed.scenario && fixed.scenario !== 'document') {
            // Предустановка сценария без документа (с дашборда: просрочка / весь долг).
            setScenario(fixed.scenario);
            setEntryId(pair.documents?.[0]?.id ? String(pair.documents[0].id) : '');
        } else {
            setScenario(pair.overdue > 0 ? 'overdue' : 'all');
            setEntryId(pair.documents?.[0]?.id ? String(pair.documents[0].id) : '');
        }
        setSent(null);
        // Бухгалтер этого юрлица — первым.
        const accountant = contacts.find((c) => c.is_accountant && (c.company_id === pair.company_id || c.company_id === null))
            || contacts.find((c) => c.is_accountant);
        if (accountant) {
            setContactId(String(accountant.id));
            setEmail(accountant.email);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [pairKey]);

    const params = useMemo(() => ({
        company_id: pair?.company_id,
        organization_id: pair?.organization_id,
        scenario,
        entry_id: scenario === 'document' ? entryId : undefined,
        amount: scenario === 'custom' ? amount : undefined,
    }), [pair, scenario, entryId, amount]);

    const ready = !!pair && (scenario !== 'document' || !!entryId) && (scenario !== 'custom' || Number(amount) > 0);

    useEffect(() => {
        if (!ready) { setPreview(null); return undefined; }
        let cancelled = false;
        setLoading(true);
        setError(null);
        const timer = setTimeout(() => {
            axios.get(previewUrl(params))
                .then(({ data }) => { if (!cancelled) setPreview(data); })
                .catch((e) => { if (!cancelled) { setPreview(null); setError(e?.response?.data?.message || 'Не удалось собрать платёжку'); } })
                .finally(() => { if (!cancelled) setLoading(false); });
        }, 250);
        return () => { cancelled = true; clearTimeout(timer); };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [ready, JSON.stringify(params)]);

    const pickContact = (value) => {
        setContactId(value);
        const contact = contacts.find((c) => String(c.id) === value);
        if (contact) setEmail(contact.email);
    };

    const send = async () => {
        if (!ready || !email) return;
        setSending(true);
        setSent(null);
        try {
            await onSend({ ...params, email, save_contact: saveContact && !contactId });
            setSent(email);
        } catch (e) {
            setError(e?.response?.data?.message || e?.message || 'Не удалось отправить');
        } finally {
            setSending(false);
        }
    };

    if (pairs.length === 0) {
        return (
            <Alert status="info" title="Платить нечего">
                По регистру взаиморасчётов непогашенных документов нет. Когда появятся — здесь будет готовая платёжка.
            </Alert>
        );
    }

    const card = compact
        ? { p: '0' }
        : { borderRadius: 'xl', border: '1px solid', borderColor: 'border.muted', bg: 'bg', p: '4' };

    return (
        <Stack gap="4">
            <Box {...card}>
                <Stack gap="4">
                    <Field label="Кто платит и кому">
                        {/* Плитки, а не выпадающий список: клиент должен видеть сразу
                            все пары с просрочкой, а не только выбранную. */}
                        <SimpleGrid columns={{ base: 1, md: pairs.length > 1 ? 2 : 1 }} gap="2" w="100%">
                            {pairs.map((p) => {
                                const active = p.key === pairKey;

                                return (
                                    <Box
                                        key={p.key}
                                        as="button"
                                        type="button"
                                        textAlign="left"
                                        onClick={() => setPairKey(p.key)}
                                        p="3"
                                        borderRadius="lg"
                                        border="2px solid"
                                        borderColor={active ? 'red.500' : 'border.muted'}
                                        bg="bg"
                                        _dark={{ borderColor: active ? 'red.400' : 'border.muted' }}
                                        _hover={{ borderColor: active ? 'red.500' : 'border.emphasized' }}
                                        transition="all 0.15s"
                                        cursor="pointer"
                                    >
                                        <Text fontWeight="600" fontSize="sm" color="fg" lineHeight="short">
                                            {p.company_name}
                                        </Text>
                                        <HStack gap="1" fontSize="xs" color="fg.muted" mt="0.5">
                                            <LuArrowRight size={12} />
                                            <Text>{p.organization_name}</Text>
                                        </HStack>
                                        <HStack gap="3" mt="2" wrap="wrap" fontSize="xs">
                                            {p.overdue > 0
                                                ? <Text color="red.fg" fontWeight="600">Просрочено {rub(p.overdue)}</Text>
                                                : <Text color="green.fg">Просрочки нет</Text>}
                                            {p.debt > 0 && <Text color="fg.muted">Долг {rub(p.debt)}</Text>}
                                            {!p.requisites_ok && <Badge colorPalette="orange" size="sm">нет реквизитов</Badge>}
                                        </HStack>
                                    </Box>
                                );
                            })}
                        </SimpleGrid>
                    </Field>

                    {pair && !pair.requisites_ok && (
                        <Alert status="warning" title="У нашего юрлица не заполнены банковские реквизиты">
                            Платёжку по этой паре собрать нельзя — обратитесь к менеджеру.
                        </Alert>
                    )}

                    <Field label="Что оплатить">
                        <HStack gap="2" wrap="wrap">
                            {scenarios.map((s) => {
                                const disabled = (s.value === 'overdue' && !(pair?.overdue > 0))
                                    || (s.value === 'all' && !(pair?.debt > 0 || pair?.documents?.length))
                                    || (s.value === 'document' && !(pair?.documents?.length));
                                const suffix = s.value === 'overdue' && pair?.overdue > 0 ? ` · ${rub(pair.overdue)}`
                                    : s.value === 'all' && pair?.debt > 0 ? ` · ${rub(pair.debt)}` : '';
                                const active = scenario === s.value;

                                // Выбор — красной рамкой, как у плиток пары: заливка
                                // читалась как «кнопка действия», а не как выбранный вариант.
                                return (
                                    <Button
                                        key={s.value}
                                        size="sm"
                                        variant="outline"
                                        colorPalette={active ? 'red' : 'gray'}
                                        borderWidth="2px"
                                        borderColor={active ? 'red.500' : 'border.muted'}
                                        color={active ? 'red.fg' : 'fg'}
                                        fontWeight={active ? '600' : '500'}
                                        _dark={{ borderColor: active ? 'red.400' : 'border.muted' }}
                                        disabled={disabled}
                                        onClick={() => setScenario(s.value)}
                                    >
                                        {s.label}{suffix}
                                    </Button>
                                );
                            })}
                        </HStack>
                    </Field>

                    {scenario === 'document' && (
                        <Field label="Документ">
                            <NativeSelect.Root size="sm">
                                <NativeSelect.Field value={entryId} onChange={(e) => setEntryId(e.target.value)}>
                                    {(pair?.documents || []).map((d) => (
                                        <option key={d.id} value={String(d.id)}>
                                            № {d.number}{d.date ? ` от ${d.date}` : ''} — {rub(d.amount)}{d.overdue ? ' · просрочен' : d.due ? ` · до ${d.due}` : ''}
                                        </option>
                                    ))}
                                </NativeSelect.Field>
                                <NativeSelect.Indicator />
                            </NativeSelect.Root>
                        </Field>
                    )}

                    {scenario === 'custom' && (
                        <Field label="Сумма, ₽" maxW="220px">
                            <Input type="number" min="1" step="0.01" value={amount} onChange={(e) => setAmount(e.target.value)} placeholder="Например, 50000" />
                        </Field>
                    )}
                </Stack>
            </Box>

            {error && <Alert status="error" title="Не получилось">{error}</Alert>}

            {preview && (
                <Box {...card}>
                    <Flex gap="4" direction={{ base: 'column', md: 'row' }}>
                        <Stack gap="2" flex="1" minW="0" fontSize="sm">
                            <HStack gap="2" wrap="wrap">
                                <Text fontWeight="bold" fontSize="lg" color="fg">{rub(preview.amount)}</Text>
                                <Badge variant="subtle">{preview.scenario_label}</Badge>
                                {loading && <Badge colorPalette="gray" variant="outline">обновляю…</Badge>}
                            </HStack>
                            <Text color="fg.muted">Получатель</Text>
                            <Text color="fg">{preview.payee.legal_name || preview.payee.name}, ИНН {preview.payee.tax_id || '—'}{preview.payee.tax_code ? `, КПП ${preview.payee.tax_code}` : ''}</Text>
                            <Text color="fg">р/с {preview.payee.account_number}, {preview.payee.bank_name}, БИК {preview.payee.bank_bik}</Text>
                            {preview.contract && (
                                <>
                                    <Text color="fg.muted">Основание</Text>
                                    <Text color="fg">{preview.contract.label}</Text>
                                </>
                            )}
                            <Text color="fg.muted">Назначение платежа</Text>
                            <Text color="fg">{preview.purpose}</Text>
                            {!preview.payer.account_number && (
                                <Text fontSize="xs" color="fg.muted">Счёт плательщика в наших данных отсутствует — бухгалтер подставит его в клиент-банке.</Text>
                            )}
                        </Stack>
                        <Stack gap="1" align="center" minW="150px">
                            <Image src={preview.qr} alt="QR для оплаты" boxSize="150px" />
                            <Text fontSize="xs" color="fg.muted">QR для банковского приложения</Text>
                        </Stack>
                    </Flex>

                    <HStack gap="2" wrap="wrap" mt="4">
                        <Button as="a" href={downloadUrl(params, 'pdf')} size="sm" colorPalette="red">
                            <LuDownload /> Скачать PDF
                        </Button>
                        <Button as="a" href={downloadUrl(params, 'txt')} size="sm" variant="outline">
                            <LuFileCode2 /> Файл для клиент-банка
                        </Button>
                    </HStack>

                    <Box mt="5" pt="4" borderTop="1px solid" borderColor="border.muted">
                        <Text fontWeight="semibold" color="fg" mb="2">Отправить бухгалтеру</Text>
                        <Flex gap="3" wrap="wrap" align="flex-end">
                            {contacts.length > 0 && (
                                <Field label="Из адресной книги" maxW="280px">
                                    <NativeSelect.Root size="sm">
                                        <NativeSelect.Field value={contactId} onChange={(e) => pickContact(e.target.value)}>
                                            <option value="">Другой адрес</option>
                                            {contacts.map((c) => (
                                                <option key={c.id} value={String(c.id)}>{c.name}{c.role ? ` (${c.role})` : ''} — {c.email}</option>
                                            ))}
                                        </NativeSelect.Field>
                                        <NativeSelect.Indicator />
                                    </NativeSelect.Root>
                                </Field>
                            )}
                            <Field label="E-mail" maxW="300px">
                                <Input
                                    size="sm"
                                    type="email"
                                    value={email}
                                    onChange={(e) => { setEmail(e.target.value); setContactId(''); }}
                                    placeholder="buh@company.ru"
                                />
                            </Field>
                            {!contactId && (
                                <Checkbox size="sm" checked={saveContact} onCheckedChange={(e) => setSaveContact(!!e.checked)}>
                                    Запомнить как бухгалтера
                                </Checkbox>
                            )}
                            <Button size="sm" colorPalette="green" onClick={send} loading={sending} disabled={!email || !ready}>
                                <LuSend /> Отправить
                            </Button>
                        </Flex>
                        {sent && <Text fontSize="sm" color="green.fg" mt="2">Отправлено на {sent}: PDF и файл для клиент-банка во вложении.</Text>}
                    </Box>
                </Box>
            )}
        </Stack>
    );
}
