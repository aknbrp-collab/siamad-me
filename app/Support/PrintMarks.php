<?php

namespace App\Support;

use App\Enums\PrintDocument;
use App\Models\Setting;
use App\Models\Signatory;
use App\Models\Stamp;
use Illuminate\Http\Request;

/**
 * Who signs a printed document and whether the signature and stamp images
 * are printed. Each document has a default (settings "print.{document}.*");
 * staff may choose another signatory and stamp, or print without images for
 * a wet signature. Mahasantri and wali santri always get the default.
 */
final class PrintMarks
{
    public function __construct(
        public readonly ?Signatory $signatory,
        public readonly ?Stamp $stamp,
        public readonly bool $images,
    ) {}

    public static function defaults(PrintDocument $document): self
    {
        $key = 'print.'.$document->value;

        return new self(
            self::signatory(Setting::get($key.'.signatory')),
            self::stamp(Setting::get($key.'.stamp')),
            (bool) Setting::get($key.'.images', true),
        );
    }

    /**
     * The default, with the choices staff made in the print dialog
     * (?marks=0|1&signatory=ID&stamp=ID, where 0 means none).
     */
    public static function for(PrintDocument $document, Request $request): self
    {
        $marks = self::defaults($document);

        if (! (ActiveRole::get()?->isStaff() ?? false) || ! $request->hasAny(['marks', 'signatory', 'stamp'])) {
            return $marks;
        }

        return new self(
            $request->has('signatory') ? self::signatory($request->integer('signatory')) : $marks->signatory,
            $request->has('stamp') ? self::stamp($request->integer('stamp')) : $marks->stamp,
            $request->has('marks') ? $request->boolean('marks') : $marks->images,
        );
    }

    /**
     * The signature image to print, as a file path for dompdf.
     */
    public function signatureFile(): ?string
    {
        return $this->images ? Branding::file($this->signatory?->signature_path) : null;
    }

    public function stampFile(): ?string
    {
        return $this->images ? Branding::file($this->stamp?->image_path) : null;
    }

    private static function signatory(mixed $id): ?Signatory
    {
        return is_numeric($id) && (int) $id > 0 ? Signatory::query()->where('is_active', true)->find((int) $id) : null;
    }

    private static function stamp(mixed $id): ?Stamp
    {
        return is_numeric($id) && (int) $id > 0 ? Stamp::query()->where('is_active', true)->find((int) $id) : null;
    }
}
