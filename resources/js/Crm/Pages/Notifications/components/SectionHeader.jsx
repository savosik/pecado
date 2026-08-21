import { Link, usePage } from '@inertiajs/react';
import { Badge, Box, Flex, HStack, Heading, Text, VStack } from '@chakra-ui/react';
import { LuBellRing, LuInfo } from 'react-icons/lu';
import { Button } from '@/components/ui/button';

/**
 * Шапка раздела «Уведомления»: одинаковая на всех экранах.
 *
 * Решает две проблемы, о которых сказал заказчик: пять отдельных пунктов
 * в меню были непонятны, и было неясно, чем раздел отличается от «Писем».
 * Поэтому здесь всегда видно: где я нахожусь, что на этом экране делаю
 * и почему это не переписка менеджера.
 *
 * @param {{ title: string, purpose: string, control?: string, children?: import('react').ReactNode }} props
 */
export default function SectionHeader({ title, purpose, control, children }) {
    const { url } = usePage();

    const tabs = [
        { path: '/crm/notifications/rules', label: 'Правила', hint: 'Кому и когда уходят письма' },
        { path: '/crm/notifications/journal', label: 'Что уходило', hint: 'История: кто получил, а кто нет и почему' },
        { path: '/crm/notifications/coverage', label: 'Кому некому писать', hint: 'Где не хватает контактов' },
        { path: '/crm/notifications/campaigns', label: 'Рассылки', hint: 'Акции и новости по сегменту' },
        { path: '/crm/notifications/suppressions', label: 'Не писать', hint: 'Адреса, на которые письма не идут' },
    ];

    const isActive = (path) => url.startsWith(path);

    return (
        <VStack align="stretch" gap={4} mb={5}>
            <HStack gap={3}>
                <LuBellRing size={22} />
                <Heading size="lg">Уведомления клиентам</Heading>
                <Badge colorPalette="purple" variant="subtle">шлёт система</Badge>
            </HStack>

            {/* Главное недоразумение: чем это отличается от «Писем». */}
            <Box borderWidth="1px" borderRadius="md" p={3} bg="bg.subtle">
                <HStack gap={2} align="start">
                    <Box pt="2px"><LuInfo size={16} /></Box>
                    <Box>
                        <Text fontSize="sm">
                            Здесь настраиваются письма, которые система отправляет <b>сама</b>, когда
                            что-то произошло: изменился заказ, пришёл акт сверки, подошёл срок оплаты.
                            Вы задаёте правило один раз — дальше оно работает без вас.
                        </Text>
                        <Text fontSize="sm" mt={1} color="fg.muted">
                            Переписка, которую менеджер пишет руками конкретному человеку, живёт
                            в разделе{' '}
                            <Link href="/crm/emails" style={{ textDecoration: 'underline' }}>
                                «Письма (пишу сам)»
                            </Link>. Это разные вещи: там вы сочиняете текст и жмёте «отправить»,
                            здесь — описываете, при каком событии и кому письмо уйдёт автоматически.
                        </Text>
                    </Box>
                </HStack>
            </Box>

            <Flex gap={2} wrap="wrap">
                {tabs.map((tab) => (
                    <Link key={tab.path} href={tab.path}>
                        <Button
                            size="sm"
                            variant={isActive(tab.path) ? 'solid' : 'outline'}
                            title={tab.hint}
                        >
                            {tab.label}
                        </Button>
                    </Link>
                ))}
            </Flex>

            <Box>
                <Heading size="md" mb={1}>{title}</Heading>
                <Text fontSize="sm" color="fg.muted">{purpose}</Text>
                {control && (
                    <Text fontSize="sm" color="fg.muted" mt={1}>
                        <b>Что вы здесь делаете:</b> {control}
                    </Text>
                )}
            </Box>

            {children}
        </VStack>
    );
}
