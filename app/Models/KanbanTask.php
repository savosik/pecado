<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
