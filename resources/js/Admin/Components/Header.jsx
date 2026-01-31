import React from "react";
import { Box, HStack, IconButton, Text, Breadcrumb, Menu } from "@chakra-ui/react";
import { usePage, router } from "@inertiajs/react";
import { LuMenu, LuUser, LuLogOut } from "react-icons/lu";
import { ColorModeButton } from "@/components/ui/color-mode";

export const Header = ({ onMobileMenuOpen, breadcrumbs = [] }) => {
    const { auth } = usePage().props;

    const handleLogout = () => {
        router.post('/logout');
    };

    // Generate breadcrumbs from URL if not provided
    const autoGenerateBreadcrumbs = () => {
        const { url } = usePage();
        // Remove query parameters from URL
        const cleanUrl = url.split('?')[0];
        const parts = cleanUrl.split('/').filter(Boolean);

        if (parts.length === 1 && parts[0] === 'admin') {
            return [{ label: 'Главная', href: '/admin' }];
        }

        const crumbs = [{ label: 'Главная', href: '/admin' }];

        if (parts.length > 1) {
            // Simple label mapping for common routes
            const labelMap = {
                'products': 'Товары',
                'categories': 'Категории',
                'brands': 'Бренды',
                'users': 'Пользователи',
                'orders': 'Заказы',
                'articles': 'Статьи',
                'news': 'Новости',
                'faqs': 'FAQ',
                'settings': 'Настройки',
            };

            crumbs.push({
                label: labelMap[parts[1]] || parts[1],
                href: `/${parts.slice(0, 2).join('/')}`,
            });
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
            <HStack justify="space-between">
                {/* Left: Mobile menu button + Breadcrumbs */}
                <HStack gap={4}>
                    <IconButton
                        display={{ base: "flex", md: "none" }}
                        variant="ghost"
                        onClick={onMobileMenuOpen}
                        aria-label="Открыть меню"
                    >
                        <LuMenu />
                    </IconButton>

                    <Breadcrumb.Root>
                        <Breadcrumb.List>
                            {finalBreadcrumbs.map((crumb, index) => (
                                <React.Fragment key={index}>
                                    <Breadcrumb.Item>
                                        <Breadcrumb.Link
                                            href={crumb.href}
                                            fontSize="sm"
                                            color={index === finalBreadcrumbs.length - 1 ? "fg.default" : "fg.muted"}
                                            fontWeight={index === finalBreadcrumbs.length - 1 ? "medium" : "normal"}
                                        >
                                            {crumb.label}
                                        </Breadcrumb.Link>
                                    </Breadcrumb.Item>
                                    {index < finalBreadcrumbs.length - 1 && (
                                        <Breadcrumb.Separator>/</Breadcrumb.Separator>
                                    )}
                                </React.Fragment>
                            ))}
                        </Breadcrumb.List>
                    </Breadcrumb.Root>
                </HStack>

                {/* Right: Theme toggle + User menu */}
                <HStack gap={2}>
                    <ColorModeButton />

                    <Menu.Root>
                        <Menu.Trigger asChild>
                            <Box
                                px={3}
                                py={2}
                                borderRadius="md"
                                cursor="pointer"
                                _hover={{ bg: "bg.muted" }}
                                transition="background 0.2s"
                            >
                                <HStack gap={2}>
                                    <LuUser />
                                    <Text fontSize="sm" fontWeight="medium">
                                        {auth?.user?.name || 'Пользователь'}
                                    </Text>
                                </HStack>
                            </Box>
                        </Menu.Trigger>
                        <Menu.Content>
                            <Menu.Item value="profile" onClick={() => router.visit('/admin/profile')}>
                                <LuUser />
                                Профиль
                            </Menu.Item>
                            <Menu.Separator />
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
