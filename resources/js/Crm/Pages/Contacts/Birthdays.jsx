import { Head, Link } from '@inertiajs/react';
import { Badge, Box, HStack, Image, Text, VStack } from '@chakra-ui/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Button } from '@/components/ui/button';
import { Alert } from '@/components/ui/alert';
import { LuArrowLeft, LuCake } from 'react-icons/lu';

/**
 * Ближайшие дни рождения контактов.
 *
 * Год у половины людей неизвестен, поэтому показываем число и месяц,
 * а возраст не считаем вовсе.
 */
export default function Birthdays({ birthdays = [] }) {
    return (
        <>
            <Head title="CRM — Дни рождения" />
            <PageHeader
                title="Дни рождения"
                description="Кого поздравить в ближайшие два месяца"
                actions={(
                    <Link href={route('crm.contacts.index')}>
                        <Button size="sm" variant="outline"><LuArrowLeft /> К контактам</Button>
                    </Link>
                )}
            />

            <VStack align="stretch" gap={2}>
                {birthdays.length === 0 && (
                    <Alert status="info" title="Ближайших дней рождения нет">
                        Дата рождения заполняется в карточке контакта. Накануне мы поставим
                        задачу «Поздравить» персональному менеджеру.
                    </Alert>
                )}

                {birthdays.map((item) => (
                    <HStack key={item.id} borderWidth="1px" borderRadius="lg" p={3} gap={3} flexWrap="wrap">
                        {item.avatar_url
                            ? <Image src={item.avatar_url} alt="" boxSize="40px" borderRadius="full" objectFit="cover" />
                            : (
                                <Box boxSize="40px" borderRadius="full" bg="bg.emphasized" display="flex" alignItems="center" justifyContent="center">
                                    <LuCake size={16} />
                                </Box>
                            )}

                        <VStack align="start" gap={0} flex="1" minW="200px">
                            <Link href={item.url}><Text fontSize="sm" fontWeight="600">{item.full_name}</Text></Link>
                            <Text fontSize="xs" color="fg.muted">
                                {[item.position, item.client?.name].filter(Boolean).join(' · ')}
                            </Text>
                        </VStack>

                        <HStack gap={2}>
                            <Text fontSize="sm">{item.date_label}</Text>
                            {item.is_today
                                ? <Badge colorPalette="pink">сегодня</Badge>
                                : <Badge variant="subtle">через {item.days_left} дн.</Badge>}
                        </HStack>
                    </HStack>
                ))}
            </VStack>
        </>
    );
}

Birthdays.layout = (page) => <CrmLayout>{page}</CrmLayout>;
