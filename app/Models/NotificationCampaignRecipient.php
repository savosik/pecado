<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Адресат кампании: кому ушло письмо и что с ним стало.
 */
class NotificationCampaignRecipient extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'notification_campaign_id',
        'client_user_id',
        'contact_id',
        'email',
        'status',
        'skip_reason',
        'notification_delivery_id',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(NotificationCampaign::class, 'notification_campaign_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(ClientContact::class, 'contact_id');
    }
}
