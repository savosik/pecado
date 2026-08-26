import { Link, usePage } from '@inertiajs/react';
import { Box, HStack, Text } from '@chakra-ui/react';

/**
 * Постоянное подменю раздела почты.
 *
 * До него у каждой страницы был свой набор кнопок в шапке: из «Писем» нельзя
 * было попасть в «Стоп-лист» вовсе, только через «Правила». Заказчик про это
 * и сказал — «навигация неочевидная и скачет».
 *
 * Меню одинаково везде и только подсвечивает активный пункт.
 */
export default function MailNav({ description = null }) {
    const { url } = usePage();

    const items = [
        { href: route('crm.emails.index'), label: 'Поток', match: '/crm/emails' },
        { href: route('crm.emails.suppressions.index'), label: 'Заблокированные', match: '/crm/emails/suppressions' },
    ];

    const active = (item) => {
        const path = url.split('?')[0];

        return item.match === '/crm/emails'
            ? path === '/crm/emails'
            : path.startsWith(item.match);
    };

    return (
        <Box mb={4}>
            <HStack gap={1} borderBottomWidth="1px" pb={0}>
                {items.map((item) => {
                    const isActive = active(item);

                    return (
                        <Link key={item.href} href={item.href}>
                            <Box
                                px={3}
                                py={2}
                                fontSize="sm"
                                fontWeight={isActive ? '600' : '400'}
                                color={isActive ? 'fg' : 'fg.muted'}
                                borderBottomWidth="2px"
                                borderColor={isActive ? 'colorPalette.solid' : 'transparent'}
                                colorPalette="blue"
                                _hover={{ color: 'fg' }}
                            >
                                {item.label}
                            </Box>
                        </Link>
                    );
                })}
            </HStack>

            {description && (
                <Text fontSize="sm" color="fg.muted" mt={3}>{description}</Text>
            )}
        </Box>
    );
}
