<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PublicMediaController
{
    public function show(string $path): BinaryFileResponse
    {
        $segments = explode('/', $path);
        abort_if($path === '' || in_array('..', $segments, true) || in_array('.', $segments, true), 404);
        abort_unless(in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'], true), 404);

        $disk = Storage::disk('public');
        abort_unless($disk->exists($path), 404);

        $mimeType = $disk->mimeType($path) ?: 'application/octet-stream';
        abort_unless(str_starts_with($mimeType, 'image/'), 404);

        return response()->file($disk->path($path), [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'public, max-age=86400, stale-while-revalidate=604800',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
