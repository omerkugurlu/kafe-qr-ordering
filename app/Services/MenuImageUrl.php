<?php

namespace App\Services;

final class MenuImageUrl
{
    private const REMOTE_HOST = 'qrapi.advertpark.com';

    public static function make(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $parts = parse_url($path);
        if (is_array($parts) && isset($parts['scheme'], $parts['host'])) {
            if (strtolower($parts['scheme']) === 'https'
                && strtolower($parts['host']) === self::REMOTE_HOST
                && ! isset($parts['user'])
                && ! isset($parts['pass'])
                && ! isset($parts['port'])
                && ! isset($parts['query'])
                && ! isset($parts['fragment'])
                && self::isAllowedRemotePath(rawurldecode($parts['path'] ?? ''))) {
                $remotePath = rawurldecode($parts['path']);
                return url('/media/menu-import/'.self::encodePath(substr($remotePath, strlen('/media/files/'))));
            }

            return $path;
        }

        return url('/media/'.self::encodePath(ltrim($path, '/')));
    }

    public static function isAllowedRemotePath(string $path): bool
    {
        if (in_array('.', explode('/', $path), true) || in_array('..', explode('/', $path), true)) {
            return false;
        }

        return preg_match('#\A/media/files/[\pL\pN._/-]+\.(?:jpe?g|png|webp|gif|avif)\z#iuD', $path) === 1;
    }

    private static function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }
}
