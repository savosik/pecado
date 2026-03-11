import { HStack, Button } from '@chakra-ui/react';
import { Link, usePage } from '@inertiajs/react';
import { LuNewspaper, LuFileText, LuTicket, LuBadge } from 'react-icons/lu';

const sections = [
    { label: 'Новости', href: '/news', icon: LuNewspaper },
    { label: 'Статьи', href: '/articles', icon: LuFileText },
    { label: 'О брендах', href: '/brand-stories', icon: LuBadge },
    { label: 'Акции', href: '/promotions', icon: LuTicket },
];

/**
 * Переключатель между разделами контента.
 * Используется как actions в PageHeader (выровнен вправо).
 */
export default function ContentSwitcher() {
    const { url } = usePage();
    const currentPath = url.split('?')[0];

    return (
        <HStack gap="2" flexWrap="wrap">
            {sections.map(({ label, href, icon: Icon }) => {
                const isActive = currentPath.startsWith(href);
                return (
                    <Button
                        key={href}
                        as={Link}
                        href={href}
                        size="sm"
                        variant={isActive ? 'solid' : 'outline'}
                        colorPalette={isActive ? 'pecado' : 'gray'}
                    >
                        <Icon />
                        {label}
                    </Button>
                );
            })}
        </HStack>
    );
}
