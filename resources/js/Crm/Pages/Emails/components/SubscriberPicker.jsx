import { useCallback, useEffect, useRef, useState } from 'react';
import { Badge, Box, HStack, Input, Spinner, Text, VStack, Wrap } from '@chakra-ui/react';
import axios from 'axios';
import { Button } from '@/components/ui/button';
import { LuSearch, LuX } from 'react-icons/lu';

/**
 * Кто подписан на правило.
 *
 * Пустой список означает «все партнёры» — и это состояние по умолчанию,
 * потому что до подписок правило было глобальным фильтром и должно им
 * остаться, пока никого не выбрали явно.
 */
export default function SubscriberPicker({ value, onChange }) {
    const selected = value || [];
    const [scoped, setScoped] = useState(() => selected.length > 0);
    const [search, setSearch] = useState('');
    const [options, setOptions] = useState([]);
    const [loading, setLoading] = useState(false);
    const timer = useRef(null);

    const load = useCallback((query) => {
        setLoading(true);

        axios.get(route('crm.emails.rules.clients'), { params: { search: query } })
            .then((res) => setOptions(res.data.options || []))
            .catch(() => setOptions([]))
            .finally(() => setLoading(false));
    }, []);

    useEffect(() => {
        if (!scoped) {
            return undefined;
        }

        clearTimeout(timer.current);
        timer.current = setTimeout(() => load(search), 300);

        return () => clearTimeout(timer.current);
    }, [scoped, search, load]);

    const toggleScope = (next) => {
        setScoped(next);

        // Переключение на «всех» очищает список: иначе правило выглядит
        // глобальным, а подписчики тихо ждут в скрытом поле.
        if (!next) {
            onChange([]);
        }
    };

    const add = (option) => {
        if (selected.some((item) => item.id === option.id)) {
            return;
        }

        onChange([...selected, { id: option.id, label: option.label }]);
    };

    const remove = (id) => onChange(selected.filter((item) => item.id !== id));

    return (
        <Box>
            <Text fontSize="sm" fontWeight="600" mb={1}>Кто подписан</Text>

            <HStack gap={2} mb={2}>
                <Button
                    size="xs"
                    variant={scoped ? 'outline' : 'solid'}
                    onClick={() => toggleScope(false)}
                >
                    Все партнёры
                </Button>
                <Button
                    size="xs"
                    variant={scoped ? 'solid' : 'outline'}
                    onClick={() => toggleScope(true)}
                >
                    Выбранные
                </Button>
                {scoped && selected.length > 0 && (
                    <Text fontSize="xs" color="fg.muted">Подписано: {selected.length}</Text>
                )}
            </HStack>

            {!scoped && (
                <Text fontSize="xs" color="fg.muted">
                    Правило разбирает письма всех партнёров — так же, как работало до подписок.
                </Text>
            )}

            {scoped && (
                <VStack align="stretch" gap={2}>
                    {selected.length > 0 && (
                        <Wrap gap={2}>
                            {selected.map((item) => (
                                <Badge key={item.id} variant="subtle" colorPalette="blue" gap={1}>
                                    {item.label}
                                    <Box
                                        as="button"
                                        type="button"
                                        aria-label={`Отписать: ${item.label}`}
                                        onClick={() => remove(item.id)}
                                        display="inline-flex"
                                    >
                                        <LuX size={12} />
                                    </Box>
                                </Badge>
                            ))}
                        </Wrap>
                    )}

                    <HStack gap={2} maxW="420px">
                        <LuSearch size={14} />
                        <Input
                            size="sm"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Найти партнёра по названию, почте или телефону"
                        />
                        {loading && <Spinner size="xs" />}
                    </HStack>

                    <Box borderWidth="1px" borderRadius="md" maxH="220px" overflowY="auto">
                        {options.length === 0 && !loading && (
                            <Text fontSize="xs" color="fg.muted" p={2}>
                                {search ? 'Никого не нашлось' : 'Начните вводить название партнёра'}
                            </Text>
                        )}
                        {options.map((option) => {
                            const picked = selected.some((item) => item.id === option.id);

                            return (
                                <Box
                                    key={option.id}
                                    as="button"
                                    type="button"
                                    onClick={() => (picked ? remove(option.id) : add(option))}
                                    display="block"
                                    textAlign="left"
                                    w="100%"
                                    px={2}
                                    py={1.5}
                                    borderBottomWidth="1px"
                                    bg={picked ? 'bg.subtle' : undefined}
                                    _hover={{ bg: 'bg.muted' }}
                                >
                                    <Text fontSize="sm">{option.label}</Text>
                                    {option.sublabel && (
                                        <Text fontSize="xs" color="fg.muted">{option.sublabel}</Text>
                                    )}
                                </Box>
                            );
                        })}
                    </Box>

                    {selected.length === 0 && (
                        <Text fontSize="xs" color="orange.fg">
                            Пока никто не выбран — правило не поймает ни одного письма.
                        </Text>
                    )}
                </VStack>
            )}
        </Box>
    );
}
