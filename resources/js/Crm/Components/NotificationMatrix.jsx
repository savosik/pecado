import { useCallback, useEffect, useMemo, useState } from 'react';
import { Badge, Box, HStack, Text, VStack, Wrap } from '@chakra-ui/react';
import axios from 'axios';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { LuBellOff, LuPlus, LuX } from 'react-icons/lu';
import { toastError, toastSuccess } from '@/utils/toast';

/**
 * Матрица уведомлений: строка на тип, напротив — куда слать.
 *
 * Один компонент на CRM и кабинет: два представления одной настройки развели бы
 * интерфейс и отправку, а это тот самый класс ошибок, из-за которого подсистему
 * переделывают третий раз. Отличается только `endpoints` и `readOnlyKeys`.
 */
export default function NotificationMatrix({
    endpoints,
    canEdit = true,
    intro = null,
    // Какие адресаты вообще можно выбрать. В кабинете сервер принимает от
    // клиента только почту аккаунта и свой адрес — предлагать ему остальное
    // значит показывать кнопки, отвечающие ошибкой.
    allowedTypes = ['login', 'manager', 'contact_role', 'contact', 'email'],
}) {
    const [data, setData] = useState(null);
    const [busy, setBusy] = useState(false);
    const [adding, setAdding] = useState(null);

    const load = useCallback(() => {
        axios.get(endpoints.index)
            .then((res) => setData(res.data))
            .catch(() => toastError('Не удалось загрузить настройки уведомлений'));
    }, [endpoints.index]);

    useEffect(() => { load(); }, [load]);

    const save = async (row, patch) => {
        setBusy(true);
        try {
            const res = await axios.patch(endpoints.update, {
                occasion_key: row.key,
                is_enabled: patch.enabled ?? row.enabled,
                destinations: (patch.destinations ?? row.destinations).map(
                    ({ label, ...rest }) => rest,
                ),
                options: patch.options ?? row.options ?? {},
            });
            setData(res.data);
            toastSuccess('Сохранено');
        } catch (e) {
            toastError('Не получилось', e?.response?.data?.message || 'Попробуйте ещё раз.');
        } finally {
            setBusy(false);
            setAdding(null);
        }
    };

    const groups = useMemo(() => {
        if (!data) return [];
        const map = new Map();
        data.rows.forEach((row) => {
            if (!map.has(row.family_label)) map.set(row.family_label, []);
            map.get(row.family_label).push(row);
        });
        return [...map.entries()];
    }, [data]);

    if (!data) {
        return <Text fontSize="sm" color="fg.muted">Загружаем…</Text>;
    }

    return (
        <VStack align="stretch" gap={5}>
            {intro && <Text fontSize="sm" color="fg.muted">{intro}</Text>}

            {groups.map(([family, rows]) => (
                <Box key={family}>
                    <Text fontSize="sm" fontWeight="700" mb={2}>{family}</Text>

                    <VStack align="stretch" gap={0} borderWidth="1px" borderRadius="md">
                        {rows.map((row, index) => (
                            <Box
                                key={row.key}
                                p={3}
                                borderTopWidth={index === 0 ? 0 : '1px'}
                            >
                                <HStack justify="space-between" align="start" gap={4} flexWrap="wrap">
                                    <VStack align="stretch" gap={1} flex="1" minW="220px">
                                        <HStack gap={2}>
                                            <Text fontSize="sm" fontWeight="600">{row.label}</Text>
                                            {row.changed_by_client && (
                                                <Badge size="sm" colorPalette="purple" variant="subtle">
                                                    правил клиент
                                                </Badge>
                                            )}
                                            {row.overridden && !row.changed_by_client && (
                                                <Badge size="sm" colorPalette="blue" variant="subtle">
                                                    настроено
                                                </Badge>
                                            )}
                                        </HStack>

                                        {!row.enabled ? (
                                            <HStack gap={1} color="fg.muted">
                                                <LuBellOff size={13} />
                                                <Text fontSize="xs">Не присылать это вообще</Text>
                                            </HStack>
                                        ) : (
                                            <Wrap gap={1}>
                                                {row.destinations.length === 0 && (
                                                    <Text fontSize="xs" color="orange.fg">
                                                        Адресат не указан — уведомление никому не уходит
                                                    </Text>
                                                )}
                                                {row.destinations.map((dest, i) => (
                                                    <Badge key={`${dest.type}-${i}`} variant="subtle" gap={1}>
                                                        {dest.label}
                                                        {canEdit && (
                                                            <Box
                                                                as="button"
                                                                type="button"
                                                                aria-label={`Убрать адресата: ${dest.label}`}
                                                                display="inline-flex"
                                                                onClick={() => save(row, {
                                                                    destinations: row.destinations.filter((_, k) => k !== i),
                                                                })}
                                                            >
                                                                <LuX size={11} />
                                                            </Box>
                                                        )}
                                                    </Badge>
                                                ))}
                                            </Wrap>
                                        )}

                                        {row.subtype && row.enabled && (
                                            <SubtypePicker
                                                row={row}
                                                canEdit={canEdit}
                                                onChange={(subtypes) => save(row, { options: { subtypes } })}
                                            />
                                        )}
                                    </VStack>

                                    {canEdit && (
                                        <HStack gap={2}>
                                            {row.enabled && (
                                                <Button
                                                    size="xs"
                                                    variant="outline"
                                                    disabled={busy}
                                                    onClick={() => setAdding(adding === row.key ? null : row.key)}
                                                >
                                                    <LuPlus /> Адресат
                                                </Button>
                                            )}
                                            <Button
                                                size="xs"
                                                variant={row.enabled ? 'ghost' : 'solid'}
                                                disabled={busy}
                                                onClick={() => save(row, { enabled: !row.enabled })}
                                            >
                                                {row.enabled ? 'Отключить' : 'Включить'}
                                            </Button>
                                        </HStack>
                                    )}
                                </HStack>

                                {adding === row.key && (
                                    <DestinationPicker
                                        row={row}
                                        roles={data.roles}
                                        contactsEndpoint={endpoints.contacts}
                                        allowContacts={data.has_contacts}
                                        allowedTypes={allowedTypes}
                                        onPick={(destination) => save(row, {
                                            destinations: [...row.destinations, destination],
                                        })}
                                        onCancel={() => setAdding(null)}
                                    />
                                )}
                            </Box>
                        ))}
                    </VStack>
                </Box>
            ))}
        </VStack>
    );
}

/**
 * Подтип уведомления: о каких именно случаях писать.
 *
 * Один и тот же выбор для статусов заказа и типов документов — кому-то нужны
 * только счета, кому-то только акты сверки. Пустой набор означает «все»:
 * незаполненная настройка не должна означать тишину.
 */
function SubtypePicker({ row, canEdit, onChange }) {
    const chosen = row.options?.subtypes || [];
    const { label, options } = row.subtype;

    return (
        <Box mt={1}>
            <Text fontSize="xs" color="fg.muted" mb={1}>
                {label}{chosen.length === 0 ? ' — сейчас обо всех' : ` — выбрано ${chosen.length}`}
            </Text>
            <Wrap gap={2}>
                {options.map((option) => (
                    <Checkbox
                        key={option.value}
                        size="sm"
                        disabled={!canEdit}
                        checked={chosen.includes(option.value)}
                        onCheckedChange={(e) => onChange(
                            e.checked
                                ? [...chosen, option.value]
                                : chosen.filter((s) => s !== option.value),
                        )}
                    >
                        <Text fontSize="xs">{option.label}</Text>
                    </Checkbox>
                ))}
            </Wrap>
        </Box>
    );
}

/**
 * Выбор адресата.
 *
 * `allowedTypes` не декоративный: в кабинете сервер принимает от клиента только
 * почту аккаунта и свой адрес. Показать там «контактам с ролью» значило бы
 * предложить кнопку, которая отвечает ошибкой.
 */
function DestinationPicker({ roles, contactsEndpoint, allowContacts, allowedTypes, onPick, onCancel }) {
    const [mode, setMode] = useState(null);
    const [email, setEmail] = useState('');
    const [contacts, setContacts] = useState([]);

    const allows = (type) => allowedTypes.includes(type);

    useEffect(() => {
        if (mode !== 'contact' || !contactsEndpoint) return;
        axios.get(contactsEndpoint)
            .then((res) => setContacts(res.data.options || []))
            .catch(() => setContacts([]));
    }, [mode, contactsEndpoint]);

    return (
        <Box mt={3} p={3} borderWidth="1px" borderRadius="md" bg="bg.subtle">
            <HStack gap={2} flexWrap="wrap" mb={mode ? 3 : 0}>
                {allows('login') && (
                    <Button size="xs" variant="outline" onClick={() => onPick({ type: 'login' })}>
                        На почту партнёра
                    </Button>
                )}
                {allows('manager') && (
                    <Button size="xs" variant="outline" onClick={() => onPick({ type: 'manager' })}>
                        Персональному менеджеру
                    </Button>
                )}
                {allows('contact_role') && (
                    <Button size="xs" variant="outline" onClick={() => setMode('role')}>
                        Контактам с ролью
                    </Button>
                )}
                {allows('contact') && allowContacts && (
                    <Button size="xs" variant="outline" onClick={() => setMode('contact')}>
                        Конкретному человеку
                    </Button>
                )}
                {allows('email') && (
                    <Button size="xs" variant="outline" onClick={() => setMode('email')}>
                        На другой адрес
                    </Button>
                )}
                <Button size="xs" variant="ghost" onClick={onCancel}>Отмена</Button>
            </HStack>

            {mode === 'role' && (
                <Wrap gap={2}>
                    {roles.map((role) => (
                        <Button
                            key={role.value}
                            size="xs"
                            variant="subtle"
                            onClick={() => onPick({ type: 'contact_role', role: role.value })}
                        >
                            {role.label}
                        </Button>
                    ))}
                </Wrap>
            )}

            {mode === 'contact' && (
                <VStack align="stretch" gap={1} maxH="200px" overflowY="auto">
                    {contacts.length === 0 && (
                        <Text fontSize="xs" color="fg.muted">
                            У партнёра пока нет людей с почтой в справочнике контактов
                        </Text>
                    )}
                    {contacts.map((contact) => (
                        <Box
                            key={contact.id}
                            as="button"
                            type="button"
                            textAlign="left"
                            px={2}
                            py={1}
                            borderRadius="sm"
                            _hover={{ bg: 'bg.muted' }}
                            onClick={() => onPick({ type: 'contact', contact_id: contact.id })}
                        >
                            <Text fontSize="sm">{contact.label}</Text>
                            <Text fontSize="xs" color="fg.muted">{contact.sublabel}</Text>
                        </Box>
                    ))}
                </VStack>
            )}

            {mode === 'email' && (
                <HStack gap={2} maxW="420px">
                    <input
                        value={email}
                        onChange={(e) => setEmail(e.target.value)}
                        placeholder="buh@romashka.ru"
                        style={{
                            flex: 1,
                            padding: '0.4rem',
                            borderRadius: '0.375rem',
                            border: '1px solid var(--chakra-colors-border)',
                        }}
                    />
                    <Button
                        size="xs"
                        disabled={!email.includes('@')}
                        onClick={() => onPick({ type: 'email', email: email.trim() })}
                    >
                        Добавить
                    </Button>
                </HStack>
            )}
        </Box>
    );
}
