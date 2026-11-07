<?php

namespace App\Http\Resources\V1;

use App\Models\Material;
use App\Support\ActiveRole;
use App\Support\ApiFiles;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Material
 */
class MaterialResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Material $material */
        $material = $this->resource;

        return [
            'id' => $material->id,
            'title' => $material->title,
            'body' => $material->body,
            /** An outside link (video, article). */
            'url' => $material->url,
            'file_name' => $material->original_name,
            /** Signed link to the file, valid for a few minutes. */
            'file_url' => $material->file_path === null ? null : ApiFiles::url($request->user(), ActiveRole::get(), 'material', $material->id),
            'created_at' => $material->created_at->toIso8601String(),
        ];
    }
}
