<?php

namespace App\Classes;

use App\Models\EntitySentence;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Stores illustration images for entity sentences on the private local disk
 * (ADR 0050). Files are named by their sha256 content hash, so identical
 * uploads — the same picture in two editions — share one file; deletion is
 * therefore reference-counted, not per-upload.
 */
class IllustrationStorage
{
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    /**
     * Persist an uploaded image and return the sentence columns describing
     * it. Width/height come from PHP core (getimagesize) so reading surfaces
     * can reserve the aspect ratio before the bytes arrive.
     *
     * @return array{path: string, hash: string, width: ?int, height: ?int, mime: ?string}
     */
    public function store(UploadedFile $file, string $langCode): array
    {
        $realPath = $file->getRealPath();
        $hash = hash_file('sha256', $realPath);

        $info = getimagesize($realPath);
        $mime = $info['mime'] ?? null;
        $width = is_array($info) ? ($info[0] ?? null) : null;
        $height = is_array($info) ? ($info[1] ?? null) : null;

        $extension = self::MIME_EXTENSIONS[$mime]
            ?? strtolower($file->getClientOriginalExtension() ?: 'img');

        $path = "illustrations/{$langCode}/{$hash}.{$extension}";

        Storage::disk('local')->put($path, file_get_contents($realPath));

        return [
            'path' => $path,
            'hash' => $hash,
            'width' => $width !== null ? (int) $width : null,
            'height' => $height !== null ? (int) $height : null,
            'mime' => $mime,
        ];
    }

    /**
     * Delete the stored file when no sentence references it anymore — the
     * caller must have already detached or replaced the path on its row.
     */
    public function releaseIfOrphaned(string $path): void
    {
        $stillReferenced = EntitySentence::query()
            ->where('image_path', $path)
            ->exists();

        if (! $stillReferenced) {
            Storage::disk('local')->delete($path);
        }
    }
}
