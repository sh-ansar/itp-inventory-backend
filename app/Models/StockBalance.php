<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class StockBalance extends Model
{
    protected $fillable = ['company_id', 'stock_item_id', 'warehouse_node_id', 'quantity_milli'];

    protected function casts(): array
    {
        return ['quantity_milli' => 'integer'];
    }
}
