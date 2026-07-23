import { Box, Flex, Icon, Text } from '@chakra-ui/react';
import { LuArrowDownUp, LuGrid2X2, LuLayoutList, LuList, LuChevronDown, LuPackage, LuSlidersHorizontal } from 'react-icons/lu';
import { useRef } from 'react';
import { FilterBadge } from './ProductFiltersSheet';

const PER_PAGE_OPTIONS = [10, 20, 40, 60, 100];

const SORT_LABELS = {
    newest: 'Новинки',
    popular: 'Популярные',
    price_asc: 'Цена ↑',
    price_desc: 'Цена ↓',
    name_asc: 'А → Я',
    name_desc: 'Я → А',
    article_asc: 'Артикул А–Я',
    article_desc: 'Артикул Я–А',
};

const STOCK_OPTIONS = [
    { value: '', label: 'Все' },
    { value: 'instock', label: 'В наличии' },
    { value: 'preorder', label: 'Предзаказ' },
    { value: 'notavailable', label: 'Нет в наличии' },
    { value: 'defect', label: 'Некондиция' },
];



/**
 * Обёртка-кнопка для одного контрола каталога.
 * Внутри: иконка слева, маленький лейбл + текущее значение, стрелочка справа.
 * При клике открывается скрытый <select>.
 */
function ControlButton({ icon, label, value, children }) {
    const selectRef = useRef(null);

    const handleClick = () => {
        if (selectRef.current) {
            // showPicker — современный API, click — fallback для старых браузеров
            if (typeof selectRef.current.showPicker === 'function') {
                try {
                    selectRef.current.showPicker();
                } catch {
                    selectRef.current.click();
                }
            } else {
                selectRef.current.click();
            }
        }
    };

    return (
        <Box position="relative" flexShrink="0">
            {/* Видимая кнопка */}
            <Flex
                as="button"
                type="button"
                onClick={handleClick}
                align="center"
                gap={{ base: '1.5', md: '2' }}
                px={{ base: '2.5', md: '3' }}
                py="1.5"
                bg="bg"
                border="1px solid"
                borderColor="border"
                borderRadius="lg"
                cursor="pointer"
                transition="all 0.15s"
                _hover={{ borderColor: 'gray.300', bg: 'gray.50' }}
                _dark={{
                    bg: 'gray.800',
                    borderColor: 'gray.600',
                    _hover: { borderColor: 'gray.500', bg: 'gray.700' },
                }}
                minH={{ base: '32px', md: '40px' }}
                aria-label={label}
            >
                {/* Иконка */}
                <Icon
                    as={icon}
                    boxSize="3.5"
                    color="gray.400"
                    _dark={{ color: 'gray.500' }}
                />
                {/* Лейбл + значение (md+) */}
                <Box
                    display={{ base: 'none', md: 'block' }}
                    textAlign="left"
                    lineHeight="1.2"
                >
                    <Text
                        fontSize="10px"
                        color="gray.400"
                        _dark={{ color: 'gray.500' }}
                        fontWeight="normal"
                    >
                        {label}
                    </Text>
                    <Text
                        fontSize="13px"
                        color="gray.800"
                        _dark={{ color: 'gray.100' }}
                        fontWeight="medium"
                        whiteSpace="nowrap"
                    >
                        {value}
                    </Text>
                </Box>
                {/* Только значение (base) */}
                <Text
                    display={{ base: 'block', md: 'none' }}
                    fontSize="13px"
                    color="gray.800"
                    _dark={{ color: 'gray.100' }}
                    fontWeight="medium"
                    whiteSpace="nowrap"
                    lineHeight="1"
                >
                    {value}
                </Text>
                {/* Шеврон */}
                <Icon
                    as={LuChevronDown}
                    boxSize="3"
                    color="gray.400"
                    _dark={{ color: 'gray.500' }}
                />
            </Flex>

            {/* Скрытый нативный select, занимает всю область кнопки */}
            <Box
                as="span"
                position="absolute"
                inset="0"
                overflow="hidden"
                opacity="0"
                css={{
                    '& select': {
                        position: 'absolute',
                        inset: 0,
                        width: '100%',
                        height: '100%',
                        opacity: 0,
                        cursor: 'pointer',
                        fontSize: '16px', // не даёт iOS зумить
                    },
                }}
            >
                {children(selectRef)}
            </Box>
        </Box>
    );
}

/**
 * CatalogControls — панель управления видом, сортировкой и количеством на страницу.
 * Дизайн: карточки-кнопки с иконками и двухстрочным содержимым (как в референсе).
 */
export default function CatalogControls({
    sort,
    view,
    perPage,
    sortOptions = [],
    onSortChange,
    onViewChange,
    onPerPageChange,
    inStockMode = '',
    onStockChange,
    showStockFilter = false,
    onOpenFilters,
    activeFilterCount = 0,
}) {
    const currentSortLabel =
        sortOptions.find((o) => o.value === sort)?.label
        ?? SORT_LABELS[sort]
        ?? sort;

    const currentStockLabel =
        STOCK_OPTIONS.find((o) => o.value === (inStockMode || ''))?.label
        ?? STOCK_OPTIONS[0].label;

    return (
        <Flex
            gap="2"
            align="center"
            flexWrap="wrap"
        >
            {/* Кнопка «Фильтры» — только на мобиле/планшете, в общем wrap-ряду с остальными контролами */}
            {onOpenFilters && (
                <Flex
                    as="button"
                    type="button"
                    onClick={onOpenFilters}
                    display={{ base: 'inline-flex', lg: 'none' }}
                    align="center"
                    gap={{ base: '1.5', md: '2' }}
                    px={{ base: '2.5', md: '3' }}
                    py="1.5"
                    bg="bg"
                    border="1px solid"
                    borderColor="border"
                    borderRadius="lg"
                    cursor="pointer"
                    transition="all 0.15s"
                    _hover={{ borderColor: 'gray.300', bg: 'gray.50' }}
                    _dark={{
                        bg: 'gray.800',
                        borderColor: 'gray.600',
                        _hover: { borderColor: 'gray.500', bg: 'gray.700' },
                    }}
                    minH={{ base: '32px', md: '40px' }}
                    flexShrink="0"
                >
                    <Icon as={LuSlidersHorizontal} boxSize="3.5" color="gray.400" _dark={{ color: 'gray.500' }} />
                    <Text
                        fontSize="13px"
                        color="gray.800"
                        _dark={{ color: 'gray.100' }}
                        fontWeight="medium"
                        whiteSpace="nowrap"
                        lineHeight="1"
                    >
                        Фильтры
                    </Text>
                    <FilterBadge count={activeFilterCount} />
                </Flex>
            )}

            {/* Наличие — только для авторизованных */}
                    {showStockFilter && (
                        <ControlButton
                            icon={LuPackage}
                            label="Наличие"
                            value={currentStockLabel}
                        >
                            {(ref) => (
                                <select
                                    ref={ref}
                                    value={inStockMode || ''}
                                    onChange={(e) => onStockChange?.(e.target.value)}
                                >
                                    {STOCK_OPTIONS.map((opt) => (
                                        <option key={opt.value || 'all'} value={opt.value}>
                                            {opt.label}
                                        </option>
                                    ))}
                                </select>
                            )}
                        </ControlButton>
                    )}

                    {/* Сортировка */}
                    <ControlButton
                        icon={LuArrowDownUp}
                        label="Сортировка"
                        value={currentSortLabel}
                    >
                        {(ref) => (
                            <select
                                ref={ref}
                                value={sort}
                                onChange={(e) => onSortChange(e.target.value)}
                            >
                                {sortOptions.map((opt) => (
                                    <option key={opt.value} value={opt.value}>
                                        {opt.label}
                                    </option>
                                ))}
                            </select>
                        )}
                    </ControlButton>

                    {/* Показывать по */}
                    <ControlButton
                        icon={LuList}
                        label="Показать"
                        value={String(perPage)}
                    >
                        {(ref) => (
                            <select
                                ref={ref}
                                value={perPage}
                                onChange={(e) =>
                                    onPerPageChange(Number(e.target.value))
                                }
                            >
                                {PER_PAGE_OPTIONS.map((n) => (
                                    <option key={n} value={n}>
                                        {n}
                                    </option>
                                ))}
                            </select>
                        )}
                    </ControlButton>

                    {/* Вид — toggle-кнопка */}
                    <Flex
                        align="center"
                        bg="bg"
                        border="1px solid"
                        borderColor="border"
                        borderRadius="lg"
                        overflow="hidden"
                        minH={{ base: '32px', md: '40px' }}
                        flexShrink="0"
                        _dark={{
                            bg: 'gray.800',
                            borderColor: 'gray.600',
                        }}
                    >
                        {[
                            { key: 'grid', icon: LuGrid2X2, title: 'Сетка' },
                            { key: 'list', icon: LuLayoutList, title: 'Список' },
                        ].map(({ key, icon: ViewIcon, title }) => {
                            const isActive = view === key;
                            return (
                                <Flex
                                    key={key}
                                    as="button"
                                    type="button"
                                    title={title}
                                    aria-label={title}
                                    onClick={() => onViewChange(key)}
                                    align="center"
                                    justify="center"
                                    px="2.5"
                                    h="100%"
                                    minH={{ base: '32px', md: '40px' }}
                                    cursor="pointer"
                                    transition="all 0.15s"
                                    bg={isActive ? 'gray.100' : 'transparent'}
                                    _hover={{
                                        bg: isActive ? 'gray.100' : 'gray.50',
                                    }}
                                    _dark={{
                                        bg: isActive ? 'gray.700' : 'transparent',
                                        _hover: {
                                            bg: isActive ? 'gray.700' : 'gray.600',
                                        },
                                    }}
                                >
                                    <Icon
                                        as={ViewIcon}
                                        boxSize={{ base: '3.5', md: '4' }}
                                        color={isActive ? 'gray.800' : 'gray.400'}
                                        _dark={{
                                            color: isActive ? 'gray.100' : 'gray.500',
                                        }}
                                    />
                                </Flex>
                            );
                        })}
                    </Flex>
        </Flex>
    );
}
