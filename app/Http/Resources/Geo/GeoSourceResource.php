<?php

namespace App\Http\Resources\Geo;

use App\Models\DataSource;
use App\Support\Atlas\PublicSourcePresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DataSource */
class GeoSourceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return (new PublicSourcePresenter)->present($this->resource);
    }
}
