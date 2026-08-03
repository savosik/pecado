import { useState, useEffect, useCallback } from 'react';
import { IconButton, Box } from '@chakra-ui/react';
import { LuChevronUp } from 'react-icons/lu';
import { usePage } from '@inertiajs/react';

/**
 * Кнопка «Прокрутить вверх» — появляется при scrollY > 300px.
 * Скрыта на странице корзины, чтобы не конфликтовать со sticky-футером.
 */
export default function ScrollToTop() {
    const [isVisible, setIsVisible] = useState(false);
    const { url = '' } = usePage();
    const isCartPage = url.startsWith('/cart');

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

    // Ранний выход — строго после всех хуков: на /cart компонент уходил
    // из рендера раньше useCallback, и React ловил «rendered fewer hooks
    // than expected» при переходе в корзину и обратно.
    if (isCartPage) return null;

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
