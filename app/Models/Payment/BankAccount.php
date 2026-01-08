<?php

declare(strict_types=1);

namespace App\Models\Payment;

use App\Models\User\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int         $id
 * @property int         $user_id
 * @property string      $bank_code
 * @property string      $agencia
 * @property null|string $agencia_dv
 * @property string      $conta
 * @property null|string $conta_dv
 * @property string      $cpf
 * @property string      $name
 * @property null|string $recipient_id
 * @property null|Carbon $created_at
 * @property null|Carbon $updated_at
 * @property User        $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder|BankAccount where($column, $operator = null, $value = null, $boolean = 'and')
 *
 * @mixin \Illuminate\Database\Eloquent\Builder
 */
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
