<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasFactory;

    public const METHOD_CASH = 'cash';

    public const METHOD_CARD = 'card';

    protected $fillable = [
        'table_session_id',
        'recorded_by',
        'order_item_id',
        'method',
        'amount',
        'person_label',
        'recorded_at',
        'voided_at',
        'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'recorded_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function tableSession(): BelongsTo
    {
        return $this->belongsTo(TableSession::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
