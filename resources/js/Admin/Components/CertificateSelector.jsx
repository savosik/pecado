import React, { useState, useEffect, useRef } from 'react';
import {
    Box,
    Input,
    HStack,
    VStack,
    Text,
    Badge,
    IconButton,
    Spinner,
} from '@chakra-ui/react';
import { LuX, LuCheck, LuCalendar } from 'react-icons/lu';
import axios from 'axios';

/**
 * CertificateSelector - Component for searching and selecting certificates
 *
 * @param {Array} value - Array of selected certificates (objects {id, name, type, status})
 * @param {Function} onChange - Callback (new value array)
 * @param {string} error - Validation error
 */
export const CertificateSelector = ({
    value = [],
    onChange,
    error = null,
}) => {
    const [query, setQuery] = useState('');
    const [suggestions, setSuggestions] = useState([]);
    const [loading, setLoading] = useState(false);
    const [showSuggestions, setShowSuggestions] = useState(false);
    const containerRef = useRef(null);

    // Click outside handler
    useEffect(() => {
        const handleClickOutside = (event) => {
            if (containerRef.current && !containerRef.current.contains(event.target)) {
                setShowSuggestions(false);
            }
        };
        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    // Search logic with debounce
    useEffect(() => {
        const timer = setTimeout(() => {
            if (query.trim().length >= 2) {
                searchCertificates(query);
            } else {
                setSuggestions([]);
                setShowSuggestions(false);
            }
        }, 300);

        return () => clearTimeout(timer);
    }, [query]);

    const searchCertificates = async (searchQuery) => {
        setLoading(true);
        try {
            const response = await axios.get(route('admin.certificates.search'), {
                params: { query: searchQuery }
            });
            // Filter out already selected
            const selectedIds = value.map(c => c.id);
            const filtered = response.data.filter(c => !selectedIds.includes(c.id));
            setSuggestions(filtered);
            setShowSuggestions(true);
        } catch (error) {
            console.error('Search error:', error);
        } finally {
            setLoading(false);
        }
    };

    const handleSelect = (certificate) => {
        const formattedCert = {
            id: certificate.id,
            name: certificate.name,
            type: certificate.type,
            status: certificate.status || 'active', // Default if not provided
        };
        onChange([...value, formattedCert]);
        setQuery('');
        setSuggestions([]);
        setShowSuggestions(false);
    };

    const handleRemove = (id) => {
        onChange(value.filter(c => c.id !== id));
    };

    return (
        <Box ref={containerRef} width="100%">
            {/* Search Input */}
            <Box position="relative" mb={4}>
                <Input
                    placeholder="Поиск сертификата..."
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    borderColor={error ? "red.500" : "border.muted"}
                />

                {/* Suggestions Dropdown */}
                {showSuggestions && (suggestions.length > 0 || loading) && (
                    <Box
                        position="absolute"
                        top="100%"
                        left={0}
                        right={0}
                        zIndex={1000}
                        bg="bg.panel"
                        boxShadow="lg"
                        borderRadius="md"
                        mt={1}
                        maxHeight="300px"
                        overflowY="auto"
                        borderWidth="1px"
                        borderColor="border.muted"
                    >
                        {loading && suggestions.length === 0 ? (
                            <Box p={4} textAlign="center">
                                <Spinner size="sm" />
                            </Box>
                        ) : (
                            <VStack align="stretch" gap={0}>
                                {suggestions.map((cert) => (
                                    <HStack
                                        key={cert.id}
                                        p={3}
                                        cursor="pointer"
                                        _hover={{ bg: "bg.muted" }}
                                        onClick={() => handleSelect(cert)}
                                        borderBottomWidth="1px"
                                        borderColor="border.muted"
                                        justify="space-between"
                                    >
                                        <VStack align="start" gap={0}>
                                            <Text fontSize="sm" fontWeight="medium">{cert.name}</Text>
                                            <HStack fontSize="xs" color="fg.muted">
                                                <Text>{cert.type}</Text>
                                                {cert.external_id && <Text>• {cert.external_id}</Text>}
                                            </HStack>
                                        </VStack>
                                        <Badge size="sm" variant="subtle">
                                            {cert.issued_at ? new Date(cert.issued_at).getFullYear() : 'N/A'}
                                        </Badge>
                                    </HStack>
                                ))}
                                {suggestions.length === 0 && !loading && (
                                    <Box p={4} textAlign="center" color="fg.muted" fontSize="sm">
                                        Ничего не найдено
                                    </Box>
                                )}
                            </VStack>
                        )}
                    </Box>
                )}
                {error && <Text color="red.500" fontSize="sm" mt={1}>{error}</Text>}
            </Box>

            {/* Selected Certificates List */}
            {value.length > 0 && (
                <VStack align="stretch" gap={2}>
                    <Text fontSize="sm" fontWeight="medium" color="fg.muted">
                        Выбранные сертификаты:
                    </Text>
                    {value.map((cert) => (
                        <HStack
                            key={cert.id}
                            p={3}
                            borderWidth="1px"
                            borderColor="border.muted"
                            borderRadius="md"
                            bg="bg.subtle"
                            justify="space-between"
                        >
                            <Box>
                                <Text fontSize="sm" fontWeight="medium" lineClamp={1}>
                                    {cert.name}
                                </Text>
                                <HStack gap={2} mt={1}>
                                    <Badge size="xs" variant="outline">{cert.type}</Badge>
                                    {cert.status === 'expired' && (
                                        <Badge size="xs" colorPalette="red">Истек</Badge>
                                    )}
                                </HStack>
                            </Box>
                            <IconButton
                                aria-label="Удалить"
                                size="xs"
                                variant="ghost"
                                colorPalette="red"
                                onClick={() => handleRemove(cert.id)}
                            >
                                <LuX />
                            </IconButton>
                        </HStack>
                    ))}
                </VStack>
            )}
        </Box>
    );
};
