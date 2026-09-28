<?php

namespace App\Domain\Documents;

use App\Domain\RuleViolation;
use Illuminate\Http\UploadedFile;
use ZipArchive;

/**
 * Server-side checks for confidential uploads (spec §9). The type is decided by the file's content,
 * never by the browser's claim or the extension alone, and the two must agree.
 *
 * Accepted: PDF, DOCX (without macros), JPEG, PNG, WebP. Legacy .doc and every archive, script or
 * executable are refused. This is not a virus scan; no scanner is configured (see BUILD_STATUS).
 */
final class UploadGuard
{
    public const MAX_KILOBYTES = 20480;

    /** extension => sniffed MIME type */
    public const TYPES = [
        'pdf' => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    private const DOCX_MAX_ENTRIES = 2000;

    private const DOCX_MAX_UNCOMPRESSED = 200 * 1024 * 1024;

    /** Laravel validation rules for form fields; check() is still the authority. */
    public static function rules(): array
    {
        return ['file', 'max:'.self::MAX_KILOBYTES, 'extensions:'.implode(',', array_keys(self::TYPES))];
    }

    public static function acceptAttribute(): string
    {
        return implode(',', array_map(fn ($e) => '.'.$e, array_keys(self::TYPES)));
    }

    /**
     * @return array{mime: string, extension: string, size: int, sha256: string, original_name: string}
     */
    public static function check(UploadedFile $file): array
    {
        if (! $file->isValid()) {
            throw new RuleViolation('The file did not upload completely. Please try again.');
        }
        $path = $file->getRealPath();
        $size = (int) filesize($path);
        if ($size === 0) {
            throw new RuleViolation('The file is empty.');
        }
        if ($size > self::MAX_KILOBYTES * 1024) {
            throw new RuleViolation('The file is larger than '.(self::MAX_KILOBYTES / 1024).' MB.');
        }

        $original = self::cleanName($file->getClientOriginalName());
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (! isset(self::TYPES[$extension])) {
            throw new RuleViolation('Only PDF, Word (.docx), JPEG, PNG and WebP files can be uploaded.');
        }

        $sniffed = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
        $expected = self::TYPES[$extension];

        if ($extension === 'docx') {
            // finfo reports some valid .docx files as a generic zip; the structure check below decides.
            if (! in_array($sniffed, [$expected, 'application/zip', 'application/octet-stream'], true)) {
                throw new RuleViolation('The file does not appear to be a Word document.');
            }
            self::assertSafeDocx($path);
            $sniffed = $expected;
        } elseif ($sniffed !== $expected) {
            throw new RuleViolation('The file\'s contents do not match its .'.$extension.' extension.');
        }

        return [
            'mime' => $sniffed,
            'extension' => $extension,
            'size' => $size,
            'sha256' => hash_file('sha256', $path),
            'original_name' => $original,
        ];
    }

    /** Keeps a readable name for display and downloads only; storage uses a random identifier. */
    public static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F"<>:|?*]/u', '', $name) ?? '';
        $name = trim($name, " .\t");

        return mb_substr($name !== '' ? $name : 'document', -150);
    }

    private static function assertSafeDocx(string $path): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuleViolation('The Word document could not be read.');
        }

        try {
            if ($zip->numFiles < 1 || $zip->numFiles > self::DOCX_MAX_ENTRIES) {
                throw new RuleViolation('The Word document has an unexpected structure.');
            }
            $total = 0;
            $hasMain = false;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = strtolower($stat['name']);
                $total += $stat['size'];
                if (str_contains($name, '..') || str_starts_with($name, '/')) {
                    throw new RuleViolation('The Word document has an unexpected structure.');
                }
                if (str_contains($name, 'vbaproject') || str_contains($name, 'vbadata') || str_contains($name, 'activex')
                    || preg_match('/\.(exe|dll|js|vbs|bat|cmd|ps1|scr|jar|msi|com|hta)$/', $name)) {
                    throw new RuleViolation('Word documents containing macros or embedded programs cannot be uploaded. Save it as a plain .docx or PDF.');
                }
                if ($name === 'word/document.xml') {
                    $hasMain = true;
                }
            }
            if ($total > self::DOCX_MAX_UNCOMPRESSED) {
                throw new RuleViolation('The Word document is too large once expanded.');
            }
            $types = (string) $zip->getFromName('[Content_Types].xml');
            if (! $hasMain || $types === '') {
                throw new RuleViolation('The file does not appear to be a Word document.');
            }
            if (stripos($types, 'macroEnabled') !== false || stripos($types, 'vbaProject') !== false) {
                throw new RuleViolation('Word documents containing macros cannot be uploaded. Save it as a plain .docx or PDF.');
            }
        } finally {
            $zip->close();
        }
    }
}
