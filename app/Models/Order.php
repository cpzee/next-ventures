<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;
    protected $fillable = [
        'external_id',
        'customer_id',
        'total_cents',
        'currency',
        'status',
        'metadata',
        'refunded_cents'
    ];
    protected $casts = [
        'metadata' => 'array',
    ];
    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }
}
