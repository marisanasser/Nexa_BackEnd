<?php

declare(strict_types=1);

namespace App\Repositories\Campaign;

use App\Models\Campaign\Campaign;
use Illuminate\Database\Eloquent\Collection;

class CampaignRepository
{
    public function find(int $id): ?Campaign
    {
        return Campaign::find($id);
    }

    public function create(array $data): Campaign
    {
        return Campaign::create($data);
    }

    public function update(Campaign $campaign, array $data): bool
    {
        return $campaign->update($data);
    }

    public function delete(Campaign $campaign): bool
    {
        return $campaign->delete();
    }

    public function getAll(): Collection
    {
        return Campaign::all();
    }

    public function findByBrand(int $brandId): Collection
    {
        return Campaign::where('brand_id', $brandId)->get();
    }
}
