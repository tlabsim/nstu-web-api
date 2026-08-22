<?php

namespace App\Http\Controllers;

use App\Models\PersonnelAffiliationCache;
use App\Models\PersonnelProfile;
use App\Models\Publication;
use App\Models\Research;
use App\Models\SeminarWorkshopTraining;
use App\Services\ImsContactService;
use App\Services\ImsPersonnelCacheService;
use App\Services\ImsTeacherCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class TeacherController extends Controller
{
    public function __construct(
        protected ImsTeacherCacheService $teacherCacheService,
        protected ImsContactService $contactService,
        protected ImsPersonnelCacheService $personnelCacheService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'entity_id' => 'required|integer|exists:entity_profiles,entity_id',
            'search' => 'nullable|string',
            'primary_only' => 'nullable|boolean',
            'affiliation_type' => 'nullable|string',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $entityId = (int) $validated['entity_id'];
        $syncStatus = $this->teacherCacheService->syncEntityTeachersIfStale($entityId);

        $query = PersonnelAffiliationCache::query()
            ->where('entity_id', $entityId)
            ->where('is_active', true)
            ->join('personnels_cache', 'personnel_affiliations_cache.personnel_id', '=', 'personnels_cache.personnel_id')
            ->select([
                'personnel_affiliations_cache.source_affiliation_id',
                'personnel_affiliations_cache.personnel_id',
                'personnel_affiliations_cache.entity_id',
                'personnel_affiliations_cache.entity_name',
                'personnel_affiliations_cache.is_primary',
                'personnel_affiliations_cache.is_active',
                'personnel_affiliations_cache.affiliation_type',
                'personnels_cache.personnel_type',
                'personnels_cache.title',
                'personnels_cache.first_name',
                'personnels_cache.last_name',
                'personnels_cache.designation',
                'personnels_cache.photo_url',
                'personnels_cache.institutional_mail',
                'personnels_cache.primary_phone',
                'personnels_cache.seniority_order',
                'personnels_cache.primary_affiliation_name',
                'personnels_cache.primary_affiliation_entity_id',
                'personnels_cache.primary_affiliation_type',
            ]);

        if ($request->boolean('primary_only', false)) {
            $query->where('personnel_affiliations_cache.is_primary', true);
        }

        if ($request->filled('affiliation_type')) {
            $types = collect(explode(',', (string) $request->input('affiliation_type')))
                ->map(fn ($value) => trim($value))
                ->filter()
                ->values()
                ->all();

            if (!empty($types)) {
                $query->whereIn('personnel_affiliations_cache.affiliation_type', $types);
            }
        }

        if ($request->filled('search')) {
            $term = '%' . trim((string) $request->input('search')) . '%';
            $query->where(function ($searchQuery) use ($term) {
                $searchQuery
                    ->where('personnels_cache.first_name', 'like', $term)
                    ->orWhere('personnels_cache.last_name', 'like', $term)
                    ->orWhereRaw("CONCAT_WS(' ', personnels_cache.title, personnels_cache.first_name, personnels_cache.last_name) LIKE ?", [$term])
                    ->orWhere('personnels_cache.designation', 'like', $term)
                    ->orWhere('personnels_cache.institutional_mail', 'like', $term);
            });
        }

        $teachers = $query
            ->orderByDesc('personnel_affiliations_cache.is_primary')
            ->orderBy('personnels_cache.seniority_order')
            ->orderBy('personnels_cache.first_name')
            ->paginate((int) ($validated['per_page'] ?? 24))
            ->through(function ($row) {
                $fullName = trim(implode(' ', array_filter([
                    $row->title,
                    $row->first_name,
                    $row->last_name,
                ])));

                return $this->mapTeacherDirectoryRow($row, $fullName);
            });

        return response()->json([
            'status' => 'success',
            'data' => $teachers->items(),
            'meta' => [
                'current_page' => $teachers->currentPage(),
                'per_page' => $teachers->perPage(),
                'total' => $teachers->total(),
                'last_page' => $teachers->lastPage(),
                'cache' => [
                    'refreshed' => (bool) ($syncStatus['refreshed'] ?? false),
                    'served_from_cache' => (bool) ($syncStatus['served_from_cache'] ?? true),
                    'stale_fallback' => (bool) ($syncStatus['stale_fallback'] ?? false),
                    'last_synced_at' => $syncStatus['last_synced_at'] ?? null,
                ],
            ],
            'links' => [
                'first' => $teachers->url(1),
                'last' => $teachers->url($teachers->lastPage()),
                'prev' => $teachers->previousPageUrl(),
                'next' => $teachers->nextPageUrl(),
            ],
        ]);
    }

    /**
     * University-wide teacher directory. Proxies IMS /personnels?type=Teacher
     * server-side (with the API key) and returns a flat, alphabetically sorted
     * list of {personnel_id, name, designation} for the directory page.
     */
    public function directory(Request $request): JsonResponse
    {
        $baseUrl = rtrim((string) config('ims.api_base_url'), '/');
        $apiKey = (string) config('ims.api_key');

        if ($baseUrl === '') {
            return response()->json(['status' => 'error', 'message' => 'IMS API base URL is not configured.'], 500);
        }

        $items = [];
        $page = 1;
        $lastPage = 1;

        try {
            do {
                $response = Http::baseUrl($baseUrl)
                    ->acceptJson()
                    ->timeout(20)
                    ->withHeaders(['X-API-KEY' => $apiKey])
                    ->get('personnels', [
                        'type' => 'Teacher',
                        'status' => 'Active',
                        'include' => 'designation',
                        'per_page' => 100,
                        'page' => $page,
                    ]);

                if ($response->failed()) {
                    \Log::warning('Teacher directory fetch failed from IMS.', ['status' => $response->status()]);
                    break;
                }

                $payload = $response->json();
                $batch = (array) data_get($payload, 'data', []);
                foreach ($batch as $teacher) {
                    $items[] = $teacher;
                }

                $currentPage = (int) data_get($payload, 'meta.current_page', $page);
                $lastPage = (int) data_get($payload, 'meta.last_page', $currentPage);
                $page++;
            } while ($page <= $lastPage);
        } catch (\Throwable $e) {
            \Log::warning('Teacher directory fetch threw.', ['error' => $e->getMessage()]);
        }

        $teachers = collect($items)
            ->map(fn ($t) => [
                'personnel_id' => (string) data_get($t, 'id', ''),
                'name' => trim((string) data_get($t, 'full_name_with_title', data_get($t, 'full_name', ''))),
                'first_name' => trim((string) data_get($t, 'first_name', '')),
                'last_name' => trim((string) data_get($t, 'last_name', '')),
                'designation' => (string) data_get($t, 'designation_name', data_get($t, 'designation.designation_name', '')),
                'primary_affiliation' => (string) data_get($t, 'primary_affiliation', ''),
            ])
            ->filter(fn ($t) => $t['personnel_id'] !== '')
            ->sortBy(fn ($t) => strtoupper($t['first_name'] . ' ' . $t['last_name']))
            ->values()
            ->all();

        return response()->json([
            'status' => 'success',
            'data' => $teachers,
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'personnel_id' => 'required|string',
            'entity_id' => 'nullable|integer|exists:entity_profiles,entity_id',
        ]);

        $personnelId = (string) $validated['personnel_id'];
        $entityId = isset($validated['entity_id']) ? (int) $validated['entity_id'] : null;

        $profile = PersonnelProfile::query()
            ->with([
                'cache',
                'affiliationCache',
                'educations',
                'jobExperiences',
                'achievements',
                'professionalProfiles',
                'webSettings',
                'additionalData',
                'researcherProfile.externalProfiles',
            ])
            ->find($personnelId);

        if (!$profile) {
            // Auto-provision the profile on first visit (IMS is the source of truth).
            $profile = $this->personnelCacheService->ensureProfile($personnelId)
                ? PersonnelProfile::query()
                    ->with([
                        'cache',
                        'affiliationCache',
                        'educations',
                        'jobExperiences',
                        'achievements',
                        'professionalProfiles',
                        'webSettings',
                        'additionalData',
                        'researcherProfile.externalProfiles',
                    ])
                    ->find($personnelId)
                : null;
        }

        if (!$profile) {
            return response()->json(['status' => 'error', 'message' => 'Teacher profile not found'], 404);
        }

        $affiliations = $profile->affiliationCache
            ->where('is_active', true)
            ->values();

        if ($entityId !== null && !$affiliations->contains(fn ($item) => (int) $item->entity_id === $entityId)) {
            return response()->json(['status' => 'error', 'message' => 'Teacher is not affiliated with the requested entity'], 404);
        }

        $cache = $profile->cache;
        $primaryAffiliation = $affiliations->firstWhere('is_primary', true);

        $researcherId = PersonnelProfile::find($personnelId)?->researcher_id;

        $publications = $researcherId
            ? Publication::query()
                ->whereHas('authors', function ($query) use ($researcherId) {
                    $query->where('internal_author_id', $researcherId)
                        ->where('show_in_profile', true);
                })
                ->with(['authors', 'meta'])
                ->orderByDesc('publication_date')
                ->get()
            : collect();

        $researches = $researcherId
            ? Research::query()
                ->whereHas('people', fn ($query) => $query->where('internal_researcher_id', $researcherId))
                ->with(['people', 'publications'])
                ->orderByDesc('updated_at')
                ->get()
            : collect();

        $trainingSeminars = SeminarWorkshopTraining::query()
            ->where('personnel_id', $personnelId)
            ->orderByDesc('start_date')
            ->get();

        $fullName = trim(implode(' ', array_filter([
            data_get($cache, 'title'),
            data_get($cache, 'first_name'),
            data_get($cache, 'last_name'),
        ])));

        return response()->json([
            'status' => 'success',
            'data' => [
                'personnel_id' => $personnelId,
                'researcher_id' => $researcherId,
                'display_name' => $profile->display_name ?: $fullName,
                'full_name_with_title' => $fullName,
                'first_name' => data_get($cache, 'first_name'),
                'last_name' => data_get($cache, 'last_name'),
                'designation' => $profile->display_designation ?: data_get($cache, 'designation'),
                'designation_name' => data_get($cache, 'designation_name'),
                'designation_with_grade' => data_get($cache, 'designation_with_grade'),
                'short_bio' => $profile->short_bio,
                'biography' => $profile->biography,
                'photo_url' => data_get($cache, 'photo_url'),
                'personnel_type' => data_get($cache, 'personnel_type'),
                'primary_affiliation' => $primaryAffiliation ? [
                    'entity_id' => (int) $primaryAffiliation->entity_id,
                    'entity_name' => $primaryAffiliation->entity_name,
                    'entity_display_name' => $primaryAffiliation->entity_display_name ?: $primaryAffiliation->entity_name,
                    'affiliation_type' => $primaryAffiliation->affiliation_type,
                ] : [
                    'entity_id' => data_get($cache, 'primary_affiliation_entity_id'),
                    'entity_name' => data_get($cache, 'primary_affiliation_name'),
                    'entity_display_name' => data_get($cache, 'primary_affiliation_name'),
                    'affiliation_type' => data_get($cache, 'primary_affiliation_type'),
                ],
                'affiliations' => $affiliations->map(fn ($item) => [
                    'entity_id' => (int) $item->entity_id,
                    'entity_name' => $item->entity_name,
                    'entity_display_name' => $item->entity_display_name ?: $item->entity_name,
                    'affiliation_type' => $item->affiliation_type,
                    'is_primary' => (bool) $item->is_primary,
                    'start_date' => optional($item->start_date)->toDateString(),
                    'end_date' => optional($item->end_date)->toDateString(),
                ])->values()->all(),
                'contact' => array_merge([
                    'institutional_email' => data_get($cache, 'institutional_mail'),
                    'primary_phone' => data_get($cache, 'primary_phone'),
                ], $this->resolveImsContactBlock($personnelId)),
                'education' => $profile->educations->map(fn ($item) => [
                    'degree_title' => $item->degree_title,
                    'degree_level' => $item->degree_level,
                    'institution' => $item->institution,
                    'awarding_body' => $item->awarding_body,
                    'remarks' => $item->remarks,
                    'start_month' => $item->start_month,
                    'start_year' => $item->start_year,
                    'end_month' => $item->end_month,
                    'end_year' => $item->end_year,
                    'start_month_year' => optional($item->start_month_year)->toDateString(),
                    'end_month_year' => optional($item->end_month_year)->toDateString(),
                    'passing_year' => $item->passing_year,
                ])->values()->all(),
                'job_experiences' => $profile->jobExperiences->map(fn ($item) => [
                    'job_title' => $item->job_title,
                    'role' => $item->role,
                    'role_description' => $item->role_description,
                    'organization' => $item->organization,
                    'start_date' => optional($item->start_date)->toDateString(),
                    'end_date' => optional($item->end_date)->toDateString(),
                ])->values()->all(),
                'achievements' => $profile->achievements->map(fn ($item) => [
                    'type' => $item->type,
                    'title' => $item->title,
                    'awarding_body' => $item->awarding_body,
                    'award_date' => optional($item->award_date)->toDateString(),
                    'excerpt' => $item->excerpt,
                ])->values()->all(),
                'external_profiles' => $this->mapExternalProfiles($profile),
                'research_interests' => $this->normalizeResearchInterests(data_get($profile, 'researcherProfile.research_interests')),
                'publications' => $publications->map(fn ($item) => [
                    'id' => $item->id,
                    'title' => $item->title,
                    'excerpt' => $item->excerpt,
                    'description' => $item->description,
                    'publication_date' => optional($item->publication_date)->toDateString(),
                    'type' => $item->type,
                    'link_url' => $item->link_url,
                    'keywords' => $item->keywords,
                    'meta' => $item->meta
                        ->filter(fn ($m) => $m->meta_value !== null && $m->meta_value !== '')
                        ->mapWithKeys(fn ($m) => [$m->meta_key => $m->meta_value])
                        ->all(),
                    'authors' => $item->authors
                        ->sortBy('sl')
                        ->map(fn ($author) => [
                            'name' => $author->author_name,
                            'internal_author_id' => $author->internal_author_id,
                            'external_profile_link' => $author->external_author_profile_link,
                            'is_primary_editor' => (bool) $author->is_primary_editor,
                            'is_editor' => (bool) $author->is_editor,
                        ])->values()->all(),
                ])->values()->all(),
                'researches' => $researches->map(fn ($item) => [
                    'id' => $item->id,
                    'title' => $item->title,
                    'excerpt' => $item->excerpt,
                    'description' => $item->description,
                    'featured_image_uri' => $item->featured_image_uri,
                    'keywords' => $item->keywords,
                    'status' => $item->status,
                    'people' => $item->people
                        ->sortBy('sl')
                        ->map(fn ($person) => [
                            'name' => $person->researcher_name,
                            'internal_researcher_id' => $person->internal_researcher_id,
                            'external_profile_link' => $person->external_researcher_profile_link,
                            'role' => $person->role,
                            'is_owner' => (int) $person->internal_researcher_id === (int) $researcherId,
                        ])->values()->all(),
                ])->values()->all(),
                'training_seminars' => $trainingSeminars->map(fn ($item) => [
                    'id' => $item->id,
                    'type' => $item->type,
                    'attendee_type' => $item->attendee_type,
                    'title' => $item->title,
                    'excerpt' => $item->excerpt,
                    'description' => $item->description,
                    'start_date' => optional($item->start_date)->toDateString(),
                    'end_date' => optional($item->end_date)->toDateString(),
                ])->values()->all(),
                'web_settings' => $this->groupKeyValueCollection($profile->webSettings, 'key_group', 'setting_key'),
                'additional_data' => $this->groupKeyValueCollection($profile->additionalData, 'data_group', 'data_key'),
                'courses' => $this->decodeTeacherCourses($profile->additionalData),
                'supervisions' => $this->decodeTeacherSupervisions($profile->additionalData),
                'dashboard_profile_url' => (string) config('ims.dashboard_profile_url'),
            ],
        ]);
    }

    /**
     * Fetch additional contacts + offices from IMS (source of truth) for the
     * public profile. Fails soft to empty arrays so the page still renders.
     */
    protected function resolveImsContactBlock(?string $personnelId): array
    {
        if (!$personnelId) {
            return ['additional_contacts' => [], 'offices' => []];
        }

        try {
            return $this->contactService->contactBlock($personnelId);
        } catch (\Throwable $exception) {
            \Illuminate\Support\Facades\Log::warning('Failed to load IMS contact block.', [
                'personnel_id' => $personnelId,
                'error' => $exception->getMessage(),
            ]);

            return ['additional_contacts' => [], 'offices' => []];
        }
    }

    protected function mapTeacherDirectoryRow(object $row, string $fullName): array
    {
        return [
            'personnel_id' => $row->personnel_id,
            'personnel_type' => $row->personnel_type,
            'title' => $row->title,
            'first_name' => $row->first_name,
            'last_name' => $row->last_name,
            'full_name' => trim(implode(' ', array_filter([$row->first_name, $row->last_name]))),
            'full_name_with_title' => $fullName,
            'designation' => $row->designation,
            'photo_url' => $row->photo_url,
            'institutional_email' => $row->institutional_mail,
            'primary_phone' => $row->primary_phone,
            'primary_affiliation' => $row->primary_affiliation_name,
            'primary_affiliation_entity_id' => $row->primary_affiliation_entity_id,
            'primary_affiliation_type' => $row->primary_affiliation_type,
            'current_affiliation' => [
                'entity_id' => $row->entity_id,
                'entity_name' => $row->entity_name,
                'affiliation_type' => $row->affiliation_type,
                'is_primary' => (bool) $row->is_primary,
            ],
        ];
    }

    protected function mapExternalProfiles(PersonnelProfile $profile): array
    {
        $professionalProfiles = $profile->professionalProfiles
            ->map(fn ($item) => [
                'name' => $item->profile_type,
                'link' => $item->profile_link,
                'source' => 'professional_profile',
            ]);

        $researcherProfiles = collect(data_get($profile, 'researcherProfile.externalProfiles', []))
            ->map(fn ($item) => [
                'name' => $item->profile_type,
                'link' => $item->profile_link,
                'profile_id' => $item->profile_id,
                'source' => 'researcher_profile',
            ]);

        return $professionalProfiles
            ->merge($researcherProfiles)
            ->filter(fn ($item) => filled($item['link'] ?? null))
            ->unique(fn ($item) => Str::lower(($item['name'] ?? '') . '|' . ($item['link'] ?? '')))
            ->values()
            ->all();
    }

    protected function normalizeResearchInterests(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('trim', $value)));
        }

        if (is_string($value) && trim($value) !== '') {
            return collect(preg_split('/[,;\n]+/', $value))
                ->map(fn ($item) => trim((string) $item))
                ->filter()
                ->values()
                ->all();
        }

        return [];
    }

    protected function groupKeyValueCollection(Collection $items, string $groupKeyField, string $itemKeyField): array
    {
        return $items
            ->groupBy(fn ($item) => $item->{$groupKeyField} ?: 'default')
            ->map(function (Collection $groupedItems) use ($itemKeyField) {
                return $groupedItems
                    ->mapWithKeys(fn ($item) => [$item->{$itemKeyField} => $item->value])
                    ->all();
            })
            ->all();
    }

    protected function decodeTeacherCourses(Collection $items): array
    {
        return $items
            ->where('data_group', 'teacher_courses')
            ->map(function ($row) {
                $data = json_decode($row->value, true);
                if (!is_array($data)) {
                    $data = [];
                }
                return [
                    'id' => $row->id,
                    'course_code' => (string) ($data['course_code'] ?? ''),
                    'title' => (string) ($data['title'] ?? ''),
                    'level' => (string) ($data['level'] ?? ''),
                    'description' => (string) ($data['description'] ?? ''),
                    'link' => (string) ($data['link'] ?? ''),
                ];
            })
            ->sortBy(fn ($c) => strtoupper($c['course_code']))
            ->values()
            ->all();
    }

    protected function decodeTeacherSupervisions(Collection $items): array
    {
        return $items
            ->where('data_group', 'teacher_supervisions')
            ->map(function ($row) {
                $data = json_decode($row->value, true);
                if (!is_array($data)) {
                    $data = [];
                }
                return [
                    'id' => $row->id,
                    'student_name' => (string) ($data['student_name'] ?? ''),
                    'level' => (string) ($data['level'] ?? ''),
                    'period' => (string) ($data['period'] ?? ''),
                    'topic' => (string) ($data['topic'] ?? ''),
                    'description' => (string) ($data['description'] ?? ''),
                    'link' => (string) ($data['link'] ?? ''),
                ];
            })
            ->sortByDesc(function ($s) {
                $period = (string) ($s['period'] ?? '');
                if (preg_match('/\b(19|20)\d{2}\b/', $period, $m)) {
                    return (int) $m[0];
                }
                return 0;
            })
            ->values()
            ->all();
    }
}
