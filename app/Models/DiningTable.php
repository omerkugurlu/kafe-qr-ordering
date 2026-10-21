<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class DiningTable extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'branch_id',
        'name',
        'public_token_hash',
        'public_token_encrypted',
        'is_active',
    ];

    protected $hidden = [
        'public_token_hash',
        'public_token_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'public_token_encrypted' => 'encrypted',
            'is_active' => 'boolean',
        ];
    }

    public static function createWithToken(array $attributes): self
    {
        $token = Str::random(64);

        return self::query()->create([
            ...$attributes,
            'public_token_hash' => hash('sha256', $token),
            'public_token_encrypted' => $token,
        ]);
    }

    public function rotateToken(): string
    {
        $token = Str::random(64);
        $this->forceFill([
            'public_token_hash' => hash('sha256', $token),
            'public_token_encrypted' => $token,
        ])->save();

        return $token;
    }

    public function token(): string
    {
        return (string) $this->public_token_encrypted;
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(TableSession::class);
    }

    public function openSession(): ?TableSession
    {
        return $this->sessions()->where('status', TableSession::STATUS_OPEN)->latest('id')->first();
    }
}
