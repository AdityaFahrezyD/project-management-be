<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use ZipArchive;

class AttachmentFile implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('Berkas tidak valid.');

            return;
        }
        if (mb_strlen($value->getClientOriginalName()) > 255 || preg_match('/[\\x00-\\x1F\\x7F]/', $value->getClientOriginalName())) {
            $fail('Nama berkas tidak valid atau terlalu panjang.');

            return;
        }
        $extension = strtolower($value->getClientOriginalExtension());
        $mime = $value->getMimeType();
        $images = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'avif' => 'image/avif'];
        if (isset($images[$extension])) {
            if ($mime !== $images[$extension] || @getimagesize($value->getRealPath()) === false) {
                $fail('Isi gambar tidak sesuai format berkas.');
            }

            return;
        }
        if ($extension === 'pdf') {
            if ($mime !== 'application/pdf' || file_get_contents($value->getRealPath(), false, null, 0, 5) !== '%PDF-') {
                $fail('Isi PDF tidak valid.');
            }

            return;
        }
        $entries = ['docx' => 'word/document.xml', 'xlsx' => 'xl/workbook.xml', 'pptx' => 'ppt/presentation.xml'];
        $mimes = [
            'application/zip',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        ];
        if (! isset($entries[$extension]) || ! in_array($mime, $mimes, true)) {
            $fail('Format attachment tidak diizinkan.');

            return;
        }
        $zip = new ZipArchive;
        if ($zip->open($value->getRealPath()) !== true) {
            $fail('Dokumen Office tidak valid.');

            return;
        }
        $valid = $zip->locateName('[Content_Types].xml') !== false && $zip->locateName($entries[$extension]) !== false;
        $zip->close();
        if (! $valid) {
            $fail('Isi dokumen tidak sesuai ekstensi Office.');
        }
    }
}
