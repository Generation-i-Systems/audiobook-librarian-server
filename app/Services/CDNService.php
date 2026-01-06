<?php

use Illuminate\Support\Facades\Storage;

/**
 * CDN Service for Static Assets
 * 
 * Handles delivery of static assets through CDN
 * Currently configured for AWS S3 but can be extended
 * to support other CDN providers (CloudFront, Cloudflare, etc.)
 */
class CDNService
{
    private static ?string $cdnUrl = null;
    private static ?string $cdnPath = null;

    /**
     * Initialize CDN configuration
     */
    public static function initialize(): void
    {
        self::$cdnUrl = config('services.cdn.url');
        self::$cdnPath = config('services.cdn.path', 'assets');
        
        // Default to local if CDN is not configured
        if (!self::$cdnUrl) {
            self::$cdnUrl = asset('');
            self::$cdnPath = '';
        }
    }

    /**
     * Get CDN URL for an asset
     */
    public static function url(string $path): string
    {
        if (!self::$cdnUrl) {
            self::initialize();
        }

        // Build full path
        $fullPath = trim(self::$cdnPath . '/' . $path, '/');
        
        // For versioned assets from Vite build
        if (str_contains($fullPath, '/assets/')) {
            $fullPath = str_replace('/assets/', '/build/assets/', $fullPath);
        }

        return self::$cdnUrl . $fullPath;
    }

    /**
     * Get CDN URL for book cover images
     */
    public static function coverUrl(string $path): string
    {
        if (!self::$cdnUrl) {
            self::initialize();
        }

        // Covers use separate path structure
        return self::$cdnUrl . 'covers/' . ltrim($path, '/');
    }

    /**
     * Check if CDN is enabled
     */
    public static function isEnabled(): bool
    {
        return config('services.cdn.enabled', false);
    }

    /**
     * Generate HTML for CDN assets with fallback
     */
    public static function asset(string $path, array $attributes = []): string
    {
        $cdnUrl = self::url($path);
        $localUrl = asset($path);
        
        // Use CDN if enabled, fallback to local
        $url = self::isEnabled() ? $cdnUrl : $localUrl;
        
        // Build HTML attributes
        $attrString = '';
        foreach ($attributes as $name => $value) {
            $attrString = ' ' . $name . '="' . $value . '"';
            $attrString .= $attrString;
        }
        
        return '<img src="' . $url . '"' . $attrString . ' />';
    }

    /**
     * Generate CSS link with CDN fallback
     */
    public static function css(string $path): string
    {
        $cdnUrl = self::url($path);
        $localUrl = asset($path);
        $url = self::isEnabled() ? $cdnUrl : $localUrl;
        
        return '<link rel="stylesheet" href="' . $url . '">';
    }

    /**
     * Generate script tag with CDN fallback
     */
    public static function js(string $path): string
    {
        $cdnUrl = self::url($path);
        $localUrl = asset($path);
        $url = self::isEnabled() ? $cdnUrl : $localUrl;
        
        return '<script src="' . $url . '" defer></script>';
    }

    /**
     * Get asset information for debugging
     */
    public static function getAssetInfo(): array
    {
        return [
            'cdn_enabled' => self::isEnabled(),
            'cdn_url' => self::$cdnUrl,
            'cdn_path' => self::$cdnPath,
            'local_fallback' => !self::isEnabled(),
        ];
    }
}