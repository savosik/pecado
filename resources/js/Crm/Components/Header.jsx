import React from "react";
import { Box, HStack, IconButton, Text, Breadcrumb, Menu, Button } from "@chakra-ui/react";
import { usePage, router, Link } from "@inertiajs/react";
import { LuMenu, LuUser, LuLogOut, LuStore, LuShieldCheck } from "react-icons/lu";
import { Tooltip } from "@/components/ui/tooltip";
import { ColorModeButton } from "@/components/ui/color-mode";
import { menuConfig } from "../config/menuConfig";

// Карта «сегмент пути → русская подпись» для хлебных крошек.
const labelMap = {};
menuConfig.forEach((group) => {
    group.items.forEach((item) => {
        const segment = item.path.replace('/crm/', '').replace('/crm', '');
        if (segment) {
            labelMap[segment] = item.label;
        }
    });
});

export const Header = ({ onMobileMenuOpen, breadcrumbs = [] }) => {
    const page = usePage();
    const { auth } = page.props;

    const handleLogout = () => {
        router.post('/logout');
    };

    const autoGenerateBreadcrumbs = () => {
        const cleanUrl = page.url.split('?')[0];
        const parts = cleanUrl.split('/').filter(Boolean);

        if (parts.length === 1 && parts[0] === 'crm') {
            return [{ label: 'Рабочий стол', href: '/crm' }];
        }

        const crumbs = [{ label: 'Рабочий стол', href: '/crm' }];

        if (parts.length > 1) {
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
                                            color={index === finalBreadcrumbs.length - 1 ? "fg" : "fg.muted"}
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

                <HStack gap={2}>
                    {auth?.user?.is_admin && (
                        <Tooltip content="Перейти в админку" positioning={{ placement: "bottom" }}>
                            <Button asChild variant="outline" size="sm" colorPalette="gray">
                                <Link href="/admin">
                                    <LuShieldCheck />
                                    <Text display={{ base: "none", md: "inline" }}>Админка</Text>
                                </Link>
                            </Button>
                        </Tooltip>
                    )}

                    <Tooltip content="Перейти на витрину" positioning={{ placement: "bottom" }}>
                        <Button asChild variant="outline" size="sm" colorPalette="gray">
                            <Link href="/">
                                <LuStore />
                                <Text display={{ base: "none", sm: "inline" }}>На витрину</Text>
                            </Link>
                        </Button>
                    </Tooltip>

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
