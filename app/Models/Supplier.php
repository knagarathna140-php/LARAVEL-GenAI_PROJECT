<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    protected $fillable = ['name', 'contact', 'payment_terms', 'bank_details'];

    protected $hidden = ['bank_details'];

    protected function casts(): array
    {
        return ['bank_details' => 'encrypted'];
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }
}
