import { Component } from 'react';
import { Box, VStack, Heading, Text, Button, Icon } from '@chakra-ui/react';
import { LuTriangleAlert } from 'react-icons/lu';

/**
 * React Error Boundary — перехватывает ошибки рендеринга и показывает fallback UI.
 *
 * @example
 * <ErrorBoundary>
 *   <App />
 * </ErrorBoundary>
 */
export default class ErrorBoundary extends Component {
    constructor(props) {
        super(props);
        this.state = { hasError: false };
    }

    static getDerivedStateFromError() {
        return { hasError: true };
    }

    componentDidCatch(error, errorInfo) {
        if (import.meta.env.DEV) {
            console.error('[ErrorBoundary] Перехвачена ошибка:', error, errorInfo);
        }
    }

    handleReload = () => {
        window.location.reload();
    };

    render() {
        if (this.state.hasError) {
            return (
                <Box
                    minH="100vh"
                    display="flex"
                    alignItems="center"
                    justifyContent="center"
                    bg="bg"
                    px={4}
                >
                    <VStack gap={6} textAlign="center" maxW="md">
                        <Icon
                            as={LuTriangleAlert}
                            boxSize={16}
                            color="pecado.fg"
                        />
                        <Heading size="xl" color="fg">
                            Что-то пошло не так
                        </Heading>
                        <Text color="fg.muted" fontSize="md">
                            Произошла непредвиденная ошибка. Попробуйте обновить страницу.
                            Если проблема сохраняется, свяжитесь с&nbsp;поддержкой.
                        </Text>
                        <Button
                            colorPalette="pecado"
                            size="lg"
                            onClick={this.handleReload}
                        >
                            Обновить страницу
                        </Button>
                    </VStack>
                </Box>
            );
        }

        return this.props.children;
    }
}
