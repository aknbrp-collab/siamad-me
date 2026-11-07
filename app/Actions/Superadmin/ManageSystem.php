<?php

namespace App\Actions\Superadmin;

use App\Enums\PrintDocument;
use App\Models\Setting;
use App\Models\Signatory;
use App\Models\Stamp;
use App\Support\Branding;
use App\Support\Upload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

/**
 * Superadmin's system pages (Pengaturan, Identitas lembaga, Tampilan
 * aplikasi, Tanda tangan & stempel), shared by the pages and the API (v6
 * D37). Each field has an API name; the setting key it is stored under is
 * listed with its rules.
 */
class ManageSystem
{
    /**
     * Pengaturan: field => [setting key, rules].
     *
     * @var array<string, array{0: string, 1: list<string>}>
     */
    public const SETTINGS = [
        'max_installments' => ['payment.max_installments', ['required', 'integer', 'min:1', 'max:12']],
        'min_installment_amount' => ['payment.min_installment_amount', ['required', 'integer', 'min:0']],
        'bank_accounts' => ['payment.bank_accounts', ['nullable', 'string', 'max:1000']],
        'receipt_footer' => ['receipt.footer', ['nullable', 'string', 'max:500']],
        'attendance_min_percent' => ['attendance.min_percent', ['required', 'integer', 'between:0,100']],
    ];

    /**
     * Identitas lembaga: field => [setting key, rules].
     *
     * @var array<string, array{0: string, 1: list<string>}>
     */
    public const IDENTITY = [
        'name' => ['institution.name', ['required', 'string', 'max:255']],
        'short_name' => ['institution.short_name', ['nullable', 'string', 'max:30']],
        'foundation' => ['institution.foundation', ['nullable', 'string', 'max:255']],
        'address' => ['institution.address', ['nullable', 'string', 'max:500']],
        'city' => ['institution.city', ['nullable', 'string', 'max:100']],
        'phone' => ['institution.phone', ['nullable', 'string', 'max:50']],
        'email' => ['institution.email', ['nullable', 'email', 'max:255']],
        'website' => ['institution.website', ['nullable', 'string', 'max:255']],
        'licence' => ['institution.licence', ['nullable', 'string', 'max:255']],
        'head' => ['institution.head', ['nullable', 'string', 'max:150']],
    ];

    /**
     * Tampilan aplikasi (v6 D36): field => [setting key, rules].
     *
     * @var array<string, array{0: string, 1: list<string>}>
     */
    public const APPEARANCE = [
        'app_name' => ['app.name', ['nullable', 'string', 'max:100']],
        'short_name' => ['app.short_name', ['nullable', 'string', 'max:20']],
        'login_title' => ['app.login_title', ['nullable', 'string', 'max:100']],
        'login_text' => ['app.login_text', ['nullable', 'string', 'max:300']],
    ];

    /** Brand images of Identitas lembaga and of Tampilan aplikasi, with the size they are scaled to. */
    public const IDENTITY_IMAGES = ['institution_logo' => 1000, 'arabic_name' => 1000];

    public const APPEARANCE_IMAGES = ['app_logo' => 1000, 'app_icon' => 1000, 'login_background' => 2000];

    /**
     * @param  array<string, array{0: string, 1: list<string>}>  $fields  one of the field maps above
     * @return array<string, mixed> field => current value
     */
    public function read(array $fields): array
    {
        return array_map(fn (array $field) => Setting::get($field[0]), $fields);
    }

    /**
     * Validate and store a page's fields; empty text is stored as null.
     *
     * @param  array<string, array{0: string, 1: list<string>}>  $fields
     * @param  array<string, mixed>  $input  field => value
     */
    public function save(array $fields, array $input): void
    {
        $values = [];

        foreach (array_keys($fields) as $field) {
            $value = $input[$field] ?? null;
            $values[$field] = is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value;
        }

        Validator::make($values, array_map(fn (array $field) => $field[1], $fields))->validate();

        foreach ($fields as $field => [$key]) {
            if (Setting::get($key) !== $values[$field]) {
                Setting::set($key, $values[$field]);
            }
        }
    }

    /**
     * Store an uploaded brand image (scaled PNG) under its setting.
     */
    public function storeImage(string $image, UploadedFile $file): string
    {
        $sizes = [...self::IDENTITY_IMAGES, ...self::APPEARANCE_IMAGES];
        abort_unless(array_key_exists($image, $sizes), 404);

        Validator::make(['file' => $file], ['file' => ['image', 'mimes:png,jpg,jpeg,webp', Upload::max()]])->validate();
        $path = Branding::storeImage($file, 'branding', $sizes[$image]);
        Setting::set(Branding::IMAGES[$image], $path);

        return $path;
    }

    /**
     * Stop using a brand image. The file stays on disk (old PDFs may be explained by it).
     */
    public function removeImage(string $image): void
    {
        abort_unless(array_key_exists($image, Branding::IMAGES), 404);
        Setting::set(Branding::IMAGES[$image], null);
    }

    /**
     * @param  array<string, mixed>  $input  name, position, office, is_active, signature (image)
     */
    public function saveSignatory(?Signatory $signatory, array $input): Signatory
    {
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:150'],
            'position' => ['required', 'string', 'max:150'],
            'office' => ['nullable', 'string', 'max:150'],
            'is_active' => ['boolean'],
            'signature' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', Upload::max()],
        ])->validate();

        $attributes = [
            'name' => trim((string) $data['name']),
            'position' => trim((string) $data['position']),
            'office' => trim((string) ($data['office'] ?? '')) ?: null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];

        if (($data['signature'] ?? null) instanceof UploadedFile) {
            $attributes['signature_path'] = Branding::storeImage($data['signature'], 'branding/signatures', 600);
        }

        return Signatory::query()->updateOrCreate(['id' => $signatory?->id], $attributes);
    }

    /**
     * @param  array<string, mixed>  $input  name, is_active, image (required for a new stamp)
     */
    public function saveStamp(?Stamp $stamp, array $input): Stamp
    {
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:150'],
            'is_active' => ['boolean'],
            'image' => [$stamp !== null ? 'nullable' : 'required', 'image', 'mimes:png,jpg,jpeg,webp', Upload::max()],
        ])->validate();

        $attributes = ['name' => trim((string) $data['name']), 'is_active' => (bool) ($data['is_active'] ?? true)];

        if (($data['image'] ?? null) instanceof UploadedFile) {
            $attributes['image_path'] = Branding::storeImage($data['image'], 'branding/stamps', 600);
        }

        return Stamp::query()->updateOrCreate(['id' => $stamp?->id], $attributes);
    }

    /**
     * The default signatory, stamp and whether to print the images, per document.
     *
     * @return array<string, array{signatory: mixed, stamp: mixed, images: bool}>
     */
    public function printDefaults(): array
    {
        $defaults = [];

        foreach (PrintDocument::withDefaults() as $document) {
            $key = 'print.'.$document->value;
            $defaults[$document->value] = [
                'signatory' => Setting::get($key.'.signatory'),
                'stamp' => Setting::get($key.'.stamp'),
                'images' => (bool) Setting::get($key.'.images', true),
            ];
        }

        return $defaults;
    }

    /**
     * @param  array<int|string, mixed>  $defaults  document => {signatory, stamp, images}; unknown ids are cleared
     */
    public function savePrintDefaults(array $defaults): void
    {
        $signatories = Signatory::query()->pluck('id')->all();
        $stamps = Stamp::query()->pluck('id')->all();

        foreach (PrintDocument::withDefaults() as $document) {
            $choice = is_array($defaults[$document->value] ?? null) ? $defaults[$document->value] : [];
            $key = 'print.'.$document->value;
            $signatory = in_array((int) ($choice['signatory'] ?? 0), $signatories, true) ? (int) $choice['signatory'] : null;
            $stamp = in_array((int) ($choice['stamp'] ?? 0), $stamps, true) ? (int) $choice['stamp'] : null;

            foreach (['signatory' => $signatory, 'stamp' => $stamp, 'images' => (bool) ($choice['images'] ?? true)] as $part => $value) {
                if (Setting::get($key.'.'.$part, $part === 'images' ? true : null) !== $value) {
                    Setting::set($key.'.'.$part, $value);
                }
            }
        }
    }
}
