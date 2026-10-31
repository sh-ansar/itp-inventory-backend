<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class StockTransaction extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id', 'company_id', 'stock_item_id', 'operation',
        'source_warehouse_node_id', 'destination_warehouse_node_id', 'quantity_milli',
        'before_source_milli', 'after_source_milli',
        'before_destination_milli', 'after_destination_milli',
        'actor_id', 'actor_name', 'reason',
    ];

    protected function casts(): array
    {
        return [
            'quantity_milli' => 'integer', 'before_source_milli' => 'integer', 'after_source_milli' => 'integer',
            'before_destination_milli' => 'integer', 'after_destination_milli' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
