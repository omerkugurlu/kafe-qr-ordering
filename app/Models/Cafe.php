<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Cafe extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const POLICY_GUEST = 'guest';

    public const POLICY_OPTIONAL = 'optional';

    public const POLICY_REQUIRED = 'required';

    protected $fillable = [
        'name',
        'slug',
        'status',
        'registration_policy',
        'loyalty_enabled',
        'points_per_100',
        'brand_name',
        'logo_path',
        'cover_path',
        'description',
        'phone',
        'address',
        'instagram',
    ];

    protected $appends = [
        'logo_url',
        'cover_url',
    ];

    protected function casts(): array
    {
        return [
            'loyalty_enabled' => 'boolean',
            'points_per_100' => 'integer',
        ];
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(MenuCategory::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(MenuProduct::class);
    }

    public function loyaltyCampaigns(): HasMany
    {
        return $this->hasMany(LoyaltyCampaign::class);
    }

    public function loyaltyAccounts(): HasMany
    {
        return $this->hasMany(LoyaltyAccount::class);
    }

    public function coupons(): HasMany
    {
        return $this->hasMany(Coupon::class);
    }

    public function announcements(): HasMany
    {
        return $this->hasMany(Announcement::class);
    }

    public function getLogoUrlAttribute(): ?string
    {
        return $this->publicAssetUrl($this->logo_path);
    }

    public function getCoverUrlAttribute(): ?string
    {
        return $this->publicAssetUrl($this->cover_path);
    }

    private function publicAssetUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        return Str::startsWith($path, ['http://', 'https://'])
            ? $path
            : Storage::disk('public')->url($path);
    }
}
