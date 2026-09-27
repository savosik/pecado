import { Head, Link, router } from '@inertiajs/react';
import { Badge, Box, HStack, Text, VStack } from '@chakra-ui/react';
import { LuMessageCircleQuestion, LuPaperclip } from 'react-icons/lu';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { DataTable } from '@/Admin/Components/DataTable';
import { SearchInput } from '@/Admin/Components/SearchInput';
import { Button } from '@/components/ui/button';
import ScopeToggle from '@/Crm/Components/ScopeToggle';
import RowActions from '@/shared/Panel/RowActions';

const STATUS_CHIPS = [
    { value: 'open', label: 'Ждут ответа', palette: 'blue' },
    { value: 'answered', label: 'Отвечены', palette: 'green' },
    { value: 'rejected', label: 'Отклонены', palette: 'red' },
    { value: 'all', label: 'Все', palette: 'gray' },
];

/**
 * Вопросы клиентов менеджеру.
 *
 * Строка — вопрос с сайта, из кабинета или через клиентский API. По умолчанию
 * показаны те, что ждут ответа; открыть карточку — глаз. Разрез «только мои /
 * весь отдел» — общий для CRM; гости и партнёры без менеджера видны в отделе.
 */
export default function Index({ questions, counts = {}, filters = {}, canSeeDepartment = false }) {
    const apply = (patch) => {
        router.get(route('crm.questions.index'), { ...filters, ...patch, page: undefined }, {
            preserveState: true,
            replace: true,
        });
    };

    const columns = [
        {
            key: 'subject',
            label: 'Вопрос',
            render: (_, row) => (
                <VStack align="start" gap={0.5} minW="260px">
                    <HStack gap={1.5}>
                        <Text fontSize="sm" fontWeight="600">{row.subject}</Text>
                        {row.has_attachment && <LuPaperclip size={12} title="Есть вложение" />}
                    </HStack>
                    <Text fontSize="xs" color="fg.muted" lineClamp={2}>{row.body_preview}</Text>
                </VStack>
            ),
        },
        {
            key: 'client',
            label: 'От кого',
            render: (_, row) => (
                <VStack align="start" gap={0}>
                    {row.client
                        ? (
                            <Link href={row.client.url}>
                                <Text fontSize="sm" fontWeight="500" color="blue.600">{row.client.name}</Text>
                            </Link>
                        )
                        : (
                            <HStack gap={1.5}>
                                <Text fontSize="sm">{row.name || 'Без имени'}</Text>
                                <Badge size="xs" variant="subtle" colorPalette="gray">гость</Badge>
                            </HStack>
                        )}
                    <Text fontSize="xs" color="fg.muted">{row.email}</Text>
                </VStack>
            ),
        },
        ...(canSeeDepartment ? [{
            key: 'manager',
            label: 'Менеджер',
            render: (_, row) => (
                <Text fontSize="sm" color={row.manager ? undefined : 'fg.muted'}>{row.manager || 'не закреплён'}</Text>
            ),
        }] : []),
        {
            key: 'status',
            label: 'Статус',
            render: (_, row) => (
                <VStack align="start" gap={1}>
                    <Badge size="sm" colorPalette={row.status_color}>{row.status_label}</Badge>
                    {row.answered_by && (
                        <Text fontSize="xs" color="fg.muted">{row.answered_by}{row.answered_at ? `, ${row.answered_at}` : ''}</Text>
                    )}
                </VStack>
            ),
        },
        {
            key: 'created_at',
            label: 'Задан',
            render: (_, row) => <Text fontSize="sm" whiteSpace="nowrap">{row.created_at}</Text>,
        },
        {
            key: 'actions',
            label: 'Действия',
            render: (_, row) => (
                <RowActions size="xs" view={{ href: route('crm.questions.show', row.id) }} />
            ),
        },
    ];

    return (
        <>
            <Head title="CRM — Вопросы клиентов" />
            <PageHeader
                title="Вопросы клиентов"
                description="Что спрашивают партнёры на сайте, в кабинете и через API — и что им ответили"
            />

            <VStack align="stretch" gap={4}>
                <Box bg="bg.subtle" borderWidth="1px" borderRadius="lg" px={3} py={2}>
                    <HStack gap={2} flexWrap="wrap" align="center">
                        <HStack gap={1} flexWrap="wrap">
                            {STATUS_CHIPS.map((chip) => {
                                const active = filters.status === chip.value;
                                const count = counts[chip.value] ?? 0;

                                return (
                                    <Button
                                        key={chip.value}
                                        size="xs"
                                        variant={active ? 'solid' : 'outline'}
                                        colorPalette={active ? chip.palette : 'gray'}
                                        onClick={() => apply({ status: chip.value })}
                                    >
                                        {chip.label}
                                        <Badge size="xs" variant={active ? 'solid' : 'subtle'} colorPalette={active ? chip.palette : 'gray'} ml={1}>
                                            {count}
                                        </Badge>
                                    </Button>
                                );
                            })}
                        </HStack>
                        <Box flex="1" minW="220px">
                            <SearchInput
                                value={filters.search || ''}
                                onChange={(value) => apply({ search: value || undefined })}
                                placeholder="Тема, текст, партнёр, почта…"
                            />
                        </Box>
                        <ScopeToggle section="questions" scope={filters.scope} available={canSeeDepartment} />
                    </HStack>
                </Box>

                <DataTable
                    data={questions.data}
                    columns={columns}
                    pagination={questions}
                    emptyMessage={filters.status === 'open'
                        ? 'Вопросов без ответа нет'
                        : 'Вопросов не найдено'}
                />

                <HStack gap={2} color="fg.muted" fontSize="xs">
                    <LuMessageCircleQuestion size={14} />
                    <Text>
                        О новом вопросе своего партнёра менеджер получает письмо — настраивается в «Моих уведомлениях».
                        Вопросы гостей видны в разрезе «весь отдел».
                    </Text>
                </HStack>
            </VStack>
        </>
    );
}

Index.layout = (page) => <CrmLayout>{page}</CrmLayout>;
