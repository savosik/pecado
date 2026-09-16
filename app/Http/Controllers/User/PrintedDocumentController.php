<?php

namespace App\Http\Controllers\User;

use App\Enums\PrintedDocumentType;
use App\Http\Controllers\Controller;
use App\Models\PrintedDocument;
use App\Models\User;
use App\Services\Documents\ClientDocumentQuery;
use App\Services\SimpleCsvExporter;
use App\Services\SimpleXlsxExporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Раздел «Документы» в личном кабинете (v16.1.0).
 *
 * Печатные формы, сформированные 1С: счета, счета-фактуры, УПД, акты сверки,
 * договоры. Сайт их не рисует и не редактирует — только показывает и отдаёт файл.
 *
 * Клиент видит документы всех своих контрагентов, независимо от того, есть ли
 * на сайте соответствующий заказ или реализация: договор и акт сверки основания
 * не имеют вовсе, а нужны не реже счёта.
 */
class PrintedDocumentController extends Controller
{
    public function __construct(private readonly ClientDocumentQuery $documents) {}

    public function index(Request $request): InertiaResponse
    {
        $user = $request->user();
        [$query, $context] = $this->buildIndexQuery($request, $user);

        $documents = $query->paginate($context['per_page'])->withQueryString();
        $organizationsEnabled = (bool) config('erp.organizations.enabled');
        $typeCounts = $this->typeCounts($request, $user);

        $documents->getCollection()->transform(fn (PrintedDocument $document): array => [
            'id' => $document->id,
            'title' => $document->display_title,
            'type' => $document->type->value,
            'type_label' => $document->type_label,
            'type_color' => $document->type->color(),
            'number' => $document->number,
            'date' => $document->date?->format('d.m.Y'),
            // Период показывается только у форм за период (акт сверки): у остальных
            // он пуст, и лишняя строка в карточке сбивала бы с толку.
            'period' => $document->period_label,
            'format' => $document->format->value,
            'format_label' => $document->format->label(),
            'company' => $document->company?->name,
            'organization' => $organizationsEnabled && $document->organization && ! $document->organization->is_stub
                ? $document->organization->name
                : null,
            'base' => $this->documents->base($document),
            // Размер округлённый: клиенту важно понять, «откроется ли это
            // на телефоне», а не точное число байтов.
            'size' => $document->size_label,
            'download_url' => route('cabinet.documents.download', $document->id),
        ]);

        return Inertia::render('User/Cabinet/Documents/Index', [
            'documents' => $documents,
            'filters' => $context['filters'],
            'types' => PrintedDocumentType::options(),
            'typeCounts' => $typeCounts,
            'typeTotal' => array_sum($typeCounts),
            'companies' => $user->companies()->orderBy('name')->pluck('name', 'id')
                ->map(fn (?string $name, int $id): array => ['value' => (string) $id, 'label' => (string) $name])
                ->values()
                ->all(),
            'organizations' => $organizationsEnabled ? $this->documents->organizationOptions($user) : [],
            'organizationsEnabled' => $organizationsEnabled,
            'presetsEnabled' => (bool) config('search-cabinet.presets'),
            'exportEnabled' => (bool) config('search-cabinet.export'),
            'subscriptionsEnabled' => (bool) config('documents.subscriptions_enabled'),
        ]);
    }

    public function export(Request $request, SimpleCsvExporter $csv, SimpleXlsxExporter $xlsx): StreamedResponse
    {
        abort_unless((bool) config('search-cabinet.export'), 404);

        $format = strtolower((string) $request->input('format', ''));
        abort_unless(in_array($format, ['csv', 'xlsx'], true), 422, 'Допустимые форматы: csv, xlsx.');

        [$query] = $this->buildIndexQuery($request, $request->user());
        $withSeller = (bool) config('erp.organizations.enabled');

        $headers = array_merge(
            ['Вид документа', 'Номер', 'Дата', 'Контрагент'],
            $withSeller ? ['Продавец'] : [],
            ['Основание', 'Размер, МБ'],
        );

        $rows = (function () use ($query, $withSeller): \Generator {
            foreach ($query->cursor() as $document) {
                yield array_merge(
                    [
                        $document->type_label,
                        $document->number ?? '',
                        $document->date?->format('d.m.Y') ?? '',
                        $document->company ? $document->company->name : '',
                    ],
                    // Пустое значение выводим словами: пустая ячейка в Excel
                    // читается как потеря данных, а не как «организации нет».
                    $withSeller ? [$document->organization ? $document->organization->name : 'Не указана'] : [],
                    [
                        $this->documents->base($document)['label'] ?? '',
                        $document->size_bytes === null ? '' : round($document->size_bytes / 1024 / 1024, 2),
                    ],
                );
            }
        })();

        $filename = 'documents-'.now()->format('Y-m-d-His');

        return $format === 'csv'
            ? $csv->stream($filename, $headers, $rows)
            : $xlsx->stream($filename, $headers, $rows, 'Документы');
    }

    /**
     * Скачивание печатной формы.
     *
     * Файл идёт через приложение, а не прямой ссылкой на диск: ссылка на
     * счёт-фактуру означала бы, что документ клиента доступен любому, кто её
     * получил — см. ту же причину в Crm\AttachmentController::download.
     *
     * Storage::url() здесь не годится вдвойне: он вернёт строку (S3-драйвер
     * собирает её из endpoint и bucket), но бакет приватный и отдаст по ней 403,
     * так что клиент получил бы «битую» кнопку скачивания.
     */
    public function download(Request $request, PrintedDocument $document): StreamedResponse
    {
        $user = $request->user();

        // 404, а не 403: 403 подтвердил бы, что чужой документ существует.
        abort_unless(
            PrintedDocument::query()->visibleTo($user)->whereKey($document->id)->exists(),
            404,
        );

        abort_unless($document->file_status === PrintedDocument::FILE_STORED, 404, 'Файл ещё не готов');

        $disk = Storage::disk($document->disk);

        abort_unless($document->path && $disk->exists($document->path), 404, 'Файл не найден');

        return $disk->download($document->path, $document->download_name);
    }

    /**
     * Единый конструктор отбора для списка, выгрузки и счётчиков — общим сервисом
     * с клиентским API v1.
     *
     * @return array{0: Builder<PrintedDocument>, 1: array{per_page: int, filters: array<string, mixed>}}
     */
    private function buildIndexQuery(Request $request, User $user, bool $applyTypeFilter = true): array
    {
        $filters = $this->filters($request);
        $query = $this->documents->builder($user, $filters, $applyTypeFilter);

        $sortBy = in_array($request->input('sort_by'), ClientDocumentQuery::SORT_FIELDS, true)
            ? (string) $request->input('sort_by')
            : 'date';
        $sortOrder = $request->input('sort_order') === 'asc' ? 'asc' : 'desc';
        $this->documents->applySort($query, $sortBy, $sortOrder);

        $perPage = min(max((int) $request->input('per_page', 15), 5), 100);

        return [$query, [
            'per_page' => $perPage,
            'filters' => $filters + [
                'sort_by' => $sortBy,
                'sort_order' => $sortOrder,
                'per_page' => $perPage,
            ],
        ]];
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return [
            'search' => trim((string) $request->input('search', '')),
            'type' => $this->arrayInput($request, 'type'),
            'company_id' => $this->arrayInput($request, 'company_id'),
            'organization_id' => $this->arrayInput($request, 'organization_id'),
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
            'order_id' => $request->input('order_id'),
            'shipment_id' => $request->input('shipment_id'),
        ];
    }

    /**
     * Счётчики по видам документов для чипов быстрых фильтров.
     *
     * @return array<string, int>
     */
    private function typeCounts(Request $request, User $user): array
    {
        return $this->documents->typeCounts($user, $this->filters($request));
    }

    /**
     * Значение фильтра приходит и скаляром, и массивом: чип шлёт один вид,
     * мультивыбор — несколько.
     *
     * @return list<string>
     */
    private function arrayInput(Request $request, string $key): array
    {
        $input = $request->input($key);

        if ($input === null || $input === '') {
            return [];
        }

        $values = array_map(
            static fn (mixed $value): string => trim((string) $value),
            is_array($input) ? $input : [$input],
        );

        return array_values(array_unique(array_filter($values, static fn (string $value): bool => $value !== '')));
    }
}
