import React, { useMemo } from 'react';
import { Box, HStack, IconButton, Text, Breadcrumb, Menu, Button } from '@chakra-ui/react';
import { usePage, router, Link } from '@inertiajs/react';
import { LuMenu, LuUser, LuLogOut, LuStore } from 'react-icons/lu';
import { Tooltip } from '@/components/ui/tooltip';
import { ColorModeButton } from '@/components/ui/color-mode';
import { usePanel } from './PanelContext';
import { PANELS } from './panels';

const ACTION_LABELS = {
    create: 'Создание',
    edit: 'Редактирование',
};

export const Header = ({ onMobileMenuOpen, breadcrumbs = [] }) => {
    const page = usePage();
    const { auth } = page.props;
    const panel = usePanel();
    const { key, basePath, menuConfig, homeLabel, actionBreadcrumbs = false, profileHref } = panel;

    // Карта «сегмент пути → русская подпись» для хлебных крошек.
    const labelMap = useMemo(() => {
        const map = {};

        menuConfig.forEach((group) => {
            group.items.forEach((item) => {
                const segment = item.path.replace(`${basePath}/`, '').replace(basePath, '');
                if (segment) {
                    map[segment] = item.label;
                }
            });
        });

        return map;
    }, [menuConfig, basePath]);

    // Ссылки на остальные панели, доступные пользователю.
    const otherPanels = PANELS.filter((p) => p.key !== key && auth?.user?.[p.flag]);

    const handleLogout = () => {
        router.post('/logout');
    };

    const autoGenerateBreadcrumbs = () => {
        const cleanUrl = page.url.split('?')[0];
        const parts = cleanUrl.split('/').filter(Boolean);
        const rootSegment = basePath.replace('/', '');

        if (parts.length === 1 && parts[0] === rootSegment) {
            return [{ label: homeLabel, href: basePath }];
        }

        const crumbs = [{ label: homeLabel, href: basePath }];

        if (parts.length > 1) {
            crumbs.push({
                label: labelMap[parts[1]] || parts[1],
                href: `/${parts.slice(0, 2).join('/')}`,
            });
        }

        if (actionBreadcrumbs && parts.length > 2) {
            const action = ACTION_LABELS[parts[parts.length - 1]];

            if (action) {
                crumbs.push({ label: action, href: cleanUrl });
            }
        }

        return crumbs;
    };

    const finalBreadcrumbs = breadcrumbs.length > 0 ? breadcrumbs : autoGenerateBreadcrumbs();

    return (
        <Box
            as="header"
            position="sticky"
            top={0}
            bg="bg.panel"
            borderBottomWidth="1px"
            borderColor="border.muted"
            px={{ base: 4, md: 6 }}
            py={3}
            zIndex={5}
        >
            <HStack justify="space-between" gap={2}>
                {/* Слева: кнопка мобильного меню + хлебные крошки.
                    minW=0 обязателен: без него длинная крошка не даёт flex-контейнеру
                    сжаться и текст ломается по одной букве в строку (телефон). */}
                <HStack gap={{ base: 2, md: 4 }} minW={0} flex="1">
                    <IconButton
                        display={{ base: 'flex', md: 'none' }}
                        variant="ghost"
                        onClick={onMobileMenuOpen}
                        aria-label="Открыть меню"
                        flexShrink={0}
                        minW="44px"
                        h="44px"
                    >
                        <LuMenu />
                    </IconButton>

                    <Breadcrumb.Root minW={0} overflow="hidden">
                        <Breadcrumb.List flexWrap="nowrap" whiteSpace="nowrap">
                            {finalBreadcrumbs.map((crumb, index) => {
                                const isLast = index === finalBreadcrumbs.length - 1;

                                return (
                                    <React.Fragment key={index}>
                                        {/* На телефоне оставляем только текущий раздел —
                                            путь целиком туда не помещается. */}
                                        <Breadcrumb.Item
                                            display={{ base: isLast ? 'inline-flex' : 'none', md: 'inline-flex' }}
                                            minW={0}
                                        >
                                            <Breadcrumb.Link
                                                href={crumb.href}
                                                fontSize="sm"
                                                color={isLast ? 'fg' : 'fg.muted'}
                                                fontWeight={isLast ? 'medium' : 'normal'}
                                                truncate
                                            >
                                                {crumb.label}
                                            </Breadcrumb.Link>
                                        </Breadcrumb.Item>
                                        {!isLast && (
                                            <Breadcrumb.Separator display={{ base: 'none', md: 'inline-flex' }}>
                                                /
                                            </Breadcrumb.Separator>
                                        )}
                                    </React.Fragment>
                                );
                            })}
                        </Breadcrumb.List>
                    </Breadcrumb.Root>
                </HStack>

                {/* Справа: переходы между панелями, тема, меню пользователя.
                    На телефоне переходы и имя убраны в меню пользователя — иначе
                    правая группа съедает всю ширину строки. */}
                <HStack gap={{ base: 1, md: 2 }} flexShrink={0}>
                    {otherPanels.map((target) => (
                        <Tooltip
                            key={target.key}
                            content={`Перейти в раздел «${target.label}»`}
                            positioning={{ placement: 'bottom' }}
                        >
                            <Button
                                asChild
                                variant="outline"
                                size="sm"
                                colorPalette="gray"
                                display={{ base: 'none', md: 'inline-flex' }}
                            >
                                <Link href={target.basePath}>
                                    <target.icon />
                                    <Text>{target.label}</Text>
                                </Link>
                            </Button>
                        </Tooltip>
                    ))}

                    <Tooltip content="Перейти на витрину" positioning={{ placement: 'bottom' }}>
                        <Button
                            asChild
                            variant="outline"
                            size="sm"
                            colorPalette="gray"
                            display={{ base: 'none', md: 'inline-flex' }}
                        >
                            <Link href="/">
                                <LuStore />
                                <Text>На витрину</Text>
                            </Link>
                        </Button>
                    </Tooltip>

                    <ColorModeButton />

                    <Menu.Root>
                        <Menu.Trigger asChild>
                            <Box
                                px={{ base: 2, md: 3 }}
                                py={2}
                                minW="44px"
                                minH="44px"
                                display="flex"
                                alignItems="center"
                                justifyContent="center"
                                borderRadius="md"
                                cursor="pointer"
                                _hover={{ bg: 'bg.muted' }}
                                transition="background 0.2s"
                                aria-label="Меню пользователя"
                            >
                                <HStack gap={2}>
                                    <LuUser />
                                    <Text
                                        fontSize="sm"
                                        fontWeight="medium"
                                        display={{ base: 'none', md: 'inline' }}
                                    >
                                        {auth?.user?.name || 'Пользователь'}
                                    </Text>
                                </HStack>
                            </Box>
                        </Menu.Trigger>
                        <Menu.Content>
                            <Menu.ItemGroup>
                                <Menu.ItemGroupLabel>
                                    {auth?.user?.name || 'Пользователь'}
                                </Menu.ItemGroupLabel>
                            </Menu.ItemGroup>
                            <Menu.Separator />

                            {/* Дубли кнопок, скрытых на телефоне. hideFrom вместо
                                обёртки в Box: обёртка ломает клавиатурную навигацию меню. */}
                            {otherPanels.map((target) => (
                                <Menu.Item
                                    key={target.key}
                                    value={`panel-${target.key}`}
                                    hideFrom="md"
                                    onClick={() => router.visit(target.basePath)}
                                >
                                    <target.icon />
                                    {target.label}
                                </Menu.Item>
                            ))}
                            <Menu.Item value="storefront" hideFrom="md" onClick={() => router.visit('/')}>
                                <LuStore />
                                На витрину
                            </Menu.Item>
                            <Menu.Separator hideFrom="md" />

                            {profileHref && (
                                <>
                                    <Menu.Item value="profile" onClick={() => router.visit(profileHref)}>
                                        <LuUser />
                                        Профиль
                                    </Menu.Item>
                                    <Menu.Separator />
                                </>
                            )}
                            <Menu.Item value="logout" onClick={handleLogout} color="fg.error">
                                <LuLogOut />
                                Выйти
                            </Menu.Item>
                        </Menu.Content>
                    </Menu.Root>
                </HStack>
            </HStack>
        </Box>
    );
};
