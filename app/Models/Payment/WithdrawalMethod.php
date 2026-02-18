<?php

declare(strict_types=1);

namespace App\Models\Payment;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * WithdrawalMethod model for withdrawal method configuration.
 *
 * @property int          $id
 * @property string       $code
 * @property string       $name
 * @property null|string  $description
 * @property float|string $min_amount
 * @property float|string $max_amount
 * @property null|string  $processing_time
 * @property float|string $fee
 * @property bool         $is_active
 * @property null|array   $required_fields
 * @property null|array   $field_config
 * @property int          $sort_order
 * @property null|Carbon  $created_at
 * @property null|Carbon  $updated_at
 * @property string       $formatted_fee
 * @property string       $formatted_min_amount
 * @property string       $formatted_max_amount
 *
 * @method static \Illuminate\Database\Eloquent\Builder|WithdrawalMethod where($column, $operator = null, $value = null, $boolean = 'and')
 * @method static \Illuminate\Database\Eloquent\Builder|WithdrawalMethod orderBy($column, $direction = 'asc')
 *
 * @mixin \Illuminate\Database\Eloquent\Builder
 */
class WithdrawalMethod extends Model
{
    use HasFactory;

    public const METHOD_STRIPE_CONNECT = 'stripe_connect';
    public const METHOD_STRIPE_CONNECT_BANK_ACCOUNT = 'stripe_connect_bank_account';
    public const METHOD_STRIPE_CARD = 'stripe_card';

    protected $fillable = [
        'code',
        'name',
        'description',
        'min_amount',
        'max_amount',
        'processing_time',
        'fee',
        'is_active',
        'required_fields',
        'field_config',
        'sort_order',
    ];

    protected $casts = [
        'min_amount' => 'decimal:2',
        'max_amount' => 'decimal:2',
        'fee' => 'decimal:2',
        'is_active' => 'boolean',
        'required_fields' => 'array',
        'field_config' => 'array',
        'sort_order' => 'integer',
    ];

    public static function getActiveMethods()
    {
        return static::where('is_active', true)
            ->orderBy('sort_order')
            ->get()
        ;
    }

    public static function findByCode(string $code)
    {
        return static::where('code', $code)
            ->where('is_active', true)
            ->first()
        ;
    }

    /**
     * Creator withdrawal methods allowed in the product.
     *
     * @return array<int, string>
     */
    public static function allowedCreatorMethodCodes(): array
    {
        return [
            self::METHOD_STRIPE_CONNECT,
            self::METHOD_STRIPE_CONNECT_BANK_ACCOUNT,
            self::METHOD_STRIPE_CARD,
        ];
    }

    public static function isAllowedCreatorMethodCode(string $code): bool
    {
        return in_array($code, self::allowedCreatorMethodCodes(), true);
    }

    public static function isBankDetailsMethodCode(string $code): bool
    {
        // Legacy helper kept for compatibility: no non-Stripe bank methods are allowed anymore.
        return false;
    }

    public function formattedFee(): string
    {
        return 'R$ '.number_format((float) $this->fee, 2, ',', '.');
    }

    public function getFormattedFeeAttribute(): string
    {
        return $this->formattedFee();
    }

    public function formattedMinAmount(): string
    {
        return 'R$ '.number_format((float) $this->min_amount, 2, ',', '.');
    }

    public function getFormattedMinAmountAttribute(): string
    {
        return $this->formattedMinAmount();
    }

    public function formattedMaxAmount(): string
    {
        return 'R$ '.number_format((float) $this->max_amount, 2, ',', '.');
    }

    public function getFormattedMaxAmountAttribute(): string
    {
        return $this->formattedMaxAmount();
    }

    public function isAmountValid(float $amount): bool
    {
        return $amount >= $this->min_amount && $amount <= $this->max_amount;
    }

    public function getRequiredFields(): array
    {
        return $this->required_fields ?? [];
    }

    public function getFieldConfig(): array
    {
        return $this->field_config ?? [];
    }

    public function validateWithdrawalDetails(array $details): array
    {
        $errors = [];
        $requiredFields = $this->getRequiredFields();

        foreach ($requiredFields as $field) {
            if (!isset($details[$field]) || empty($details[$field])) {
                $errors[] = "O campo '{$field}' é obrigatório para {$this->name}";
            }
        }

        return $errors;
    }
}
