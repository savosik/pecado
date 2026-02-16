import { useCallback } from 'react';
import { Box, Flex, Input, IconButton } from '@chakra-ui/react';
import { LuSearch, LuMic, LuMicOff } from 'react-icons/lu';
import { usePage } from '@inertiajs/react';
import useSearch from '@/hooks/useSearch';
import useSpeechRecognition from '@/shared/useSpeechRecognition';
import SearchDropdown from '@/components/common/SearchDropdown';

/** CSS-анимация пульсации для кнопки микрофона при записи */
const micPulseStyle = {
    animation: 'mic-pulse 1.2s ease-in-out infinite',
    '@keyframes mic-pulse': {
        '0%, 100%': { transform: 'translateY(-50%) scale(1)', opacity: 1 },
        '50%': { transform: 'translateY(-50%) scale(1.15)', opacity: 0.7 },
    },
};

/**
 * Search — главный компонент поиска для шапки.
 *
 * Объединяет поле ввода с иконкой лупы, хук useSearch,
 * голосовой ввод (useSpeechRecognition) и выпадающий SearchDropdown.
 *
 * Использование:
 *   <Search />
 */
export default function Search() {
    const { auth } = usePage().props;
    const user = auth?.user;
    const search = useSearch(user);

    // Голосовой поиск: результат → вставка в query + открытие дропдауна
    const handleSpeechResult = useCallback((transcript) => {
        search.setQuery(transcript);
        search.setOpen(true);
    }, [search.setQuery, search.setOpen]);

    const speech = useSpeechRecognition({
        onResult: handleSpeechResult,
        lang: 'ru-RU',
    });

    // Определяем цвет и стиль иконки микрофона
    const micColor = speech.hasError
        ? 'orange.400'
        : speech.isListening
            ? 'red.500'
            : !speech.isSupported
                ? 'gray.300'
                : 'gray.400';

    return (
        <Flex flex="1" position="relative" ref={search.containerRef}>
            {/* Скрываем основной инпут при мобильном оверлее */}
            <Box
                display={search.isSmall && search.open ? 'none' : 'flex'}
                alignItems="center"
                w="100%"
                position="relative"
                as="form"
                onSubmit={(e) => { e.preventDefault(); search.submitSearch(); }}
            >
                {/* Иконка лупы слева */}
                <Box
                    position="absolute"
                    left="3"
                    top="50%"
                    transform="translateY(-50%)"
                    color="gray.400"
                    pointerEvents="none"
                    zIndex="1"
                >
                    <LuSearch size={16} />
                </Box>

                <Input
                    placeholder="Поиск товаров, брендов, категорий…"
                    size="sm"
                    borderRadius="lg"
                    bg="gray.50"
                    _dark={{ bg: 'gray.800' }}
                    pl="9"
                    pr="9"
                    value={search.query}
                    onChange={(e) => search.setQuery(e.target.value)}
                    onFocus={search.onFocus}
                />

                {/* Кнопка микрофона справа */}
                <IconButton
                    aria-label="Голосовой поиск"
                    variant="ghost"
                    size="xs"
                    position="absolute"
                    right="1"
                    top="50%"
                    transform="translateY(-50%)"
                    zIndex="1"
                    color={micColor}
                    opacity={!speech.isSupported ? 0.4 : 1}
                    cursor={!speech.isSupported ? 'not-allowed' : 'pointer'}
                    onClick={speech.isSupported ? speech.toggleListening : undefined}
                    css={speech.isListening ? micPulseStyle : undefined}
                >
                    {speech.isSupported ? <LuMic size={15} /> : <LuMicOff size={15} />}
                </IconButton>
            </Box>

            {search.open && (
                <SearchDropdown
                    query={search.query}
                    setQuery={search.setQuery}
                    loading={search.loading}
                    results={search.results}
                    history={search.history}
                    error={search.error}
                    hasResults={search.hasResults}
                    isSmall={search.isSmall}
                    includeUnavailable={search.includeUnavailable}
                    toggleIncludeUnavailable={search.toggleIncludeUnavailable}
                    deleteHistoryItem={search.deleteHistoryItem}
                    clearAllHistory={search.clearAllHistory}
                    setOpen={search.setOpen}
                    submitSearch={search.submitSearch}
                />
            )}
        </Flex>
    );
}
