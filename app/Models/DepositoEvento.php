<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DepositoEvento extends Model
{
    protected $table = 'deposito_eventos';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['datos' => 'array', 'created_at' => 'datetime'];
    }
}
