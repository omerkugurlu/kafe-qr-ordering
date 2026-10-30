<?php

namespace App\Http\Controllers;

use App\Services\MenuImageUrl;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class PublicMediaController
{
    public function proxyExternal(string $path): Response
    {
        $remotePath = '/'.ltrim($path, '/');
        abort_unless(MenuImageUrl::isAllowedRemotePath($remotePath), 404);

        $url = 'https://qrapi.advertpark.com'.$remotePath;
        $cacheKey = 'menu-image-proxy:'.hash('sha256', $url);
        $image = Cache::store('file')->remember($cacheKey, now()->addDays(7), function () use ($url): array {
            $upstream = Http::connectTimeout(3)
                ->timeout(12)
                ->withOptions(['allow_redirects' => false])
                ->get($url);

            abort_unless($upstream->successful(), 404);

            $mimeType = strtolower(trim(explode(';', $upstream->header('Content-Type', ''))[0]));
            abort_unless(in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'], true), 404);

            $body = $upstream->body();
            abort_if($body === '' || strlen($body) > 5 * 1024 * 1024, 404);

            return ['mime' => $mimeType, 'body' => base64_encode($body)];
        });

        $body = base64_decode($image['body'], true);
        abort_if($body === false, 404);

        return response($body, 200, [
            'Content-Type' => $image['mime'],
            'Cache-Control' => 'public, max-age=604800, stale-while-revalidate=2592000',
            'X-Content-Type-Options' => 'nosniff',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ]);
    }

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
