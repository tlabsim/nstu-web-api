<?php

namespace App\Services;

use App\Models\EntityCache;
use App\Models\EntityProfile;
use Illuminate\Support\Str;

class BreadcrumbLinkService
{
    /**
     * Build the breadcrumb links for a teacher profile.
     *
     * Resolves the primary academic unit's slug so the entity website and
     * directory URLs can be assembled from config templates — allowing the
     * URLs to change server-side without rebuilding the frontend bundle.
     *
     * @param int|null $entityId
     * @param string|null $entityName
     * @return array{home: array{label: string, url: string|null}, entity: array{label: string, url: string|null}, directory: array{label: string, url: string|null}}
     */
    public function build(?int $entityId, ?string $entityName = null): array
    {
        $slug = $entityId ? $this->resolveEntitySlug($entityId) : null;
        $label = $entityName ?: 'Faculty';

        $entityUrl = $slug ? $this->assembleEntityUrl($slug) : null;
        $directoryUrl = $slug ? $this->assembleDirectoryUrl($slug) : null;

        return [
            'home' => [
                'label' => 'Home',
                'url' => (string) config('site_links.home_url'),
            ],
            'entity' => [
                'label' => $label,
                'url' => $entityUrl,
            ],
            'directory' => [
                'label' => 'Faculty members',
                'url' => $directoryUrl,
            ],
        ];
    }

    protected function resolveEntitySlug(int $entityId): ?string
    {
        $shortName = EntityCache::query()
            ->where('entity_id', $entityId)
            ->value('short_name');

        if ($shortName) {
            return Str::slug($shortName);
        }

        $slug = EntityProfile::query()
            ->where('entity_id', $entityId)
            ->value('slug');

        return $slug;
    }

    protected function assembleEntityUrl(string $slug): string
    {
        $base = rtrim((string) config('site_links.entity_website_base'), '/');
        $base = $this->stripTrailingPort($base);

        return $base . '/' . $slug;
    }

    protected function assembleDirectoryUrl(string $slug): string
    {
        $entityUrl = $this->assembleEntityUrl($slug);
        $path = (string) config('site_links.faculty_directory_path');
        $query = (string) config('site_links.faculty_directory_query');

        $url = $entityUrl . ($path === '' ? '' : '/' . ltrim($path, '/'));

        if ($query !== '') {
            $url .= (str_contains($url, '?') ? '&' : '?') . ltrim($query, '?&');
        }

        return $url;
    }

    /**
     * Remove a trailing :port from a URL configured during local development
     * (e.g. "http://entities.nstu.local:8080") so production URLs are clean.
     */
    protected function stripTrailingPort(string $url): string
    {
        return preg_replace('/^(https?:\/\/[^\/]+):\d+(.*)$/', '$1$2', $url);
    }
}
