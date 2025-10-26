<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class NotificationHistory extends Model
{
    use HasFactory;
    // table name matches migration: notification_histories
    protected $table = 'notification_histories';
    protected $fillable = [
        'order_id',
        'customer_id',
        'channel',
        'payload',
        'status',
        'sent_at'
    ];
    protected $casts = [
        'payload' => 'array',
        'sent_at' => 'datetime',
    ];
}