<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BankAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'bank_code',
        'agencia',
        'agencia_dv',
        'conta',
        'conta_dv',
        'cpf',
        'name',
        'recipient_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
