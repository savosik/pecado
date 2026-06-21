import { useState } from 'react';
import { Box, Heading } from '@chakra-ui/react';
import { Button } from '@/components/ui/button';

/**
 * SeoTextBlock — сворачиваемый блок SEO-текста внизу категории.
 *
 * Полный текст всегда присутствует в DOM (для краулеров); в свёрнутом виде
 * визуально ограничен по высоте с плавным затуханием через CSS-маску
 * (не зависит от цвета фона). Контент — доверенный HTML из админки
 * (categories.description), может содержать ссылки для перелинковки (F07).
 *
 * @param {{ html?: string, title?: string }} props
 */
export default function SeoTextBlock({ html, title }) {
    const [expanded, setExpanded] = useState(false);

    if (!html || !html.trim()) return null;

    return (
        <Box as="section" mt="10" pt="6" borderTopWidth="1px" borderColor="border">
            {title && (
                <Heading as="h2" size="md" mb="3" color="fg">
                    {title}
                </Heading>
            )}

            <Box
                color="gray.600"
                _dark={{ color: 'gray.300' }}
                fontSize="sm"
                lineHeight="1.7"
                maxH={expanded ? 'none' : '160px'}
                overflow="hidden"
                css={{
                    ...(expanded ? {} : { maskImage: 'linear-gradient(to bottom, #000 70%, transparent)', WebkitMaskImage: 'linear-gradient(to bottom, #000 70%, transparent)' }),
                    '& p': { marginBottom: '0.75rem' },
                    '& ul, & ol': { paddingLeft: '1.25rem', marginBottom: '0.75rem' },
                    '& li': { marginBottom: '0.25rem' },
                    '& h2, & h3, & h4': { fontWeight: 600, color: 'inherit', marginTop: '1rem', marginBottom: '0.5rem' },
                    '& a': { color: 'var(--chakra-colors-color-palette-fg, #9e1b32)', textDecoration: 'underline' },
                }}
                dangerouslySetInnerHTML={{ __html: html }}
            />

            <Button variant="ghost" size="sm" mt="2" onClick={() => setExpanded((v) => !v)}>
                {expanded ? 'Свернуть' : 'Читать полностью'}
            </Button>
        </Box>
    );
}
