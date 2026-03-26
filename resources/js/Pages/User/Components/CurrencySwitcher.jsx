import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import {
    Button, Menu, Portal, Text, HStack, Box,
} from '@chakra-ui/react';
import { LuChevronDown, LuCheck } from 'react-icons/lu';

export default function CurrencySwitcher() {
    const { currency } = usePage().props;
    const [isSubmitting, setIsSubmitting] = useState(false);

    if (!currency) return null;

    const availableCurrencies = currency.available || [];

    if (availableCurrencies.length <= 1) return null;

    const handleSwitch = (code) => {
        if (code === currency.code || isSubmitting) return;

        setIsSubmitting(true);

        router.post(
            '/api/currency/switch',
            { code },
            {
                preserveScroll: true,
                onFinish: () => setIsSubmitting(false),
            },
        );
    };

    return (
        <Menu.Root>
            <Menu.Trigger asChild>
                <Button
                    variant="ghost"
                    size="sm"
                    colorPalette="gray"
                    disabled={isSubmitting}
                    gap="1"
                >
                    <Text fontWeight="600" fontSize="sm">{currency.symbol}</Text>
                    <Text fontSize="xs" color="gray.500">{currency.code}</Text>
                    <LuChevronDown size={14} />
                </Button>
            </Menu.Trigger>
            <Portal>
                <Menu.Positioner>
                    <Menu.Content minW="180px" zIndex="popover">
                        {availableCurrencies.map((c) => (
                            <Menu.Item
                                key={c.code}
                                value={c.code}
                                onClick={() => handleSwitch(c.code)}
                                disabled={c.code === currency.code || isSubmitting}
                            >
                                <HStack gap="2" flex="1">
                                    <Text fontWeight="600">{c.symbol}</Text>
                                    <Text fontWeight="500">{c.code}</Text>
                                    <Text fontSize="xs" color="gray.500">{c.name}</Text>
                                </HStack>
                                {c.code === currency.code && (
                                    <Box color="green.500"><LuCheck size={16} /></Box>
                                )}
                            </Menu.Item>
                        ))}
                    </Menu.Content>
                </Menu.Positioner>
            </Portal>
        </Menu.Root>
    );
}
