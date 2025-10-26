<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class DailyKpi extends Model
{
    use HasFactory;
    protected $fillable = [
        'date',
        'revenue_cents',
        'orders_count',
        'avg_order_value'
    ];
    protected $casts = [
        'date' => 'date',
    ];
}