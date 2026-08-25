import { Head, router } from '@inertiajs/react';
import { Badge, Box, Flex, Grid, HStack, Text, VStack } from '@chakra-ui/react';
import { LuDownload, LuEye, LuX } from 'react-icons/lu';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { DataTable } from '@/Admin/Components/DataTable';
import { SearchInput } from '@/Admin/Components/SearchInput';
import { ProductSelector } from '@/Admin/Components/ProductSelector';
import { Button } from '@/components/ui/button';
import MultiSelectFilter from '@/Crm/Components/MultiSelectFilter';
import AmountFilterInput from '@/Crm/Components/AmountFilterInput';
import ScopeToggle from '@/Crm/Components/ScopeToggle';
import FilterChips from '@/Crm/Components/FilterChips';
import MetricHint from '@/Crm/Components/MetricHint';
import PeriodFilter from '@/Crm/Components/PeriodFilter';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';
import { useDocumentFilters } from '@/Crm/hooks/useDocumentFilters';

/** «1 документ», «2 документа», «5 документов» — иначе итог читается как опечатка. */
const documentsLabel = (count) => {
    const tail = count % 10;
    const teen = count % 100 >= 11 && count % 100 <= 14;

    if (!teen && tail === 1) return `${count} документ`;
    if (!teen && tail >= 2 && tail <= 4) return `${count} документа`;

    return `${count} документов`;
};

/** ISO-дата из поля <input type="date"> — в человеческий вид для чипа. */
const humanDate = (value) => (value ? value.split('-').reverse().join('.') : '');

