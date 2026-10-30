<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class AssetLocation extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['id', 'kind', 'code', 'name'];
}