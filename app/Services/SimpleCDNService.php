<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Simple CDN Service
 * 
 * Basic implementation for static asset CDN integration
 * Can be extended to support various CDN providers
 */
class SimpleAssetCDNService
{
    /**
     * Check if CDN is enabled
     */
    public static function isEnabled(): bool
    {
        return config('services.cdn.enabled', false);
    }

    /**
     * Get CDN URL for an asset
     */
    public static function url(string $path): string
    {
        if (!self::isEnabled()) {
            return asset($path);
        }

        // Default to local if CDN is not configured
        $baseUrl = config('services.cdn.url', 'https://cdn.example.com');
        $basePath = config('services.cdn.path', 'assets');
        
        // Build full path
        $fullPath = trim($basePath . '/' . $path, '/');
        
        // For versioned assets from Vite build
        if (str_contains($fullPath, '/assets/')) {
            $fullPath = str_replace('/assets/', '/build/assets/', $fullPath);
        }

        return $baseUrl . $fullPath;
    }

    /**
     * Get CDN URL for book cover images
     */
    public static function coverUrl(string $path): string
    {
        if (!self::isEnabled()) {
            return asset('covers/' . $path);
        }

        $baseUrl = config('services.cdn.url', 'https://cdn.example.com');
        
        // Covers use separate path structure
        return $baseUrl . 'covers/' . ltrim($path, '/');
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
            'cdn_url' => config('services.cdn.url', 'https://cdn.example.com'),
            'cdn_path' => config('services.cdn.path', 'assets'),
            'local_fallback' => !self::isEnabled(),
        ];
    }
}