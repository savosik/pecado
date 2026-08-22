import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { Badge, Box, HStack, Image, Text, VStack, Wrap } from '@chakra-ui/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { DataTable } from '@/Admin/Components/DataTable';
import { SearchInput } from '@/Admin/Components/SearchInput';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import ScopeToggle from '@/Crm/Components/ScopeToggle';
import ContactForm from '@/Crm/Components/ContactForm';
import { LuCake, LuMail, LuPhone, LuUserPlus } from 'react-icons/lu';

const selectStyle = {
    padding: '0.5rem',
    borderRadius: '0.375rem',
    border: '1px solid var(--chakra-colors-border)',
    minWidth: '170px',
};

/**
 * Справочник людей.
 *
 * Строка — человек, а не роль. Бухгалтер трёх юрлиц одного партнёра занимает
 * одну строку: роли и «где» показаны агрегатом. Иначе список выглядел бы так,
 * будто в базе дубли.
 */
export default function Index({
    contacts,
    filters,
    roles = [],
    sources = [],
    channels = [],
    can = {},
    canSeeDepartment = false,
}) {
    const [creating, setCreating] = useState(false);

    const apply = (patch) => {
        router.get(route('crm.contacts.index'), { ...filters, ...patch, page: undefined }, {
            preserveState: true,
            replace: true,
        });
    };

    const columns = [
        {
            key: 'full_name',
            label: 'Контакт',
            render: (_, row) => (
                <HStack gap={3} align="start">
                    {row.avatar_url
                        ? <Image src={row.avatar_url} alt="" boxSize="36px" borderRadius="full" objectFit="cover" />
                        : (
                            <Box boxSize="36px" borderRadius="full" bg="bg.emphasized" display="flex" alignItems="center" justifyContent="center">
                                <Text fontSize="xs" color="fg.muted">{(row.full_name || '?').slice(0, 1)}</Text>
                            </Box>
                        )}
                    <VStack align="start" gap={0}>
                        <Link href={route('crm.contacts.show', row.id)}>
                            <Text fontSize="sm" fontWeight="600">{row.full_name}</Text>
                        </Link>
                        {row.position && <Text fontSize="xs" color="fg.muted">{row.position}</Text>}
                        <HStack gap={1} mt={1}>
                            <Badge size="sm" variant="subtle" colorPalette={row.source_color}>{row.source_badge}</Badge>
                            {!row.is_active && <Badge size="sm" colorPalette="gray">не работает</Badge>}
                        </HStack>
                    </VStack>
                </HStack>
            ),
        },
        {
            key: 'roles',
            label: 'Роли',
            render: (_, row) => (row.roles?.length
                ? (
                    <Wrap gap={1}>
                        {row.roles.slice(0, 3).map((item) => (
                            <Badge key={item.value} size="sm" colorPalette={item.color} variant="subtle">{item.label}</Badge>
                        ))}
                        {row.roles.length > 3 && <Text fontSize="xs" color="fg.muted">ещё {row.roles.length - 3}</Text>}
                    </Wrap>
                )
                : <Text fontSize="xs" color="fg.muted">—</Text>),
        },
        {
            key: 'links',
            label: 'Где',
            render: (_, row) => (row.links?.length
                ? (
                    <VStack align="start" gap={0}>
                        {row.links.slice(0, 2).map((link) => (
                            <Text key={link.id} fontSize="xs">
                                {link.subject?.url
                                    ? <a href={link.subject.url}>{link.subject.title}</a>
                                    : (link.subject?.title || '—')}
                            </Text>
                        ))}
                        {row.links.length > 2 && <Text fontSize="xs" color="fg.muted">ещё {row.links.length - 2}</Text>}
                    </VStack>
                )
                : <Text fontSize="xs" color="fg.muted">{row.client?.name || '—'}</Text>),
        },
        {
            key: 'contacts',
            label: 'Связь',
            render: (_, row) => (
                <VStack align="start" gap={0}>
                    {row.phone && (
                        <HStack gap={1}><LuPhone size={12} /><Text fontSize="xs">{row.phone}</Text></HStack>
                    )}
                    {row.email && (
                        <HStack gap={1}><LuMail size={12} /><Text fontSize="xs">{row.email}</Text></HStack>
                    )}
                    {row.preferred_channel_label && (
                        <Text fontSize="xs" color="fg.muted">предпочитает: {row.preferred_channel_label}</Text>
                    )}
                </VStack>
            ),
        },
        {
            key: 'birthday',
            label: 'День рождения',
            render: (_, row) => (row.birthday_label
                ? <HStack gap={1}><LuCake size={12} /><Text fontSize="xs">{row.birthday_label}</Text></HStack>
                : <Text fontSize="xs" color="fg.muted">—</Text>),
        },
    ];

    return (
        <>
            <Head title="CRM — Контакты" />
            <PageHeader
                title="Контакты"
                description="Люди партнёров и контрагентов: кто, кем приходится и как связаться"
                actions={can.create
                    ? <Button size="sm" onClick={() => setCreating((v) => !v)}><LuUserPlus /> Новый контакт</Button>
                    : null}
            />

            <VStack align="stretch" gap={4}>
                {creating && (
                    <Box borderWidth="1px" borderRadius="lg" p={4}>
                        <ContactForm
                            channels={channels}
                            roles={roles}
                            onSaved={() => { setCreating(false); router.reload(); }}
                            onCancel={() => setCreating(false)}
                        />
                    </Box>
                )}

                <HStack gap={3} flexWrap="wrap" align="center">
                    <Box flex="1" minW="240px">
                        <SearchInput
                            value={filters.search || ''}
                            onChange={(value) => apply({ search: value || undefined })}
                            placeholder="ФИО, телефон, почта, должность..."
                        />
                    </Box>

                    <select
                        value={filters.role || ''}
                        onChange={(e) => apply({ role: e.target.value || undefined })}
                        style={selectStyle}
                    >
                        <option value="">Любая роль</option>
                        {roles.map((item) => (
                            <option key={item.value} value={item.value}>{item.label}</option>
                        ))}
                    </select>

                    <select
                        value={filters.source || ''}
                        onChange={(e) => apply({ source: e.target.value || undefined })}
                        style={selectStyle}
                    >
                        <option value="">Любой источник</option>
                        {sources.map((item) => (
                            <option key={item.value} value={item.value}>{item.label}</option>
                        ))}
                    </select>

                    <select
                        value={filters.activity || 'active'}
                        onChange={(e) => apply({ activity: e.target.value })}
                        style={selectStyle}
                    >
                        <option value="active">Работают</option>
                        <option value="inactive">Не работают</option>
                        <option value="all">Все</option>
                    </select>

                    <ScopeToggle section="contacts" scope={filters.scope} available={canSeeDepartment} />
                </HStack>

                <HStack gap={4} flexWrap="wrap">
                    <Checkbox
                        checked={!!filters.with_email}
                        onCheckedChange={(e) => apply({ with_email: e.checked ? 1 : undefined })}
                    >
                        Есть почта
                    </Checkbox>
                    <Checkbox
                        checked={!!filters.with_phone}
                        onCheckedChange={(e) => apply({ with_phone: e.checked ? 1 : undefined })}
                    >
                        Есть телефон
                    </Checkbox>
                    <Checkbox
                        checked={!!filters.with_birthday}
                        onCheckedChange={(e) => apply({ with_birthday: e.checked ? 1 : undefined })}
                    >
                        Есть день рождения
                    </Checkbox>
                </HStack>

                <DataTable
                    data={contacts.data}
                    columns={columns}
                    pagination={contacts}
                    sortColumn={filters.sort}
                    sortDirection={filters.direction}
                    emptyMessage="Пока никого нет — заведите первый контакт"
                />
            </VStack>
        </>
    );
}

Index.layout = (page) => <CrmLayout>{page}</CrmLayout>;
