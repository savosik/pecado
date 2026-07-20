/**
 * Хук переехал в @/shared/Panel/usePermission — он общий для всех панелей
 * (/admin, /crm, /wms). Здесь оставлен реэкспорт: на этот путь ссылаются
 * десятки страниц админки.
 */
export { usePermission } from '@/shared/Panel/usePermission';
