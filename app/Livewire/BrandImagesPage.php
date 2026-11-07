<?php

namespace App\Livewire;

use App\Models\Setting;
use App\Support\Branding;
use App\Support\Upload;
use Flux\Flux;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * A Superadmin page that uploads, replaces and removes brand images
 * (Identity: institution images; Tampilan aplikasi: app images). The page
 * lists its images in images() and renders them with <x-brand-images>.
 */
abstract class BrandImagesPage extends Component
{
    use WithFileUploads;

    /** @var array<string, TemporaryUploadedFile|null> */
    public array $uploads = [];

    /**
     * @return array<string, array{label: string, hint: string, max?: int}>
     */
    abstract public function images(): array;

    public function updatedUploads(mixed $file, string $image): void
    {
        $images = $this->images();
        abort_unless(array_key_exists($image, $images) && array_key_exists($image, Branding::IMAGES), 404);

        $this->validate(["uploads.{$image}" => ['image', 'mimes:png,jpg,jpeg,webp', Upload::max()]]);

        $upload = $this->uploads[$image] ?? null;
        abort_if($upload === null, 422);

        try {
            $path = Branding::storeImage($upload, 'branding', $images[$image]['max'] ?? 1000);
        } catch (ValidationException $exception) {
            $this->addError("uploads.{$image}", $exception->getMessage());

            return;
        }

        Setting::set(Branding::IMAGES[$image], $path);
        $this->uploads[$image] = null;
        Flux::toast(variant: 'success', text: __('Image saved.'));
    }

    public function removeImage(string $image): void
    {
        abort_unless(array_key_exists($image, $this->images()) && array_key_exists($image, Branding::IMAGES), 404);

        // The file stays on disk (old PDFs may be explained by it); the setting is cleared.
        Setting::set(Branding::IMAGES[$image], null);
    }
}
