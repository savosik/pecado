<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $title
 * @property string|null $description
 * @property string $status
 * @property int $order
 * @property string|null $page_url
 * @property string|null $browser
 * @property string|null $user_name
 * @property string|null $scope
 * @property string|null $type
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\KanbanComment> $allComments
 * @property-read int|null $all_comments_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\KanbanTaskAttachment> $attachments
 * @property-read int|null $attachments_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\KanbanComment> $comments
 * @property-read int|null $comments_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KanbanTask newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KanbanTask newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KanbanTask query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KanbanTask whereBrowser($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KanbanTask whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KanbanTask whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KanbanTask whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KanbanTask whereOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KanbanTask wherePageUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KanbanTask whereScope($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KanbanTask whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KanbanTask whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KanbanTask whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KanbanTask whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KanbanTask whereUserName($value)
 *
 * @mixin \Eloquent
 */
class KanbanTask extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'description',
        'status',
        'order',
        'page_url',
        'browser',
        'user_name',
        'scope',
        'type',
    ];

    public function comments(): HasMany
    {
        return $this->hasMany(KanbanComment::class)->whereNull('parent_id');
    }

    public function allComments(): HasMany
    {
        return $this->hasMany(KanbanComment::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(KanbanTaskAttachment::class);
    }
}
