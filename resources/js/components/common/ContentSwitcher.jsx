import { HStack, Button } from '@chakra-ui/react';
import { Link, usePage } from '@inertiajs/react';
import { LuNewspaper, LuFileText } from 'react-icons/lu';

const sections = [
    { label: 'Новости', href: '/news', icon: LuNewspaper },
    { label: 'Статьи', href: '/articles', icon: LuFileText },
];

/**
 * Переключатель между разделами «Новости» и «Статьи».
 */
export default function ContentSwitcher() {
    const { url } = usePage();
    const currentPath = url.split('?')[0];

    return (
        <HStack gap="2" mb="6">
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
