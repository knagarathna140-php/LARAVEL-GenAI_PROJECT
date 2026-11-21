<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    public const OPEN_STATUSES = ['ordered', 'in_transit'];

    protected $fillable = ['po_number', 'supplier_id', 'status', 'order_date', 'eta', 'freight_cost'];

    protected function casts(): array
    {
        return ['order_date' => 'date', 'eta' => 'date', 'freight_cost' => 'decimal:2'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    /** Delayed = still open and past its ETA. */
    public function isDelayed(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true)
            && $this->eta !== null
            && $this->eta->isBefore(today());
    }
}
