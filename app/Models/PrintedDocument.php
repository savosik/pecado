<?php

namespace App\Models;

use App\Casts\ErpDatetime;
use App\Enums\PrintedDocumentType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Печатная форма документа (PDF), сформированная 1С (v16.1.0).
 *
 * Сайт форм не рисует: 1С кладёт готовый PDF в обменный бакет S3 и присылает по шине
 * запись о документе, а сайт переносит файл в собственное приватное хранилище.
 *
 * `uuid` идентифицирует **печатную форму**, а не документ-основание: у одной реализации
 * бывают и УПД, и счёт-фактура. Он стабилен между перевыставлениями — по нему форма
 * обновляется. Истории версий нет: клиенту нужен актуальный документ, а не архив редакций.
 *
 * Бизнес-правила — docs-erp/content/rules/printed-documents.md.
 *
 * @property int $id
 * @property string $uuid
 * @property PrintedDocumentType $type
 * @property string|null $erp_type_code
 * @property string|null $erp_type_name
 * @property string|null $number
 * @property \Illuminate\Support\Carbon|null $date
 * @property string|null $title
 * @property int|null $user_id
 * @property int|null $company_id
 * @property int|null $organization_id
 * @property int|null $order_id
 * @property int|null $shipment_id
 * @property string|null $partner_uuid
 * @property string|null $contractor_uuid
 * @property string|null $organization_uuid
 * @property string|null $order_uuid
 * @property string|null $shipment_uuid
 * @property string|null $tax_id
 * @property string|null $base_document_kind
 * @property string|null $disk
 * @property string|null $path
 * @property string|null $source_url
 * @property string|null $original_filename
 * @property string|null $mime_type
 * @property int|null $size_bytes
 * @property string|null $checksum
 * @property string $file_status
 * @property \Illuminate\Support\Carbon|null $stored_at
 * @property int $version
 * @property int|null $revision
 * @property int|null $applied_revision
 * @property \Illuminate\Support\Carbon|null $erp_created_at
 * @property \Illuminate\Support\Carbon|null $erp_updated_at
 * @property-read \App\Models\User|null $user
 * @property-read \App\Models\Company|null $company
 * @property-read \App\Models\Organization|null $organization
 * @property-read \App\Models\Order|null $order
 * @property-read \App\Models\Shipment|null $shipment
 * @property-read string $type_label
 * @property-read string $display_title
 * @property-read string $download_name
 *
 * @method static \Database\Factories\PrintedDocumentFactory factory($count = null, $state = [])
 *
 * @mixin \Eloquent
 */
class PrintedDocument extends Model
{
    use HasFactory;
    use SoftDeletes;

    /** Запись создана, файл ещё не перенесён из обменного бакета. */
    public const FILE_PENDING = 'pending';

    /** Файл лежит в хранилище сайта. Только такие документы видит клиент. */
    public const FILE_STORED = 'stored';

    /** 1С не выложила файл в обменный бакет либо ключ указан неверно. */
    public const FILE_MISSING = 'missing';

    /** Не PDF или превышен лимит размера. */
    public const FILE_REJECTED = 'rejected';

    /**
     * Подписи состояний файла для CRM. Клиенту они не показываются: он видит
     * только сохранённые документы, для него остальных состояний не существует.
     *
     * @var array<string, string>
     */
    public const FILE_STATUS_LABELS = [
        self::FILE_PENDING => 'Ожидает переноса',
        self::FILE_STORED => 'Сохранён',
        self::FILE_MISSING => 'Файл отсутствует',
        self::FILE_REJECTED => 'Отклонён',
    ];

    protected $fillable = [
        'uuid',
        'type',
        'erp_type_code',
        'erp_type_name',
        'number',
        'date',
        'title',
        'user_id',
        'company_id',
        'organization_id',
        'order_id',
        'shipment_id',
        'partner_uuid',
        'contractor_uuid',
        'organization_uuid',
        'order_uuid',
        'shipment_uuid',
        'tax_id',
        'base_document_kind',
        'disk',
        'path',
        'source_url',
        'original_filename',
        'mime_type',
        'size_bytes',
        'checksum',
        'file_status',
        'stored_at',
        'version',
        'revision',
        'applied_revision',
        'erp_created_at',
        'erp_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => PrintedDocumentType::class,
            'date' => 'date',
            'stored_at' => 'datetime',
            'size_bytes' => 'integer',
            'version' => 'integer',
            'revision' => 'integer',
            'applied_revision' => 'integer',
            'erp_created_at' => ErpDatetime::class,
            'erp_updated_at' => ErpDatetime::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /**
     * Документы, доступные клиенту.
     *
     * Ось видимости — контрагенты пользователя, а не денормализованный `user_id`:
     * 1С перепривязывает Company к другому партнёру (HandleContractorUpdated),
     * и снимок `user_id` в документе после этого укажет на прежнего владельца.
     *
     * Вторая ветка — документы, у которых контрагента на сайте ещё нет. Без неё
     * клиент не увидел бы собственный счёт до того, как приедет `contractor.created`.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $query) use ($user) {
            $query
                ->whereIn('company_id', Company::withoutGlobalScopes()
                    ->where('user_id', $user->id)
                    ->select('id'))
                ->orWhere(function (Builder $query) use ($user) {
                    $query->whereNull('company_id')->where('user_id', $user->id);
                });
        });
    }

    /**
     * Только документы с перенесённым файлом.
     *
     * Клиенту нельзя показывать строку, которую невозможно скачать: ссылка
     * на «ожидает переноса» выглядит как поломка сайта, а не как задержка обмена.
     */
    public function scopeStored(Builder $query): Builder
    {
        return $query->where('file_status', self::FILE_STORED);
    }

    public function getTypeLabelAttribute(): string
    {
        if ($this->type === PrintedDocumentType::OTHER && filled($this->erp_type_name)) {
            return $this->erp_type_name;
        }

        return $this->type->label();
    }

    /**
     * Заголовок для списка. 1С может прислать готовый — тогда берём его,
     * иначе собираем из вида, номера и даты.
     */
    public function getDisplayTitleAttribute(): string
    {
        if (filled($this->title)) {
            return $this->title;
        }

        $parts = [$this->type_label];

        if (filled($this->number)) {
            $parts[] = '№ '.$this->number;
        }

        if ($this->date) {
            $parts[] = 'от '.$this->date->format('d.m.Y');
        }

        return implode(' ', $parts);
    }

    /**
     * Имя файла при скачивании.
     *
     * Собирается из вида и номера, а не берётся из `original_filename`: 1С называет
     * файлы по-своему, и в папке загрузок клиента они сливаются в неразличимую кучу.
     * Кириллица сохраняется — Content-Disposition её переносит, а транслит читается хуже.
     */
    public function getDownloadNameAttribute(): string
    {
        $parts = [$this->type_label];

        if (filled($this->number)) {
            $parts[] = $this->number;
        }

        if ($this->date) {
            $parts[] = $this->date->format('d.m.Y');
        }

        $name = trim(implode(' ', $parts));
        // Символы, недопустимые в именах файлов Windows и macOS: иначе браузер
        // молча обрежет имя или откажется сохранять.
        $name = preg_replace('#[/\\\\:*?"<>|]+#u', '-', $name) ?? '';
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

        if ($name === '') {
            $name = 'Документ '.Str::limit($this->uuid, 8, '');
        }

        return $name.'.pdf';
    }
}
