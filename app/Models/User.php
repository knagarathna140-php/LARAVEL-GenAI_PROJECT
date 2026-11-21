<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;

    public const ROLE_SALES = 'sales';
    public const ROLE_PURCHASING = 'purchasing';

    protected $fillable = ['name', 'email', 'password', 'role'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['password' => 'hashed'];
    }

    /** Only Purchasing may see supplier bank details and create purchase orders. */
    public function isPurchasing(): bool
    {
        return $this->role === self::ROLE_PURCHASING;
    }
}
