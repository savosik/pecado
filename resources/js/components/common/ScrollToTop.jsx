import { useState, useEffect, useCallback } from 'react';
import { IconButton, Box } from '@chakra-ui/react';
import { LuChevronUp } from 'react-icons/lu';

/**
 * Кнопка «Прокрутить вверх» — появляется при scrollY > 300px.
 */
export default function ScrollToTop() {
    const [isVisible, setIsVisible] = useState(false);

    useEffect(() => {
        const handleScroll = () => {
            setIsVisible(window.scrollY > 300);
        };

        window.addEventListener('scroll', handleScroll, { passive: true });
        return () => window.removeEventListener('scroll', handleScroll);
    }, []);

    const scrollToTop = useCallback(() => {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }, []);

    return (
        <Box
            position="fixed"
            bottom={{ base: '24', lg: '8' }}
            right={{ base: '4', lg: '8' }}
            zIndex="50"
            opacity={isVisible ? 1 : 0}
            transform={isVisible ? 'translateY(0)' : 'translateY(16px)'}
            transition="all 0.3s ease"
            pointerEvents={isVisible ? 'auto' : 'none'}
        >
            <IconButton
                onClick={scrollToTop}
                aria-label="Прокрутить наверх"
                rounded="full"
                size="lg"
                colorPalette="pecado"
                variant="solid"
                shadow="lg"
                _hover={{ transform: 'scale(1.1)' }}
            >
                <LuChevronUp size={24} />
            </IconButton>
        </Box>
    );
}
