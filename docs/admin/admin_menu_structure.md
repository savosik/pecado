# Структура меню админ-панели

Данный документ описывает структуру навигационного меню для админ-панели, построенной на Chakra UI v3. Меню логически сгруппировано по функциональным областям приложения и полностью соответствует схеме базы данных.

---

## Группы меню

### 1. 📊 Главная (Dashboard)
| Пункт | Иконка | Путь | Описание |
|-------|--------|------|----------|
| Обзор | `LuLayoutDashboard` | `/admin` | Главная страница с аналитикой |

---

### 2. 🛒 Каталог (Catalog)
Управление товарами и их характеристиками.

| Пункт | Иконка | Путь | Описание |
|-------|--------|------|----------|
| Товары | `LuPackage` | `/admin/products` | CRUD для товаров |
| Категории | `LuFolderTree` | `/admin/categories` | Иерархия категорий |
| Бренды | `LuBadge` | `/admin/brands` | Управление брендами |
| Модели | `LuBox` | `/admin/product-models` | Модели товаров (привязка к брендам) |
| Атрибуты | `LuList` | `/admin/attributes` | Атрибуты товаров и их значения |
| Размерные сетки | `LuRuler` | `/admin/size-charts` | Таблицы размеров |
| Штрихкоды | `LuBarcode` | `/admin/product-barcodes` | Штрихкоды товаров |
| Сертификаты | `LuShieldCheck` | `/admin/certificates` | Сертификаты качества |
| Сегменты | `LuPieChart` | `/admin/segments` | Сегментация товаров |

---

### 3. 📦 Склады (Warehouses)
Управление складами и региональной логистикой.

| Пункт | Иконка | Путь | Описание |
|-------|--------|------|----------|
| Склады | `LuWarehouse` | `/admin/warehouses` | Управление складами |
| Регионы | `LuMapPin` | `/admin/regions` | Регионы и привязка к складам |

---

### 4. � Продажи (Sales)
Заказы, корзины и возвраты.

| Пункт | Иконка | Путь | Описание |
|-------|--------|------|----------|
| Заказы | `LuShoppingCart` | `/admin/orders` | Управление заказами |
| Корзины | `LuShoppingBag` | `/admin/carts` | Просмотр корзин пользователей |
| Возвраты | `LuUndo2` | `/admin/returns` | Управление возвратами |
| Избранное | `LuHeart` | `/admin/favorites` | Избранное пользователей |
| Список желаний | `LuBookmark` | `/admin/wishlist` | Списки желаний |

---

### 5. 🎯 Маркетинг (Marketing)
Акции, скидки и подборки товаров.

| Пункт | Иконка | Путь | Описание |
|-------|--------|------|----------|
| Акции | `LuTicket` | `/admin/promotions` | Рекламные акции |
| Скидки | `LuPercent` | `/admin/discounts` | Управление скидками |
| Подборки | `LuLayoutGrid` | `/admin/product-selections` | Тематические подборки товаров |

---

### 6. 👥 Пользователи (Users)
Управление пользователями и их данными.

| Пункт | Иконка | Путь | Описание |
|-------|--------|------|----------|
| Пользователи | `LuUsers` | `/admin/users` | Все пользователи |
| Компании | `LuBuilding2` | `/admin/companies` | Компании клиентов |
| Банковские счета | `LuCreditCard` | `/admin/company-bank-accounts` | Банковские реквизиты компаний |
| Адреса доставки | `LuMapPinned` | `/admin/delivery-addresses` | Адреса доставки |

---

### 7. 💵 Финансы (Finance)
Валюты и балансы.

| Пункт | Иконка | Путь | Описание |
|-------|--------|------|----------|
| Валюты | `LuBanknote` | `/admin/currencies` | Управление валютами |
| Балансы | `LuWallet` | `/admin/user-balances` | Балансы пользователей |

---

### 8. 📝 Контент (Content)
Управление контентом сайта.

| Пункт | Иконка | Путь | Описание |
|-------|--------|------|----------|
| Статьи | `LuFileText` | `/admin/articles` | Управление статьями |
| Новости | `LuNewspaper` | `/admin/news` | Лента новостей |
| FAQ | `LuHelpCircle` | `/admin/faqs` | Вопросы и ответы |
| Баннеры | `LuImage` | `/admin/banners` | Рекламные баннеры |
| Страницы | `LuFile` | `/admin/pages` | Статические страницы |
| Истории | `LuCirclePlay` | `/admin/stories` | Истории (сторисы) |

---

### 9. 🏷️ Теги (Tags)
Полиморфная система тегов.

| Пункт | Иконка | Путь | Описание |
|-------|--------|------|----------|
| Теги | `LuTags` | `/admin/tags` | Управление тегами |

---

### 10. ⚙️ Система (System)
Системные настройки и медиа.

| Пункт | Иконка | Путь | Описание |
|-------|--------|------|----------|
| Медиа | `LuImagePlay` | `/admin/media` | Управление медиафайлами |
| Настройки | `LuSettings` | `/admin/settings` | Общие настройки |

---

## Реализация на Chakra UI v3

### Структура данных для меню

```tsx
import {
  LuLayoutDashboard,
  LuPackage,
  LuFolderTree,
  LuBadge,
  LuBox,
  LuList,
  LuRuler,
  LuBarcode,
  LuShieldCheck,
  LuPieChart,
  LuWarehouse,
  LuMapPin,
  LuShoppingCart,
  LuShoppingBag,
  LuUndo2,
  LuHeart,
  LuBookmark,
  LuTicket,
  LuPercent,
  LuLayoutGrid,
  LuUsers,
  LuBuilding2,
  LuCreditCard,
  LuMapPinned,
  LuBanknote,
  LuWallet,
  LuFileText,
  LuNewspaper,
  LuHelpCircle,
  LuImage,
  LuFile,
  LuCirclePlay,
  LuTags,
  LuImagePlay,
  LuSettings,
} from "react-icons/lu";

export interface MenuItem {
  label: string;
  icon: React.ElementType;
  path: string;
}

export interface MenuGroup {
  title: string;
  icon: React.ElementType;
  items: MenuItem[];
}

export const menuConfig: MenuGroup[] = [
  {
    title: "Главная",
    icon: LuLayoutDashboard,
    items: [
      { label: "Обзор", icon: LuLayoutDashboard, path: "/admin" },
    ],
  },
  {
    title: "Каталог",
    icon: LuPackage,
    items: [
      { label: "Товары", icon: LuPackage, path: "/admin/products" },
      { label: "Категории", icon: LuFolderTree, path: "/admin/categories" },
      { label: "Бренды", icon: LuBadge, path: "/admin/brands" },
      { label: "Модели", icon: LuBox, path: "/admin/product-models" },
      { label: "Атрибуты", icon: LuList, path: "/admin/attributes" },
      { label: "Размерные сетки", icon: LuRuler, path: "/admin/size-charts" },
      { label: "Штрихкоды", icon: LuBarcode, path: "/admin/product-barcodes" },
      { label: "Сертификаты", icon: LuShieldCheck, path: "/admin/certificates" },
      { label: "Сегменты", icon: LuPieChart, path: "/admin/segments" },
    ],
  },
  {
    title: "Склады",
    icon: LuWarehouse,
    items: [
      { label: "Склады", icon: LuWarehouse, path: "/admin/warehouses" },
      { label: "Регионы", icon: LuMapPin, path: "/admin/regions" },
    ],
  },
  {
    title: "Продажи",
    icon: LuShoppingCart,
    items: [
      { label: "Заказы", icon: LuShoppingCart, path: "/admin/orders" },
      { label: "Корзины", icon: LuShoppingBag, path: "/admin/carts" },
      { label: "Возвраты", icon: LuUndo2, path: "/admin/returns" },
      { label: "Избранное", icon: LuHeart, path: "/admin/favorites" },
      { label: "Список желаний", icon: LuBookmark, path: "/admin/wishlist" },
    ],
  },
  {
    title: "Маркетинг",
    icon: LuTicket,
    items: [
      { label: "Акции", icon: LuTicket, path: "/admin/promotions" },
      { label: "Скидки", icon: LuPercent, path: "/admin/discounts" },
      { label: "Подборки", icon: LuLayoutGrid, path: "/admin/product-selections" },
    ],
  },
  {
    title: "Пользователи",
    icon: LuUsers,
    items: [
      { label: "Пользователи", icon: LuUsers, path: "/admin/users" },
      { label: "Компании", icon: LuBuilding2, path: "/admin/companies" },
      { label: "Банковские счета", icon: LuCreditCard, path: "/admin/company-bank-accounts" },
      { label: "Адреса доставки", icon: LuMapPinned, path: "/admin/delivery-addresses" },
    ],
  },
  {
    title: "Финансы",
    icon: LuBanknote,
    items: [
      { label: "Валюты", icon: LuBanknote, path: "/admin/currencies" },
      { label: "Балансы", icon: LuWallet, path: "/admin/user-balances" },
    ],
  },
  {
    title: "Контент",
    icon: LuFileText,
    items: [
      { label: "Статьи", icon: LuFileText, path: "/admin/articles" },
      { label: "Новости", icon: LuNewspaper, path: "/admin/news" },
      { label: "FAQ", icon: LuHelpCircle, path: "/admin/faqs" },
      { label: "Баннеры", icon: LuImage, path: "/admin/banners" },
      { label: "Страницы", icon: LuFile, path: "/admin/pages" },
      { label: "Истории", icon: LuCirclePlay, path: "/admin/stories" },
    ],
  },
  {
    title: "Теги",
    icon: LuTags,
    items: [
      { label: "Теги", icon: LuTags, path: "/admin/tags" },
    ],
  },
  {
    title: "Система",
    icon: LuSettings,
    items: [
      { label: "Медиа", icon: LuImagePlay, path: "/admin/media" },
      { label: "Настройки", icon: LuSettings, path: "/admin/settings" },
    ],
  },
];
```

---

### Компонент Sidebar с Accordion

```tsx
import { Box, Accordion, HStack, Text, Icon, VStack } from "@chakra-ui/react";
import { Link, usePage } from "@inertiajs/react";
import { LuChevronDown } from "react-icons/lu";
import { menuConfig, MenuGroup, MenuItem } from "./menuConfig";

interface SidebarProps {
  isCollapsed?: boolean;
}

export const Sidebar = ({ isCollapsed = false }: SidebarProps) => {
  const { url } = usePage();

  const isActive = (path: string) => {
    if (path === "/admin") {
      return url === "/admin" || url === "/admin/";
    }
    return url.startsWith(path);
  };

  return (
    <Box
      as="nav"
      position="fixed"
      left={0}
      top={0}
      h="100vh"
      w={isCollapsed ? "16" : "64"}
      bg="bg.panel"
      borderRightWidth="1px"
      borderColor="border.muted"
      py={4}
      overflowY="auto"
      transition="width 0.2s"
    >
      {/* Логотип */}
      <Box px={4} mb={6}>
        <Text fontSize="xl" fontWeight="bold" color="fg.default">
          {isCollapsed ? "P" : "Pecado Admin"}
        </Text>
      </Box>

      {/* Навигация */}
      <Accordion.Root collapsible multiple defaultValue={["Каталог", "Контент"]}>
        {menuConfig.map((group) => (
          <Accordion.Item key={group.title} value={group.title}>
            <Accordion.ItemTrigger
              px={4}
              py={2}
              _hover={{ bg: "bg.muted" }}
              cursor="pointer"
            >
              <HStack flex="1" gap={3}>
                <Icon as={group.icon} boxSize={5} color="fg.muted" />
                {!isCollapsed && (
                  <Text fontSize="sm" fontWeight="medium" color="fg.default">
                    {group.title}
                  </Text>
                )}
              </HStack>
              {!isCollapsed && <Accordion.ItemIndicator />}
            </Accordion.ItemTrigger>

            <Accordion.ItemContent>
              <Accordion.ItemBody>
                <VStack align="stretch" gap={0} pl={isCollapsed ? 0 : 4}>
                  {group.items.map((item) => (
                    <Link key={item.path} href={item.path}>
                      <HStack
                        px={4}
                        py={2}
                        gap={3}
                        bg={isActive(item.path) ? "bg.emphasized" : "transparent"}
                        color={isActive(item.path) ? "fg.default" : "fg.muted"}
                        borderRadius="md"
                        _hover={{ bg: "bg.muted", color: "fg.default" }}
                        transition="all 0.2s"
                      >
                        <Icon as={item.icon} boxSize={4} />
                        {!isCollapsed && (
                          <Text fontSize="sm">{item.label}</Text>
                        )}
                      </HStack>
                    </Link>
                  ))}
                </VStack>
              </Accordion.ItemBody>
            </Accordion.ItemContent>
          </Accordion.Item>
        ))}
      </Accordion.Root>
    </Box>
  );
};
```

---

### Альтернативный вариант: Плоский список с группировкой

```tsx
import { Box, VStack, HStack, Text, Icon, Separator } from "@chakra-ui/react";
import { Link, usePage } from "@inertiajs/react";
import { menuConfig } from "./menuConfig";

export const SidebarFlat = () => {
  const { url } = usePage();

  const isActive = (path: string) => url.startsWith(path);

  return (
    <Box as="nav" w="64" bg="bg.panel" h="100vh" p={4}>
      <VStack align="stretch" gap={1}>
        {menuConfig.map((group, groupIndex) => (
          <Box key={group.title}>
            {groupIndex > 0 && <Separator my={3} />}
            
            <Text
              fontSize="xs"
              fontWeight="semibold"
              color="fg.muted"
              textTransform="uppercase"
              letterSpacing="wide"
              mb={2}
              px={3}
            >
              {group.title}
            </Text>

            <VStack align="stretch" gap={0}>
              {group.items.map((item) => (
                <Link key={item.path} href={item.path}>
                  <HStack
                    px={3}
                    py={2}
                    gap={3}
                    borderRadius="md"
                    bg={isActive(item.path) ? "colorPalette.subtle" : "transparent"}
                    color={isActive(item.path) ? "colorPalette.fg" : "fg.default"}
                    _hover={{
                      bg: isActive(item.path) ? "colorPalette.subtle" : "bg.muted",
                    }}
                    transition="all 0.15s"
                  >
                    <Icon as={item.icon} boxSize={5} />
                    <Text fontSize="sm">{item.label}</Text>
                  </HStack>
                </Link>
              ))}
            </VStack>
          </Box>
        ))}
      </VStack>
    </Box>
  );
};
```

---

## Визуальная схема меню

```
┌─────────────────────────────┐
│  🏠 Pecado Admin            │
├─────────────────────────────┤
│                             │
│  📊 ГЛАВНАЯ                 │
│     └─ Обзор                │
│                             │
│  🛒 КАТАЛОГ                 │
│     ├─ Товары               │
│     ├─ Категории            │
│     ├─ Бренды               │
│     ├─ Модели               │
│     ├─ Атрибуты             │
│     ├─ Размерные сетки      │
│     ├─ Штрихкоды            │
│     ├─ Сертификаты          │
│     └─ Сегменты             │
│                             │
│  📦 СКЛАДЫ                  │
│     ├─ Склады               │
│     └─ Регионы              │
│                             │
│  � ПРОДАЖИ                 │
│     ├─ Заказы               │
│     ├─ Корзины              │
│     ├─ Возвраты             │
│     ├─ Избранное            │
│     └─ Список желаний       │
│                             │
│  🎯 МАРКЕТИНГ               │
│     ├─ Акции                │
│     ├─ Скидки               │
│     └─ Подборки             │
│                             │
│  👥 ПОЛЬЗОВАТЕЛИ            │
│     ├─ Пользователи         │
│     ├─ Компании             │
│     ├─ Банковские счета     │
│     └─ Адреса доставки      │
│                             │
│  💵 ФИНАНСЫ                 │
│     ├─ Валюты               │
│     └─ Балансы              │
│                             │
│  📝 КОНТЕНТ                 │
│     ├─ Статьи               │
│     ├─ Новости              │
│     ├─ FAQ                  │
│     ├─ Баннеры              │
│     ├─ Страницы             │
│     └─ Истории              │
│                             │
│  🏷️ ТЕГИ                    │
│     └─ Теги                 │
│                             │
│  ⚙️ СИСТЕМА                 │
│     ├─ Медиа                │
│     └─ Настройки            │
│                             │
└─────────────────────────────┘
```

---

## Соответствие схеме данных

| Группа меню | Таблицы БД |
|-------------|-----------|
| Главная | — |
| Каталог | `products`, `categories`, `brands`, `product_models`, `attributes`, `attribute_values`, `size_charts`, `product_barcodes`, `certificates`, `segments` |
| Склады | `warehouses`, `regions`, `product_warehouse`, `region_warehouse` |
| Продажи | `orders`, `order_items`, `carts`, `cart_items`, `returns`, `return_items`, `favorites`, `wishlist_items` |
| Маркетинг | `promotions`, `discounts`, `product_selections` |
| Пользователи | `users`, `companies`, `company_bank_accounts`, `delivery_addresses` |
| Финансы | `currencies`, `user_balances` |
| Контент | `articles`, `news`, `faqs`, `banners`, `pages`, `stories`, `story_slides` |
| Теги | `tags`, `taggables` |
| Система | `media` |

---

## Зависимости

```bash
npm install react-icons
```

---

## Рекомендации по реализации

1. **Активное состояние** — выделяйте текущий пункт меню с помощью `bg.emphasized` или `colorPalette.subtle`
2. **Иконки** — используйте библиотеку `react-icons/lu` (Lucide Icons) для единообразия
3. **Доступность** — добавьте `aria-current="page"` для активных ссылок
4. **Мобильная версия** — используйте `Drawer` для мобильного меню
5. **Анимации** — добавьте плавные переходы (`transition`) при наведении и раскрытии групп
6. **Вложенные сущности** — `order_items`, `cart_items`, `return_items`, `story_slides`, `attribute_values` не вынесены в отдельные пункты, так как редактируются в контексте родительских сущностей
