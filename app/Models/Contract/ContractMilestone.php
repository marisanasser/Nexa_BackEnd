<?php

namespace App\Models\Contract;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractMilestone extends Model
{
    use HasFactory;

    protected $fillable = [
        'contract_id',
        'title',
        'description',
        'status',
        'submission_data',
        'feedback',
        'due_date',
        'completed_at',
        'order',
    ];

    protected $casts = [
        'submission_data' => 'array',
        'due_date' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
}
