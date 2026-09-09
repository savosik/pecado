import { Box } from '@chakra-ui/react';
import { SearchInput } from '@/Admin/Components/SearchInput';

/**
 * Поиск по партнёрам — крупное поле на белой плашке, на всю ширину.
 *
 * Раньше поиск стоял первым в общей строке с селектами: те были на белых
 * плашках и занимали больше места, а поле поиска прозрачное сливалось с
 * фоном — менеджеры тыкали в селект стадии, чтобы найти партнёра. Теперь
 * поиск живёт отдельной строкой и выглядит как главное поле раздела, а
 * отборы ушли под воронку и стали компактнее.
 *
 * @param {string} value
 * @param {Function} onChange
 */
export default function ClientsSearchBar({ value, onChange }) {
    return (
        <Box
            bg="bg.panel"
            borderWidth="1px"
            borderColor="border"
            borderRadius="lg"
            boxShadow="sm"
            px={2}
            py={1.5}
        >
            <SearchInput
                value={value}
                onChange={onChange}
                size="lg"
                placeholder="Найти партнёра: название, email, телефон, текст задачи или комментария, номер документа…"
                inputProps={{
                    variant: 'subtle',
                    bg: 'bg.panel',
                    fontSize: 'md',
                    _placeholder: { color: 'fg.muted' },
                }}
            />
        </Box>
    );
}
