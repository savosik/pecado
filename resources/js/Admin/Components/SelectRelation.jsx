import React, { useState, useEffect } from 'react';
import {
    Select,
    Portal,
    Spinner,
    createListCollection,
} from '@chakra-ui/react';
import axios from 'axios';

/**
 * SelectRelation - select для выбора связанных сущностей с поддержкой поиска
 * 
 * @param {string} name - Имя поля
 * @param {number|number[]|null} value - Значение (ID или массив ID)
 * @param {Function} onChange - Callback изменения
 * @param {string} endpoint - API endpoint для загрузки опций (опционально)
 * @param {Array} options - Готовый массив опций (опционально, если не задан endpoint)
 * @param {boolean} multiple - Множественный выбор
 * @param {string} placeholder - Placeholder
 * @param {string} error - Текст ошибки
 * @param {string} label - Label для поля
 * @param {boolean} disabled - Отключить поле
 */
export const SelectRelation = ({
    name,
    value = null,
    onChange,
    endpoint,
    options: directOptions = null,
    multiple = false,
    placeholder = 'Выберите...',
    error = null,
    label = null,
    disabled = false,
}) => {
    const [options, setOptions] = useState([]);
    const [loading, setLoading] = useState(false);
    const [searchQuery, setSearchQuery] = useState('');

    // Загрузка опций при монтировании и изменении поискового запроса
    useEffect(() => {
        if (endpoint) {
            loadOptions();
        } else if (directOptions) {
            // Если переданы готовые опции, используем их
            setOptions(directOptions);
        }
    }, [endpoint, searchQuery, directOptions]);

    const loadOptions = async () => {
        if (!endpoint) return;

        setLoading(true);
        try {
            const response = await axios.get(endpoint, {
                params: { search: searchQuery },
            });

            // Предполагаем, что API возвращает массив объектов
            const data = response.data.data || response.data;
            setOptions(data);
        } catch (error) {
            console.error('Ошибка загрузки опций:', error);
            setOptions([]);
        } finally {
            setLoading(false);
        }
    };

    // Создание коллекции для Chakra Select (мемоизируем)
    const collection = React.useMemo(() => createListCollection({
        items: options.map(option => {
            // Если опции уже в нужном формате { value, label }
            if (option.value !== undefined && option.label !== undefined) {
                return {
                    label: option.label,
                    value: String(option.value),
                };
            }
            // Иначе используем labelKey/valueKey (для API ответов)
            return {
                label: option.name || option.label,
                value: String(option.id || option.value),
            };
        }),
    }), [options]);

    // Обработка изменения значения
    const handleValueChange = (details) => {
        if (multiple) {
            // Для множественного выбора
            const selectedValues = details.value.map(v => parseInt(v));
            onChange(selectedValues);
        } else {
            // Для одиночного выбора
            const selectedValue = details.value.length > 0 ? parseInt(details.value[0]) : null;
            onChange(selectedValue);
        }
    };

    // Преобразование value в формат для Chakra Select
    const selectValue = multiple
        ? (Array.isArray(value) ? value.map(v => String(v)) : [])
        : (value ? [String(value)] : []);

    return (
        <Select.Root
            collection={collection}
            value={selectValue}
            onValueChange={handleValueChange}
            multiple={multiple}
            invalid={!!error}
            disabled={disabled}
        >
            <Select.HiddenSelect name={name} />
            <Select.Control>
                <Select.Trigger>
                    <Select.ValueText placeholder={placeholder} />
                </Select.Trigger>
                <Select.IndicatorGroup>
                    {loading && <Spinner size="xs" />}
                    {multiple && <Select.ClearTrigger />}
                    <Select.Indicator />
                </Select.IndicatorGroup>
            </Select.Control>

            <Portal>
                <Select.Positioner>
                    <Select.Content>
                        {/* Поиск */}
                        <Select.ItemGroup>
                            <Select.ItemGroupLabel>
                                <input
                                    type="text"
                                    placeholder="Поиск..."
                                    value={searchQuery}
                                    onChange={(e) => setSearchQuery(e.target.value)}
                                    style={{
                                        width: '100%',
                                        padding: '8px',
                                        border: 'none',
                                        outline: 'none',
                                    }}
                                />
                            </Select.ItemGroupLabel>
                        </Select.ItemGroup>

                        {/* Опции */}
                        {loading ? (
                            <Select.Item item={{ label: 'Загрузка...', value: 'loading' }} disabled>
                                Загрузка...
                            </Select.Item>
                        ) : options.length === 0 ? (
                            <Select.Item item={{ label: 'Нет данных', value: 'empty' }} disabled>
                                Нет данных
                            </Select.Item>
                        ) : (
                            collection.items.map((item) => (
                                <Select.Item key={item.value} item={item}>
                                    {item.label}
                                    <Select.ItemIndicator />
                                </Select.Item>
                            ))
                        )}
                    </Select.Content>
                </Select.Positioner>
            </Portal>
        </Select.Root>
    );
};
