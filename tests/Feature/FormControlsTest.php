<?php

use App\Actions\Documents\UploadDocument;
use App\Support\Upload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Validator;

test('an empty select shows a choosable placeholder, never a real option', function () {
    $html = Blade::render(<<<'BLADE'
        <flux:select wire:model="typeId" placeholder="Choose…">
            <flux:select.option value="1">Surat aktif</flux:select.option>
        </flux:select>
        BLADE);

    // Not disabled: when the value is empty the browser falls back to it, not to "Surat aktif".
    expect($html)->toMatch('/<option value="" selected class="placeholder">Choose…<\/option>/');
});

test('a select without placeholder gets a hidden empty option unless it has its own', function () {
    $plain = Blade::render('<flux:select wire:model="semesterId"><flux:select.option value="5">2026/2027 Ganjil</flux:select.option></flux:select>');
    $ownEmpty = Blade::render('<flux:select wire:model="status"><flux:select.option value="">All</flux:select.option><flux:select.option value="active">Active</flux:select.option></flux:select>');

    expect($plain)->toContain('<option value="" hidden></option>')
        ->and($ownEmpty)->not->toContain('hidden></option>');
});

test('every upload uses the one configured size limit', function () {
    config(['siakad.uploads.max_kb' => 10240]);
    $check = fn (int $kb) => Validator::make(['file' => UploadedFile::fake()->create('ijazah.pdf', $kb, 'application/pdf')], ['file' => UploadDocument::rules()])->passes();

    expect($check(9000))->toBeTrue()
        ->and($check(10241))->toBeFalse()
        ->and(Upload::maxMb())->toBe(10)
        ->and(config('livewire.temporary_file_upload.rules'))->toContain('max:10240');
});

test('pages tell the browser the upload limit', function () {
    $this->get(route('login'))->assertSee('name="upload-max-kb" content="10240"', false);
});
