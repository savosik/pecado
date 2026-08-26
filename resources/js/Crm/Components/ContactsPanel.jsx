import { useCallback, useEffect, useState } from 'react';
import { Link } from '@inertiajs/react';
import axios from 'axios';
import { Badge, Box, HStack, Text, VStack } from '@chakra-ui/react';
import { Button } from '@/components/ui/button';
import ContactForm from '@/Crm/Components/ContactForm';
import { toastError, toastSuccess } from '@/utils/toast';
import RowActions from '@/shared/Panel/RowActions';
import { useConfirmDelete } from '@/shared/Panel/useConfirmDelete';
import { ConfirmDialog } from '@/shared/Panel/ConfirmDialog';
import { LuDownload, LuExternalLink, LuUnlink, LuUserPlus } from 'react-icons/lu';

const controlStyle = {
    padding: '0.35rem',
    borderRadius: '0.375rem',
    border: '1px solid var(--chakra-colors-border)',
};

function ContactRow({ item, onOpenLink, actions }) {
    return (
        <HStack borderWidth="1px" borderRadius="md" p={2} justifyContent="space-between" gap={3} flexWrap="wrap">
            <VStack align="start" gap={0} flex="1" minW="200px">
                <HStack gap={2} flexWrap="wrap">
                    <Text fontSize="sm" fontWeight="600">{item.full_name}</Text>
                    {onOpenLink}
                </HStack>
                {item.position && <Text fontSize="xs" color="fg.muted">{item.position}</Text>}
                <Text fontSize="xs" color="fg.muted">
                    {[item.phone, item.email].filter(Boolean).join(' · ') || 'Контакты не указаны'}
                </Text>
            </VStack>
            <HStack gap={1}>{actions}</HStack>
        </HStack>
    );
}

/**
 * Контакты сущности — врезка на карточке партнёра или контрагента.
 *
 * Показывает и привязанных к этой карточке, и общих людей партнёра: бухгалтер
 * бывает один на все юрлица, и держать его «где-то там» значило бы заставить
 * менеджера искать.
 */
export default function ContactsPanel({ entityType, entityId, canEdit = false, canCreate = false }) {
    const [links, setLinks] = useState([]);
    const [partnerContacts, setPartnerContacts] = useState([]);
    const [roles, setRoles] = useState([]);
    const [creating, setCreating] = useState(false);
    const [busy, setBusy] = useState(false);

    const load = useCallback(() => {
        axios.get(route('crm.contacts.for-entity'), { params: { entity_type: entityType, entity_id: entityId } })
            .then((res) => {
                setLinks(res.data.data || []);
                setPartnerContacts(res.data.partner_contacts || []);
                setRoles(res.data.roles || []);
            })
            .catch(() => {});
    }, [entityType, entityId]);

    useEffect(load, [load]);

    const attach = async (contactId, role) => {
        setBusy(true);
        try {
            await axios.post(route('crm.contacts.link', contactId), {
                entity_type: entityType,
                entity_id: entityId,
                role,
            });
            toastSuccess('Контакт привязан');
            load();
        } catch (e) {
            toastError('Не получилось', e?.response?.data?.message || 'Попробуйте ещё раз.');
        } finally {
            setBusy(false);
        }
    };

    const detach = async (contactId, linkId) => {
        setBusy(true);
        try {
            await axios.delete(route('crm.contacts.unlink', [contactId, linkId]));
            toastSuccess('Привязка снята');
            load();
        } catch (e) {
            toastError('Не получилось', e?.response?.data?.message || 'Попробуйте ещё раз.');
        } finally {
            setBusy(false);
        }
    };

    const detachConfirm = useConfirmDelete({
        title: 'Отвязать контакт?',
        description: (link) => `«${link?.contact?.full_name ?? ''}» перестанет числиться в этой карточке. Сам контакт останется в справочнике.`,
        confirmLabel: 'Отвязать',
        onConfirm: (link) => detach(link.contact.id, link.link_id),
    });

    return (
        <VStack align="stretch" gap={3}>
            <HStack justifyContent="space-between" flexWrap="wrap" gap={2}>
                <Text fontSize="xs" color="fg.muted">
                    Люди этой карточки: кем приходятся и как связаться.
                </Text>
                <HStack gap={2}>
                    {canCreate && (
                        <Button size="xs" variant="outline" onClick={() => setCreating((v) => !v)}>
                            <LuUserPlus /> Новый
                        </Button>
                    )}
                    <Link href={route('crm.contacts.index')}>
                        <Button size="xs" variant="ghost"><LuExternalLink /> Весь справочник</Button>
                    </Link>
                </HStack>
            </HStack>

            {creating && (
                <Box borderWidth="1px" borderRadius="md" p={3}>
                    <ContactForm
                        roles={roles}
                        entity={{ type: entityType, id: entityId }}
                        onSaved={() => { setCreating(false); load(); }}
                        onCancel={() => setCreating(false)}
                    />
                </Box>
            )}

            {links.length === 0 && (
                <Text fontSize="sm" color="fg.muted">Пока никто не привязан.</Text>
            )}

            {links.map((link) => (
                <ContactRow
                    key={link.link_id}
                    item={link.contact}
                    onOpenLink={(
                        <>
                            <Badge size="sm" colorPalette={link.role_color} variant="subtle">{link.role_label}</Badge>
                            {link.is_primary && <Badge size="sm" colorPalette="blue">основной</Badge>}
                        </>
                    )}
                    actions={(
                        <RowActions
                            size="xs"
                            view={{ href: route('crm.contacts.show', link.contact.id) }}
                            extra={[
                                {
                                    icon: LuDownload,
                                    label: 'Скачать в телефон',
                                    // Файл, а не страница: Inertia-ссылка сюда не годится.
                                    onClick: () => window.location.assign(route('crm.contacts.vcard', link.contact.id)),
                                },
                                {
                                    icon: LuUnlink,
                                    label: 'Отвязать',
                                    allowed: canEdit,
                                    disabled: busy,
                                    onClick: () => detachConfirm.request(link),
                                },
                            ]}
                        />
                    )}
                />
            ))}

            {partnerContacts.length > 0 && (
                <Box borderTopWidth="1px" pt={3}>
                    <Text fontSize="xs" color="fg.muted" mb={2}>
                        Другие люди этого партнёра — можно привязать сюда
                    </Text>
                    <VStack align="stretch" gap={2}>
                        {partnerContacts.map((contact) => (
                            <ContactRow
                                key={contact.id}
                                item={contact}
                                actions={(
                                    <>
                                        <RowActions size="xs" view={{ href: route('crm.contacts.show', contact.id) }} />
                                        {canEdit ? (
                                            <select
                                                defaultValue=""
                                                style={controlStyle}
                                                disabled={busy}
                                                onChange={(e) => e.target.value && attach(contact.id, e.target.value)}
                                            >
                                                <option value="">Привязать как…</option>
                                                {roles.map((role) => (
                                                    <option key={role.value} value={role.value}>{role.label}</option>
                                                ))}
                                            </select>
                                        ) : null}
                                    </>
                                )}
                            />
                        ))}
                    </VStack>
                </Box>
            )}

            <ConfirmDialog {...detachConfirm.dialogProps} />
        </VStack>
    );
}
