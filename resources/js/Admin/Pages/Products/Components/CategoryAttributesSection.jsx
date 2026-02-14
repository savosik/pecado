import { useState, useEffect, useCallback, useRef } from 'react';
import { Box, Card, Stack, Input, SimpleGrid, Spinner, Text, Em } from '@chakra-ui/react';
import { FormField, SelectRelation } from '@/Admin/Components';
import { Switch } from '@/components/ui/switch';
import axios from 'axios';
import { LuListChecks } from 'react-icons/lu';

/**
 * CategoryAttributesSection — секция динамических атрибутов на основе выбранной категории.
 *
 * При изменении categoryId делает AJAX-запрос, получает атрибуты категории,
 * и отображает поля ввода в зависимости от типа атрибута.
 *
 * @param {number|null} categoryId - ID выбранной категории
 * @param {Array} value - Текущие значения атрибутов [{ attribute_id, attribute_value_id, text_value, number_value, boolean_value }]
 * @param {Function} onChange - Callback при изменении значений
 * @param {object} errors - Объект ошибок валидации
 */
export function CategoryAttributesSection({ categoryId = null, value = [], onChange, errors = {} }) {
    const [categoryAttributes, setCategoryAttributes] = useState([]);
    const [loading, setLoading] = useState(false);
    // Храним введённые значения в ref для сохранения при смене категорий
    const valuesMapRef = useRef(new Map());

    // Инициализация valuesMapRef из начальных значений
    useEffect(() => {
        if (value && value.length > 0) {
            const map = new Map();
            value.forEach((v) => {
                map.set(v.attribute_id, {
                    attribute_value_id: v.attribute_value_id || null,
                    text_value: v.text_value || '',
                    number_value: v.number_value ?? '',
                    boolean_value: v.boolean_value ?? false,
                });
            });
            valuesMapRef.current = map;
        }
    }, []); // Только при монтировании

    // Загрузка атрибутов при изменении категории
    useEffect(() => {
        if (!categoryId) {
            setCategoryAttributes([]);
            return;
        }

        const fetchAttributes = async () => {
            setLoading(true);
            try {
                const response = await axios.get(route('admin.categories.attributes'), {
                    params: { category_ids: [categoryId] },
                });
                setCategoryAttributes(response.data || []);
            } catch (error) {
                console.error('Ошибка загрузки атрибутов категории:', error);
                setCategoryAttributes([]);
            } finally {
                setLoading(false);
            }
        };

        fetchAttributes();
    }, [categoryId]);

    // Формируем массив значений для onChange при изменении атрибутов или значений
    const emitChanges = useCallback((attrId, fieldName, fieldValue) => {
        const map = valuesMapRef.current;
        const existing = map.get(attrId) || {
            attribute_value_id: null,
            text_value: '',
            number_value: '',
            boolean_value: false,
        };
        existing[fieldName] = fieldValue;
        map.set(attrId, existing);

        // Собираем массив для отправки — только для текущих атрибутов в категории
        const result = [];
        categoryAttributes.forEach((attr) => {
            const vals = map.get(attr.id);
            if (vals) {
                // Проверяем, что есть хоть какое-то заполненное значение
                const hasValue =
                    vals.attribute_value_id ||
                    vals.text_value ||
                    vals.number_value !== '' ||
                    vals.boolean_value;

                if (hasValue) {
                    result.push({
                        attribute_id: attr.id,
                        attribute_value_id: vals.attribute_value_id || null,
                        text_value: vals.text_value || null,
                        number_value: vals.number_value !== '' ? parseFloat(vals.number_value) : null,
                        boolean_value: vals.boolean_value ?? null,
                    });
                }
            }
        });

        onChange(result);
    }, [categoryAttributes, onChange]);

    // Получить текущее значение для атрибута
    const getAttrValue = (attrId, field) => {
        const stored = valuesMapRef.current.get(attrId);
        if (stored) return stored[field];
        // Попробовать найти из начального value
        const fromValue = value.find((v) => v.attribute_id === attrId);
        if (fromValue) return fromValue[field];
        return field === 'boolean_value' ? false : (field === 'number_value' ? '' : '');
    };

    // Рендер поля ввода в зависимости от типа атрибута
    const renderAttributeField = (attr) => {
        const type = attr.type;

        switch (type) {
            case 'select':
                const selectOptions = (attr.values || []).map((v) => ({
                    value: v.id,
                    label: v.value,
                }));
                return (
                    <SelectRelation
                        label={attr.name + (attr.unit ? ` (${attr.unit})` : '')}
                        value={getAttrValue(attr.id, 'attribute_value_id')}
                        onChange={(val) => emitChanges(attr.id, 'attribute_value_id', val)}
                        options={selectOptions}
                        placeholder="Выберите значение"
                    />
                );

            case 'number':
                return (
                    <FormField label={attr.name + (attr.unit ? ` (${attr.unit})` : '')}>
                        <Input
                            type="number"
                            step="any"
                            value={getAttrValue(attr.id, 'number_value')}
                            onChange={(e) => emitChanges(attr.id, 'number_value', e.target.value)}
                            placeholder={`Введите ${attr.name.toLowerCase()}`}
                        />
                    </FormField>
                );

            case 'boolean':
                return (
                    <FormField label={attr.name}>
                        <Switch
                            checked={!!getAttrValue(attr.id, 'boolean_value')}
                            onCheckedChange={(e) => emitChanges(attr.id, 'boolean_value', e.checked)}
                            colorPalette="blue"
                        >
                            {getAttrValue(attr.id, 'boolean_value') ? 'Да' : 'Нет'}
                        </Switch>
                    </FormField>
                );

            case 'string':
            default:
                return (
                    <FormField label={attr.name + (attr.unit ? ` (${attr.unit})` : '')}>
                        <Input
                            value={getAttrValue(attr.id, 'text_value')}
                            onChange={(e) => emitChanges(attr.id, 'text_value', e.target.value)}
                            placeholder={`Введите ${attr.name.toLowerCase()}`}
                        />
                    </FormField>
                );
        }
    };

    return (
        <Card.Root>
            <Card.Header>
                <Box display="flex" alignItems="center" gap={2}>
                    <LuListChecks size={20} />
                    <Box fontSize="lg" fontWeight="semibold">
                        Атрибуты товара
                    </Box>
                </Box>
            </Card.Header>
            <Card.Body>
                {loading && (
                    <Box display="flex" justifyContent="center" py={6}>
                        <Spinner size="lg" />
                    </Box>
                )}

                {!loading && !categoryId && (
                    <Box textAlign="center" py={6} color="fg.muted">
                        <Text fontSize="md">
                            Выберите категорию во вкладке <Em>«Категории»</Em>, чтобы увидеть доступные атрибуты
                        </Text>
                    </Box>
                )}

                {!loading && categoryId && categoryAttributes.length === 0 && (
                    <Box textAlign="center" py={6} color="fg.muted">
                        <Text fontSize="md">
                            У выбранной категории нет привязанных атрибутов
                        </Text>
                    </Box>
                )}

                {!loading && categoryAttributes.length > 0 && (
                    <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                        {categoryAttributes.map((attr) => (
                            <Box key={attr.id}>
                                {renderAttributeField(attr)}
                            </Box>
                        ))}
                    </SimpleGrid>
                )}

                {errors && typeof errors === 'string' && (
                    <Box color="red.500" fontSize="sm" mt={2}>
                        {errors}
                    </Box>
                )}
            </Card.Body>
        </Card.Root>
    );
}
