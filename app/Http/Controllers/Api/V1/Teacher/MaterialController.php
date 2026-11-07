<?php

namespace App\Http\Controllers\Api\V1\Teacher;

use App\Actions\Lms\ManageMaterials;
use App\Http\Controllers\Api\V1\Teacher\Concerns\TeachesOffering;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\MaterialResource;
use App\Models\CourseOffering;
use App\Models\Material;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

#[Group('Muhadhir', weight: 11)]
class MaterialController extends Controller
{
    use TeachesOffering;

    /**
     * Materials of a class.
     */
    public function index(Request $request, CourseOffering $offering): AnonymousResourceCollection
    {
        $this->authorizeOffering($request, $offering);

        return MaterialResource::collection($offering->materials()->get());
    }

    /**
     * Share a material.
     *
     * A title and at least one of: text (`body`), a link (`url`) or a file (multipart `file`). The mahasantri
     * are notified.
     */
    public function store(Request $request, CourseOffering $offering, ManageMaterials $materials): MaterialResource
    {
        $this->authorizeOffering($request, $offering);
        $request->validate($this->rules());

        return new MaterialResource($materials->save($offering, null, $this->input($request)));
    }

    /**
     * Change a material.
     *
     * Sent as multipart POST (so a new file can be attached); a material without a new file keeps its file.
     */
    public function update(Request $request, CourseOffering $offering, Material $material, ManageMaterials $materials): MaterialResource
    {
        $this->authorizeMaterial($request, $offering, $material);
        $request->validate($this->rules());

        return new MaterialResource($materials->save($offering, $material, $this->input($request)));
    }

    /**
     * Remove a material.
     */
    public function destroy(Request $request, CourseOffering $offering, Material $material, ManageMaterials $materials): Response
    {
        $this->authorizeMaterial($request, $offering, $material);
        $materials->delete($material);

        return response()->noContent();
    }

    private function authorizeMaterial(Request $request, CourseOffering $offering, Material $material): void
    {
        $this->authorizeOffering($request, $offering);
        abort_unless($material->course_offering_id === $offering->id, 404);
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'url' => ['nullable', 'string', 'max:255'],
            'file' => ManageMaterials::fileRules(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function input(Request $request): array
    {
        return [
            'title' => $request->input('title'),
            'body' => $request->input('body') ?: null,
            'url' => $request->input('url') ?: null,
            'file' => $request->file('file'),
        ];
    }
}
