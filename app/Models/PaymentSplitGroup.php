<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentSplitGroup extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'table_session_id',
        'people_count',
        'initial_amount',
        'created_by',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'people_count' => 'integer',
            'initial_amount' => 'decimal:2',
            'closed_at' => 'datetime',
        ];
    }

    public function tableSession(): BelongsTo
    {
        return $this->belongsTo(TableSession::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'split_group_id');
    }
}
