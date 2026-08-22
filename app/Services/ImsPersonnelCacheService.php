<?php

namespace App\Services;

use App\Models\PersonnelAffiliationCache;
use App\Models\PersonnelCache;
use App\Models\PersonnelProfile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ImsPersonnelCacheService
{
    /**
     * Lazily provision a personnel profile from IMS when a visitor requests a
     * profile that has never been synced into web-api yet. Fetches the single
     * personnel (with affiliations) from IMS and creates/updates the
     * PersonnelProfile + PersonnelCache + PersonnelAffiliationCache rows.
     *
     * Returns the profile when the personnel exists in IMS, or null otherwise.
     */
    public function ensureProfile(string $personnelId): ?PersonnelProfile
    {
        try {
            $personnel = $this->fetchPersonnelFull($personnelId);
            if ($personnel === null) {
                return null;
            }

            $this->provisionProfileFromIms($personnelId, $personnel);

            return PersonnelProfile::query()->find($personnelId);
        } catch (Throwable $exception) {
            Log::warning('Failed to auto-provision personnel profile from IMS.', [
                'personnel_id' => $personnelId,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    public function resolvePhotoUrl(string $personnelId, ?int $thresholdMinutes = null): ?string
    {
        $thresholdMinutes ??= (int) config('ims.personnel_cache_update_threshold', 15);
        $cached = PersonnelCache::query()->find($personnelId);

        if ($cached && Carbon::parse($cached->updated_at)->addMinutes($thresholdMinutes)->isFuture()) {
            return $cached->photo_url;
        }

        try {
            $personnel = $this->fetchPersonnelFull($personnelId);
            if ($personnel === null) {
                return $cached?->photo_url;
            }

            $this->provisionProfileFromIms($personnelId, $personnel);

            return data_get($personnel, 'photo_url');
        } catch (Throwable $exception) {
            Log::warning('IMS personnel cache refresh failed; using cached photo URL.', [
                'personnel_id' => $personnelId,
                'error' => $exception->getMessage(),
            ]);

            return $cached?->photo_url;
        }
    }

    /**
     * Upsert all derived cache rows for a single personnel in one transaction.
     */
    protected function provisionProfileFromIms(string $personnelId, array $personnel): void
    {
        DB::transaction(function () use ($personnelId, $personnel) {
            $profile = PersonnelProfile::query()->find($personnelId);
            if (!$profile) {
                $profile = new PersonnelProfile();
                $profile->personnel_id = $personnelId;
                $profile->save();
            }

            PersonnelCache::query()->updateOrCreate(
                ['personnel_id' => $personnelId],
                [
                    'personnel_type' => data_get($personnel, 'personnel_type', 'Other'),
                    'title' => data_get($personnel, 'title'),
                    'title_bn' => data_get($personnel, 'title_bn'),
                    'first_name' => (string) data_get($personnel, 'first_name', ''),
                    'first_name_bn' => (string) data_get($personnel, 'first_name_bn', ''),
                    'last_name' => data_get($personnel, 'last_name'),
                    'last_name_bn' => data_get($personnel, 'last_name_bn'),
                    'sex' => data_get($personnel, 'sex', 'Other'),
                    'designation' => (string) (
                        data_get($personnel, 'designation.designation_with_grade')
                        ?? data_get($personnel, 'designation_name')
                        ?? 'Unknown'
                    ),
                    'designation_name' => data_get($personnel, 'designation_name'),
                    'designation_with_grade' => data_get($personnel, 'designation.designation_with_grade'),
                    'pin' => data_get($personnel, 'pin'),
                    'seniority_order' => data_get($personnel, 'seniority_order'),
                    'institutional_mail' => data_get($personnel, 'institutional_email'),
                    'primary_phone' => data_get($personnel, 'primary_phone'),
                    'photo_url' => data_get($personnel, 'photo_url'),
                    'employment_type' => data_get($personnel, 'employment_type'),
                    'date_of_joining' => data_get($personnel, 'date_of_joining'),
                    'status' => data_get($personnel, 'status'),
                    'primary_affiliation_entity_id' => data_get($personnel, 'primary_affiliation.academic_unit_id', data_get($personnel, 'primary_affiliation.entity_id')),
                    'primary_affiliation_name' => data_get($personnel, 'primary_affiliation.academic_unit_name', data_get($personnel, 'primary_affiliation')),
                    'primary_affiliation_type' => data_get($personnel, 'primary_affiliation.affiliation_type'),
                ]
            );

            foreach ((array) data_get($personnel, 'affiliations', []) as $affiliation) {
                $sourceAffiliationId = data_get($affiliation, 'id');
                if (!$sourceAffiliationId) {
                    continue;
                }

                PersonnelAffiliationCache::query()->updateOrCreate(
                    ['source_affiliation_id' => (int) $sourceAffiliationId],
                    [
                        'personnel_id' => $personnelId,
                        'entity_id' => (int) data_get($affiliation, 'academic_unit_id'),
                        'entity_name' => data_get($affiliation, 'academic_unit_name'),
                        'entity_display_name' => data_get($affiliation, 'academic_unit.display_name')
                            ?: data_get($affiliation, 'academic_unit_name'),
                        'is_primary' => (bool) data_get($affiliation, 'is_primary', false),
                        'is_active' => (bool) data_get($affiliation, 'is_active', true),
                        'affiliation_type' => data_get($affiliation, 'affiliation_type'),
                        'start_date' => data_get($affiliation, 'start_date'),
                        'end_date' => data_get($affiliation, 'end_date'),
                        'synced_at' => now(),
                    ]
                );
            }
        });
    }

    protected function fetchPersonnel(string $personnelId): ?array
    {
        $response = Http::baseUrl(rtrim((string) config('ims.api_base_url'), '/'))
            ->acceptJson()
            ->timeout(10)
            ->withHeaders(['X-API-KEY' => (string) config('ims.api_key')])
            ->get('personnels', [
                'id' => $personnelId,
                'include' => 'designation',
                'per_page' => 1,
            ])
            ->throw();

        return data_get($response->json(), 'data.0');
    }

    /**
     * Fetch a single personnel by id (full resource incl. affiliations).
     */
    protected function fetchPersonnelFull(string $personnelId): ?array
    {
        $response = Http::baseUrl(rtrim((string) config('ims.api_base_url'), '/'))
            ->acceptJson()
            ->timeout(10)
            ->withHeaders(['X-API-KEY' => (string) config('ims.api_key')])
            ->get("personnels/{$personnelId}", [
                'include' => 'affiliations,designation',
            ])
            ->throw();

        $payload = $response->json();
        $data = data_get($payload, 'data');

        return is_array($data) ? $data : null;
    }
}
