<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PersonnelAchievement;
use App\Models\PersonnelAdditionalData;
use App\Models\PersonnelCache;
use App\Models\PersonnelEducation;
use App\Models\PersonnelJobExperience;
use App\Models\PersonnelProfile;
use App\Models\PersonnelProfessionalProfile;
use App\Models\Publication;
use App\Models\PublicationAuthor;
use App\Models\PublicationMeta;
use App\Models\Research;
use App\Models\ResearchPeople;
use App\Models\ResearchPublication;
use App\Models\Researcher;
use App\Models\SeminarWorkshopTraining;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mews\Purifier\Facades\Purifier;

class PersonnelSelfEditController extends Controller
{
    /**
     * Update the short bio for the currently logged-in personnel.
     */
    public function updateShortBio(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'short_bio' => 'required|string|max:300',
        ]);

        $imsUser = $this->readImsUser($request);
        $personnelId = $imsUser['personnel_id'] ?? null;

        if (!$personnelId) {
            return response()->json(['status' => 'error', 'message' => 'Could not identify personnel.'], 403);
        }

        $profile = PersonnelProfile::firstOrCreate(
            ['personnel_id' => $personnelId],
            []
        );

        $profile->update(['short_bio' => trim($validated['short_bio'])]);

        return response()->json([
            'status' => 'success',
            'message' => 'Short bio updated.',
            'data' => ['short_bio' => $profile->short_bio],
        ]);
    }

    /**
     * Update the long biography for the currently logged-in personnel.
     */
    public function updateBiography(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'biography' => 'nullable|string|max:5000',
        ]);

        $imsUser = $this->readImsUser($request);
        $personnelId = $imsUser['personnel_id'] ?? null;

        if (!$personnelId) {
            return response()->json(['status' => 'error', 'message' => 'Could not identify personnel.'], 403);
        }

        $profile = PersonnelProfile::firstOrCreate(['personnel_id' => $personnelId]);

        $biography = trim((string) ($validated['biography'] ?? ''));
        $profile->update(['biography' => $biography]);

        return response()->json([
            'status' => 'success',
            'message' => 'Biography updated.',
            'data' => ['biography' => $biography],
        ]);
    }

    /**
     * Replace the education history for the logged-in personnel.
     */
    public function updateEducation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'educations' => 'present|array',
            'educations.*.degree_title' => 'required|string|max:512',
            'educations.*.degree_level' => 'required|in:Primary,Secondary,Higher Secondary,Undergraduate,Post-graduate,Doctoral,Post-doctoral',
            'educations.*.institution' => 'required|string|max:255',
            'educations.*.awarding_body' => 'nullable|string|max:255',
            'educations.*.remarks' => 'nullable|string|max:2000',
            'educations.*.start_month_year' => 'nullable|date',
            'educations.*.end_month_year' => 'nullable|date',
            'educations.*.passing_year' => 'required|string|size:4',
        ], [
            'educations.*.degree_title.required' => 'Provide a degree title.',
            'educations.*.degree_title.max' => 'Provide a shorter degree title.',
            'educations.*.institution.required' => 'Provide an institution.',
            'educations.*.passing_year.required' => 'Provide a passing year.',
            'educations.*.passing_year.size' => 'Provide a 4-digit year, e.g. 2021.',
        ]);

        $imsUser = $this->readImsUser($request);
        $personnelId = $imsUser['personnel_id'] ?? null;

        if (!$personnelId) {
            return response()->json(['status' => 'error', 'message' => 'Could not identify personnel.'], 403);
        }

        $profile = PersonnelProfile::firstOrCreate(['personnel_id' => $personnelId]);

        // Delete all existing education records
        $profile->educations()->delete();

        $created = [];
        foreach ($validated['educations'] as $item) {
            $created[] = PersonnelEducation::create([
                'personnel_id' => $personnelId,
                'degree_title' => trim($item['degree_title']),
                'degree_level' => $item['degree_level'],
                'institution' => trim($item['institution']),
                'awarding_body' => isset($item['awarding_body']) && $item['awarding_body'] !== '' ? trim($item['awarding_body']) : null,
                'remarks' => isset($item['remarks']) && $item['remarks'] !== '' ? trim($item['remarks']) : null,
                'start_month_year' => $item['start_month_year'] ?? null,
                'end_month_year' => $item['end_month_year'] ?? null,
                'passing_year' => $item['passing_year'],
            ]);
        }

        $mapped = collect($created)->map(fn ($item) => [
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
        ])->values()->all();

        return response()->json([
            'status' => 'success',
            'message' => 'Education updated.',
            'data' => ['education' => $mapped],
        ]);
    }

    /**
     * Replace all external profile links for the logged-in personnel.
     */
    public function updateExternalProfiles(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'profiles' => 'required|array',
            'profiles.*.name' => 'required|string|max:100',
            'profiles.*.link' => 'required|string|url|max:1024',
        ]);

        $imsUser = $this->readImsUser($request);
        $personnelId = $imsUser['personnel_id'] ?? null;

        if (!$personnelId) {
            return response()->json(['status' => 'error', 'message' => 'Could not identify personnel.'], 403);
        }

        $profile = PersonnelProfile::firstOrCreate(['personnel_id' => $personnelId]);

        // Delete all existing professional profiles for this personnel
        $profile->professionalProfiles()->delete();

        // Re-insert in the provided order
        foreach ($validated['profiles'] as $item) {
            PersonnelProfessionalProfile::create([
                'personnel_id' => $personnelId,
                'profile_type' => $item['name'],
                'profile_link' => $item['link'],
            ]);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'External profiles updated.',
        ]);
    }

    /**
     * Replace the research interests for the logged-in personnel.
     */
    public function updateResearchInterests(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'interests' => 'present|array',
            'interests.*' => 'required|string|max:255',
        ]);

        $imsUser = $this->readImsUser($request);
        $personnelId = $imsUser['personnel_id'] ?? null;

        if (!$personnelId) {
            return response()->json(['status' => 'error', 'message' => 'Could not identify personnel.'], 403);
        }

        $profile = PersonnelProfile::firstOrCreate(['personnel_id' => $personnelId]);

        $researcher = $profile->researcherProfile;
        if (!$researcher) {
            $researcher = Researcher::create([]);
            $profile->update(['researcher_id' => $researcher->id]);
        }

        $interests = collect($validated['interests'])
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->values()
            ->all();

        $researcher->update(['research_interests' => $interests]);

        return response()->json([
            'status' => 'success',
            'message' => 'Research interests updated.',
            'data' => ['research_interests' => $interests],
        ]);
    }

    /**
     * Replace the job experiences for the logged-in personnel.
     */
    public function updateJobExperiences(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'experiences' => 'present|array',
            'experiences.*.job_title' => 'required|string|max:512',
            'experiences.*.role' => 'nullable|string|max:255',
            'experiences.*.role_description' => 'nullable|string|max:5000',
            'experiences.*.organization' => 'nullable|string|max:255',
            'experiences.*.start_date' => 'required|date',
            'experiences.*.end_date' => 'nullable|date',
        ], [
            'experiences.*.job_title.required' => 'Provide a job title.',
            'experiences.*.start_date.required' => 'Provide a start date.',
        ]);

        $imsUser = $this->readImsUser($request);
        $personnelId = $imsUser['personnel_id'] ?? null;

        if (!$personnelId) {
            return response()->json(['status' => 'error', 'message' => 'Could not identify personnel.'], 403);
        }

        $profile = PersonnelProfile::firstOrCreate(['personnel_id' => $personnelId]);
        $profile->jobExperiences()->delete();

        $created = [];
        foreach ($validated['experiences'] as $item) {
            $created[] = PersonnelJobExperience::create([
                'personnel_id' => $personnelId,
                'job_title' => trim($item['job_title']),
                'role' => trim((string) ($item['role'] ?? '')),
                'role_description' => isset($item['role_description']) && $item['role_description'] !== '' ? trim($item['role_description']) : null,
                'organization' => isset($item['organization']) && $item['organization'] !== '' ? trim($item['organization']) : null,
                'start_date' => $item['start_date'],
                'end_date' => $item['end_date'] ?? null,
            ]);
        }

        $mapped = collect($created)->map(fn ($item) => [
            'job_title' => $item->job_title,
            'role' => $item->role,
            'role_description' => $item->role_description,
            'organization' => $item->organization,
            'start_date' => optional($item->start_date)->toDateString(),
            'end_date' => optional($item->end_date)->toDateString(),
        ])->values()->all();

        return response()->json([
            'status' => 'success',
            'message' => 'Job experiences updated.',
            'data' => ['job_experiences' => $mapped],
        ]);
    }

    /**
     * Replace the training/workshop/seminar entries of one type for the logged-in personnel.
     */
    public function updateTrainingSeminars(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => 'required|in:Seminar,Workshop,Training',
            'items' => 'present|array',
            'items.*.title' => 'required|string|max:2000',
            'items.*.attendee_type' => 'nullable|in:Organizer,Keynote speaker,Speaker,Presenter,Panel discussant,Trainer,Trainee,Conductor,Attendee',
            'items.*.excerpt' => 'nullable|string|max:2000',
            'items.*.description' => 'nullable|string|max:5000',
            'items.*.start_date' => 'required|date',
            'items.*.end_date' => 'nullable|date',
        ], [
            'items.*.title.required' => 'Provide a title.',
            'items.*.start_date.required' => 'Provide a start date.',
        ]);

        $imsUser = $this->readImsUser($request);
        $personnelId = $imsUser['personnel_id'] ?? null;

        if (!$personnelId) {
            return response()->json(['status' => 'error', 'message' => 'Could not identify personnel.'], 403);
        }

        $type = $validated['type'];

        // Replace only this type's rows, leaving other types untouched
        SeminarWorkshopTraining::where('personnel_id', $personnelId)
            ->where('type', $type)
            ->delete();

        foreach ($validated['items'] as $item) {
            SeminarWorkshopTraining::create([
                'personnel_id' => $personnelId,
                'type' => $type,
                'title' => trim($item['title']),
                'attendee_type' => $item['attendee_type'] ?? 'Attendee',
                'excerpt' => isset($item['excerpt']) && $item['excerpt'] !== '' ? trim($item['excerpt']) : null,
                'description' => isset($item['description']) && $item['description'] !== '' ? trim($item['description']) : '',
                'start_date' => $item['start_date'],
                'end_date' => $item['end_date'] ?? null,
            ]);
        }

        // Return all rows (all types) so the client can refresh its local copy
        $all = SeminarWorkshopTraining::where('personnel_id', $personnelId)
            ->orderByDesc('start_date')
            ->get()
            ->map(fn ($item) => [
                'id' => $item->id,
                'type' => $item->type,
                'attendee_type' => $item->attendee_type,
                'title' => $item->title,
                'excerpt' => $item->excerpt,
                'description' => $item->description,
                'start_date' => optional($item->start_date)->toDateString(),
                'end_date' => optional($item->end_date)->toDateString(),
            ])->values()->all();

        return response()->json([
            'status' => 'success',
            'message' => 'Updated.',
            'data' => ['training_seminars' => $all],
        ]);
    }

    /**
     * Replace the awards/achievements for the logged-in personnel.
     */
    public function updateAchievements(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'achievements' => 'present|array',
            'achievements.*.type' => 'required|in:Award,Achievement,Scholarship',
            'achievements.*.title' => 'required|string|max:2000',
            'achievements.*.awarding_body' => 'nullable|string|max:2000',
            'achievements.*.award_date' => 'nullable|date',
            'achievements.*.excerpt' => 'nullable|string|max:5000',
        ], [
            'achievements.*.title.required' => 'Provide a title.',
        ]);

        $imsUser = $this->readImsUser($request);
        $personnelId = $imsUser['personnel_id'] ?? null;

        if (!$personnelId) {
            return response()->json(['status' => 'error', 'message' => 'Could not identify personnel.'], 403);
        }

        $profile = PersonnelProfile::firstOrCreate(['personnel_id' => $personnelId]);
        $profile->achievements()->delete();

        $created = [];
        foreach ($validated['achievements'] as $item) {
            $created[] = PersonnelAchievement::create([
                'personnel_id' => $personnelId,
                'type' => $item['type'],
                'title' => trim($item['title']),
                'awarding_body' => isset($item['awarding_body']) && $item['awarding_body'] !== '' ? trim($item['awarding_body']) : null,
                'award_date' => $item['award_date'] ?? null,
                'excerpt' => isset($item['excerpt']) && $item['excerpt'] !== '' ? trim($item['excerpt']) : null,
            ]);
        }

        $mapped = collect($created)->map(fn ($item) => [
            'type' => $item->type,
            'title' => $item->title,
            'awarding_body' => $item->awarding_body,
            'award_date' => optional($item->award_date)->toDateString(),
            'excerpt' => $item->excerpt,
        ])->values()->all();

        return response()->json([
            'status' => 'success',
            'message' => 'Achievements updated.',
            'data' => ['achievements' => $mapped],
        ]);
    }

    /**
     * Upload / replace the photo for the logged-in personnel.
     * Proxies the file to the IMS self-service photo endpoint.
     */
    public function updatePhoto(Request $request): JsonResponse
    {
        $request->validate([
            'photo' => 'required|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        $imsUser = $this->readImsUser($request);
        $personnelId = $imsUser['personnel_id'] ?? null;

        if (!$personnelId) {
            return response()->json(['status' => 'error', 'message' => 'Could not identify personnel.'], 403);
        }

        $accessToken = $request->cookie('ims_access_token');
        if (!$accessToken) {
            return response()->json(['status' => 'error', 'message' => 'Not authenticated with IMS.'], 401);
        }

        $imsBase = rtrim(env('IMS_API_BASE_URL', 'http://ims.nstu.local/api'), '/');

        $response = Http::withToken($accessToken)
            ->attach('photo', file_get_contents($request->file('photo')), $request->file('photo')->getClientOriginalName())
            ->post("{$imsBase}/me/personnel/photo");

        if (!$response->successful()) {
            $body = $response->json();
            return response()->json([
                'status' => 'error',
                'message' => $body['message'] ?? 'Failed to upload photo.',
            ], $response->status());
        }

        // Update local cache so guests see the new photo immediately
        $result = $response->json();
        PersonnelCache::where('personnel_id', $personnelId)->update(['photo_url' => $result['photo_url'] ?? null]);

        return response()->json($result);
    }

    /**
     * Delete the photo for the logged-in personnel.
     * Proxies to the IMS self-service photo endpoint.
     */
    public function deletePhoto(Request $request): JsonResponse
    {
        $imsUser = $this->readImsUser($request);
        $personnelId = $imsUser['personnel_id'] ?? null;

        if (!$personnelId) {
            return response()->json(['status' => 'error', 'message' => 'Could not identify personnel.'], 403);
        }

        $accessToken = $request->cookie('ims_access_token');
        if (!$accessToken) {
            return response()->json(['status' => 'error', 'message' => 'Not authenticated with IMS.'], 401);
        }

        $imsBase = rtrim(env('IMS_API_BASE_URL', 'http://ims.nstu.local/api'), '/');

        $response = Http::withToken($accessToken)
            ->delete("{$imsBase}/me/personnel/photo");

        if (!$response->successful()) {
            $body = $response->json();
            return response()->json([
                'status' => 'error',
                'message' => $body['message'] ?? 'Failed to delete photo.',
            ], $response->status());
        }

        // Update local cache so guests see the removal immediately
        $result = $response->json();
        PersonnelCache::where('personnel_id', $personnelId)->update(['photo_url' => $result['photo_url'] ?? null]);

        return response()->json($result);
    }

    /**
     * Upload a featured image for a research item.
     * Stores the file on the public disk and returns its URL.
     */
    public function uploadResearchImage(Request $request): JsonResponse
    {
        $request->validate([
            'image' => 'required|image|mimes:jpg,jpeg,png,gif,webp|max:5120',
        ]);

        $imsUser = $this->readImsUser($request);
        $personnelId = $imsUser['personnel_id'] ?? null;

        if (!$personnelId) {
            return response()->json(['status' => 'error', 'message' => 'Could not identify personnel.'], 403);
        }

        $file = $request->file('image');
        $extension = strtolower($file->getClientOriginalExtension()) ?: $file->guessExtension();
        $storedName = 'research-' . Str::random(20) . '.' . $extension;
        $path = $file->storeAs('faculty_profiles/research', $storedName, 'public');

        return response()->json([
            'status' => 'success',
            'message' => 'Image uploaded.',
            'data' => ['uri' => Storage::disk('public')->url($path)],
        ]);
    }

    /**
     * Create a new research item for the logged-in personnel.
     */
    public function storeResearch(Request $request): JsonResponse
    {
        $validated = $this->validateResearch($request);

        $imsUser = $this->readImsUser($request);
        $personnelId = $imsUser['personnel_id'] ?? null;

        if (!$personnelId) {
            return response()->json(['status' => 'error', 'message' => 'Could not identify personnel.'], 403);
        }

        $researcherId = $this->resolveResearcherId($personnelId);

        $research = Research::create([
            'title' => trim($validated['title']),
            'excerpt' => isset($validated['excerpt']) && $validated['excerpt'] !== '' ? trim($validated['excerpt']) : null,
            'description' => isset($validated['description']) && $validated['description'] !== '' ? Purifier::clean($validated['description'], 'rich') : null,
            'keywords' => isset($validated['keywords']) && $validated['keywords'] !== '' ? trim($validated['keywords']) : '',
            'status' => $validated['status'],
            'featured_image_uri' => isset($validated['featured_image_uri']) && $validated['featured_image_uri'] !== '' ? trim($validated['featured_image_uri']) : '',
        ]);

        ResearchPeople::create([
            'research_id' => $research->id,
            'researcher_name' => $this->resolvePersonnelName($personnelId),
            'internal_researcher_id' => $researcherId,
            'role' => 'Researcher',
            'sl' => 1,
            'is_primary_editor' => true,
            'is_editor' => true,
        ]);

        $this->syncResearchCollaborators($research, (int) $researcherId, $validated['collaborators'] ?? []);
        $this->syncResearchPublications($research, (int) $researcherId, $validated['publication_ids'] ?? []);

        return response()->json([
            'status' => 'success',
            'message' => 'Research added.',
            'data' => ['research' => $this->mapResearch($research, (int) $researcherId)],
        ]);
    }

    /**
     * Update one of the logged-in personnel's research items.
     */
    public function updateResearch(Request $request, $researchId): JsonResponse
    {
        $validated = $this->validateResearch($request);

        $imsUser = $this->readImsUser($request);
        $personnelId = $imsUser['personnel_id'] ?? null;

        if (!$personnelId) {
            return response()->json(['status' => 'error', 'message' => 'Could not identify personnel.'], 403);
        }

        $researcherId = $this->resolveResearcherId($personnelId);

        $research = Research::query()
            ->whereHas('people', fn ($query) => $query->where('internal_researcher_id', $researcherId))
            ->find($researchId);

        if (!$research) {
            return response()->json(['status' => 'error', 'message' => 'Research not found.'], 404);
        }

        $research->update([
            'title' => trim($validated['title']),
            'excerpt' => isset($validated['excerpt']) && $validated['excerpt'] !== '' ? trim($validated['excerpt']) : null,
            'description' => isset($validated['description']) && $validated['description'] !== '' ? Purifier::clean($validated['description'], 'rich') : null,
            'keywords' => isset($validated['keywords']) && $validated['keywords'] !== '' ? trim($validated['keywords']) : '',
            'status' => $validated['status'],
            'featured_image_uri' => isset($validated['featured_image_uri']) && $validated['featured_image_uri'] !== '' ? trim($validated['featured_image_uri']) : '',
        ]);

        $this->syncResearchCollaborators($research, $researcherId, $validated['collaborators'] ?? []);
        $this->syncResearchPublications($research, $researcherId, $validated['publication_ids'] ?? []);

        return response()->json([
            'status' => 'success',
            'message' => 'Research updated.',
            'data' => ['research' => $this->mapResearch($research, $researcherId)],
        ]);
    }

    /**
     * Remove the logged-in personnel from one of their research items.
     * If they are the only person, the whole research is deleted.
     */
    public function deleteResearch(Request $request, $researchId): JsonResponse
    {
        $imsUser = $this->readImsUser($request);
        $personnelId = $imsUser['personnel_id'] ?? null;

        if (!$personnelId) {
            return response()->json(['status' => 'error', 'message' => 'Could not identify personnel.'], 403);
        }

        $researcherId = $this->resolveResearcherId($personnelId);

        $research = Research::query()
            ->with('people')
            ->whereHas('people', fn ($query) => $query->where('internal_researcher_id', $researcherId))
            ->find($researchId);

        if (!$research) {
            return response()->json(['status' => 'error', 'message' => 'Research not found.'], 404);
        }

        $otherPeople = $research->people->filter(fn ($person) => (int) $person->internal_researcher_id !== $researcherId);

        if ($otherPeople->isEmpty()) {
            $research->delete();
            $message = 'Research deleted.';
        } else {
            ResearchPeople::query()
                ->where('research_id', $research->id)
                ->where('internal_researcher_id', $researcherId)
                ->delete();
            $message = 'Removed you from this research.';
        }

        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => ['id' => (int) $researchId],
        ]);
    }

    private function validateResearch(Request $request): array
    {
        return $request->validate([
            'title' => 'required|string|max:2000',
            'excerpt' => 'nullable|string|max:2000',
            'description' => 'nullable|string|max:20000',
            'keywords' => 'nullable|string|max:2000',
            'status' => 'required|in:Ongoing,Completed',
            'featured_image_uri' => 'nullable|string|max:2000',
            'collaborators' => 'present|array',
            'collaborators.*.researcher_id' => 'nullable|integer',
            'collaborators.*.name' => 'required|string|max:240',
            'collaborators.*.external_profile_link' => 'nullable|string|max:2000',
            'publication_ids' => 'present|array',
            'publication_ids.*' => 'integer',
        ], [
            'title.required' => 'Provide a title.',
            'status.in' => 'Status must be Ongoing or Completed.',
            'collaborators.*.name.required' => 'Provide a collaborator name.',
        ]);
    }

    private function resolveResearcherId(string $personnelId): ?int
    {
        $profile = PersonnelProfile::firstOrCreate(['personnel_id' => $personnelId]);

        if ($profile->researcher_id) {
            return (int) $profile->researcher_id;
        }

        $researcher = Researcher::where('rpid', $personnelId)->first();

        if (!$researcher) {
            $researcher = Researcher::create(['rpid' => $personnelId]);
        }

        $profile->update(['researcher_id' => $researcher->id]);

        return (int) $researcher->id;
    }

    private function resolvePersonnelName(string $personnelId): string
    {
        $cache = PersonnelCache::where('personnel_id', $personnelId)->first();

        $name = trim(implode(' ', array_filter([
            $cache->title ?? null,
            $cache->first_name ?? null,
            $cache->last_name ?? null,
        ])));

        return $name !== '' ? $name : 'Researcher';
    }

    private function mapResearch(Research $research, ?int $ownerResearcherId = null): array
    {
        $research->load(['people', 'publications']);

        return [
            'id' => $research->id,
            'title' => $research->title,
            'excerpt' => $research->excerpt,
            'description' => $research->description,
            'featured_image_uri' => $research->featured_image_uri,
            'keywords' => $research->keywords,
            'status' => $research->status,
            'people' => $research->people
                ->sortBy('sl')
                ->map(fn ($person) => [
                    'name' => $person->researcher_name,
                    'internal_researcher_id' => $person->internal_researcher_id,
                    'external_profile_link' => $person->external_researcher_profile_link,
                    'role' => $person->role,
                    'is_owner' => $ownerResearcherId !== null && (int) $person->internal_researcher_id === $ownerResearcherId,
                ])
                ->values()
                ->all(),
            'publications' => $research->publications
                ->map(fn ($link) => [
                    'publication_id' => $link->publication_id,
                    'title' => $link->publication_title,
                    'link' => $link->publication_link,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Replace the collaborators of a research item (the owner row is preserved).
     */
    private function syncResearchCollaborators(Research $research, int $ownerResearcherId, array $collaborators): void
    {
        ResearchPeople::query()
            ->where('research_id', $research->id)
            ->where(function ($query) use ($ownerResearcherId) {
                $query->where('internal_researcher_id', '!=', $ownerResearcherId)
                    ->orWhereNull('internal_researcher_id');
            })
            ->delete();

        $sl = 2;
        foreach ($collaborators as $collaborator) {
            $researcherId = isset($collaborator['researcher_id']) && $collaborator['researcher_id'] !== null
                ? (int) $collaborator['researcher_id']
                : null;

            $name = trim((string) ($collaborator['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            if ($researcherId !== null) {
                if ($researcherId === $ownerResearcherId) {
                    continue;
                }
                if (!Researcher::whereKey($researcherId)->exists()) {
                    continue;
                }
            }

            ResearchPeople::create([
                'research_id' => $research->id,
                'researcher_name' => $name,
                'internal_researcher_id' => $researcherId,
                'external_researcher_profile_link' => isset($collaborator['external_profile_link']) && $collaborator['external_profile_link'] !== '' ? trim($collaborator['external_profile_link']) : null,
                'role' => 'Researcher',
                'sl' => $sl++,
                'is_primary_editor' => false,
                'is_editor' => true,
            ]);
        }
    }

    /**
     * Replace the publications linked to a research item (only the owner's own publications).
     */
    private function syncResearchPublications(Research $research, int $ownerResearcherId, array $publicationIds): void
    {
        $ids = collect($publicationIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        ResearchPublication::where('research_id', $research->id)->delete();

        if (empty($ids)) {
            return;
        }

        $publications = Publication::query()
            ->whereIn('id', $ids)
            ->whereHas('authors', fn ($query) => $query->where('internal_author_id', $ownerResearcherId))
            ->get();

        foreach ($publications as $publication) {
            ResearchPublication::create([
                'research_id' => $research->id,
                'publication_id' => $publication->id,
                'publication_title' => $publication->title,
                'publication_link' => $publication->link_url,
            ]);
        }
    }

    /**
     * Search internal researchers by name (for collaborator / author pickers).
     */
    public function searchResearchers(Request $request): JsonResponse
    {
        $request->validate([
            'q' => 'nullable|string|max:120',
        ]);

        $imsUser = $this->readImsUser($request);
        $personnelId = $imsUser['personnel_id'] ?? null;

        if (!$personnelId) {
            return response()->json(['status' => 'error', 'message' => 'Could not identify personnel.'], 403);
        }

        $q = trim((string) $request->input('q', ''));
        if ($q === '') {
            return response()->json(['status' => 'success', 'data' => []]);
        }

        $term = '%' . $q . '%';

        $results = Researcher::query()
            ->join('personnel_profiles', 'personnel_profiles.researcher_id', '=', 'researchers.id')
            ->join('personnels_cache', 'personnels_cache.personnel_id', '=', 'personnel_profiles.personnel_id')
            ->where(function ($query) use ($term) {
                $query->where('personnels_cache.first_name', 'like', $term)
                    ->orWhere('personnels_cache.last_name', 'like', $term)
                    ->orWhereRaw("CONCAT_WS(' ', personnels_cache.title, personnels_cache.first_name, personnels_cache.last_name) LIKE ?", [$term]);
            })
            ->select(
                'researchers.id as researcher_id',
                'personnels_cache.title',
                'personnels_cache.first_name',
                'personnels_cache.last_name',
                'personnels_cache.designation',
                'personnels_cache.photo_url'
            )
            ->limit(20)
            ->get()
            ->map(function ($row) {
                return [
                    'researcher_id' => (int) $row->researcher_id,
                    'name' => trim(implode(' ', array_filter([$row->title, $row->first_name, $row->last_name]))),
                    'designation' => $row->designation,
                    'photo_url' => $row->photo_url,
                ];
            })
            ->values()
            ->all();

        return response()->json(['status' => 'success', 'data' => $results]);
    }

    /**
     * Fetch one of the logged-in personnel's research items (for the editor page).
     */
    public function showResearch(Request $request, $researchId): JsonResponse
    {
        $imsUser = $this->readImsUser($request);
        $personnelId = $imsUser['personnel_id'] ?? null;

        if (!$personnelId) {
            return response()->json(['status' => 'error', 'message' => 'Could not identify personnel.'], 403);
        }

        $researcherId = $this->resolveResearcherId($personnelId);

        $research = Research::query()
            ->whereHas('people', fn ($query) => $query->where('internal_researcher_id', $researcherId))
            ->find($researchId);

        if (!$research) {
            return response()->json(['status' => 'error', 'message' => 'Research not found.'], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => ['research' => $this->mapResearch($research, $researcherId)],
        ]);
    }

    /**
     * List the logged-in personnel's own publications (for linking to research).
     */
    public function listMyPublications(Request $request): JsonResponse
    {
        $imsUser = $this->readImsUser($request);
        $personnelId = $imsUser['personnel_id'] ?? null;

        if (!$personnelId) {
            return response()->json(['status' => 'error', 'message' => 'Could not identify personnel.'], 403);
        }

        $researcherId = $this->resolveResearcherId($personnelId);

        $publications = $researcherId
            ? Publication::query()
                ->whereHas('authors', fn ($query) => $query->where('internal_author_id', $researcherId))
                ->orderByDesc('publication_date')
                ->get(['id', 'title', 'type', 'publication_date'])
                ->map(fn ($publication) => [
                    'id' => $publication->id,
                    'title' => $publication->title,
                    'type' => $publication->type,
                    'publication_date' => optional($publication->publication_date)->toDateString(),
                ])
                ->values()
                ->all()
            : [];

        return response()->json(['status' => 'success', 'data' => $publications]);
    }

    /**
     * Create a publication for the logged-in personnel.
     */
    public function storePublication(Request $request): JsonResponse
    {
        $validated = $this->validatePublication($request);

        $imsUser = $this->readImsUser($request);
        $personnelId = $imsUser['personnel_id'] ?? null;

        if (!$personnelId) {
            return response()->json(['status' => 'error', 'message' => 'Could not identify personnel.'], 403);
        }

        $researcherId = $this->resolveResearcherId($personnelId);

        $publication = Publication::create([
            'title' => trim($validated['title']),
            'excerpt' => isset($validated['excerpt']) && $validated['excerpt'] !== '' ? trim($validated['excerpt']) : null,
            'description' => isset($validated['description']) && $validated['description'] !== '' ? Purifier::clean($validated['description'], 'rich') : null,
            'publication_date' => $validated['publication_date'],
            'type' => $validated['type'],
            'link_url' => isset($validated['link_url']) && $validated['link_url'] !== '' ? trim($validated['link_url']) : '',
            'keywords' => $this->normalizePublicationKeywords($validated['keywords'] ?? ''),
        ]);

        $this->syncPublicationAuthors($publication, $researcherId, $validated['authors'] ?? [], $this->resolvePersonnelName($personnelId));
        $this->syncPublicationMeta($publication, $validated['meta'] ?? []);

        return response()->json([
            'status' => 'success',
            'message' => 'Publication added.',
            'data' => ['publication' => $this->mapPublication($publication, $researcherId)],
        ]);
    }

    /**
     * Update one of the logged-in personnel's publications.
     */
    public function updatePublication(Request $request, $publicationId): JsonResponse
    {
        $validated = $this->validatePublication($request);

        $imsUser = $this->readImsUser($request);
        $personnelId = $imsUser['personnel_id'] ?? null;

        if (!$personnelId) {
            return response()->json(['status' => 'error', 'message' => 'Could not identify personnel.'], 403);
        }

        $researcherId = $this->resolveResearcherId($personnelId);

        $publication = Publication::query()
            ->whereHas('authors', fn ($query) => $query->where('internal_author_id', $researcherId))
            ->find($publicationId);

        if (!$publication) {
            return response()->json(['status' => 'error', 'message' => 'Publication not found.'], 404);
        }

        $publication->update([
            'title' => trim($validated['title']),
            'excerpt' => isset($validated['excerpt']) && $validated['excerpt'] !== '' ? trim($validated['excerpt']) : null,
            'description' => isset($validated['description']) && $validated['description'] !== '' ? Purifier::clean($validated['description'], 'rich') : null,
            'publication_date' => $validated['publication_date'],
            'type' => $validated['type'],
            'link_url' => isset($validated['link_url']) && $validated['link_url'] !== '' ? trim($validated['link_url']) : '',
            'keywords' => $this->normalizePublicationKeywords($validated['keywords'] ?? ''),
        ]);

        $this->syncPublicationAuthors($publication, $researcherId, $validated['authors'] ?? [], $this->resolvePersonnelName($personnelId));
        $this->syncPublicationMeta($publication, $validated['meta'] ?? []);

        return response()->json([
            'status' => 'success',
            'message' => 'Publication updated.',
            'data' => ['publication' => $this->mapPublication($publication, $researcherId)],
        ]);
    }

    /**
     * Delete one of the logged-in personnel's publications.
     */
    public function deletePublication(Request $request, $publicationId): JsonResponse
    {
        $imsUser = $this->readImsUser($request);
        $personnelId = $imsUser['personnel_id'] ?? null;

        if (!$personnelId) {
            return response()->json(['status' => 'error', 'message' => 'Could not identify personnel.'], 403);
        }

        $researcherId = $this->resolveResearcherId($personnelId);

        $publication = Publication::query()
            ->whereHas('authors', fn ($query) => $query->where('internal_author_id', $researcherId))
            ->find($publicationId);

        if (!$publication) {
            return response()->json(['status' => 'error', 'message' => 'Publication not found.'], 404);
        }

        $publication->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Publication deleted.',
            'data' => ['id' => (int) $publicationId],
        ]);
    }

    /**
     * Fetch one of the logged-in personnel's publications (for the editor page).
     */
    public function showPublication(Request $request, $publicationId): JsonResponse
    {
        $imsUser = $this->readImsUser($request);
        $personnelId = $imsUser['personnel_id'] ?? null;

        if (!$personnelId) {
            return response()->json(['status' => 'error', 'message' => 'Could not identify personnel.'], 403);
        }

        $researcherId = $this->resolveResearcherId($personnelId);

        $publication = Publication::query()
            ->whereHas('authors', fn ($query) => $query->where('internal_author_id', $researcherId))
            ->find($publicationId);

        if (!$publication) {
            return response()->json(['status' => 'error', 'message' => 'Publication not found.'], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => ['publication' => $this->mapPublication($publication, $researcherId)],
        ]);
    }

    private function validatePublication(Request $request): array
    {
        return $request->validate([
            'title' => 'required|string|max:2000',
            'type' => 'required|in:Journal Article,Conference Paper,Book Chapter,Patent,Other',
            'publication_date' => 'required|date',
            'excerpt' => 'nullable|string|max:2000',
            'description' => 'nullable|string|max:20000',
            'link_url' => 'nullable|string|max:1024',
            'keywords' => 'nullable|string|max:2000',
            'authors' => 'present|array',
            'authors.*.researcher_id' => 'nullable|integer',
            'authors.*.name' => 'required|string|max:240',
            'authors.*.external_profile_link' => 'nullable|string|max:2000',
            'authors.*.is_self' => 'nullable|boolean',
            'meta' => 'present|array',
        ], [
            'title.required' => 'Provide a title.',
            'type.in' => 'Invalid publication type.',
            'publication_date.required' => 'Provide a publication date.',
            'authors.*.name.required' => 'Provide an author name.',
        ]);
    }

    private function normalizePublicationKeywords(?string $keywords): ?string
    {
        $list = collect(explode(',', (string) $keywords))
            ->map(fn ($keyword) => trim((string) $keyword))
            ->filter(fn ($keyword) => $keyword !== '')
            ->values()
            ->all();

        return empty($list) ? null : json_encode($list);
    }

    private function mapPublication(Publication $publication, ?int $ownerResearcherId = null): array
    {
        $publication->load(['authors', 'meta']);

        $meta = [];
        foreach ($publication->meta as $item) {
            if ($item->meta_value !== null && $item->meta_value !== '') {
                $meta[$item->meta_key] = $item->meta_value;
            }
        }

        $keywords = $publication->keywords;
        if (is_array($keywords)) {
            $keywords = implode(', ', $keywords);
        }

        return [
            'id' => $publication->id,
            'title' => $publication->title,
            'excerpt' => $publication->excerpt,
            'description' => $publication->description,
            'publication_date' => optional($publication->publication_date)->toDateString(),
            'type' => $publication->type,
            'link_url' => $publication->link_url,
            'keywords' => $keywords ?? '',
            'meta' => (object) $meta,
            'authors' => $publication->authors
                ->sortBy('sl')
                ->map(fn ($author) => [
                    'name' => $author->author_name,
                    'internal_author_id' => $author->internal_author_id,
                    'external_profile_link' => $author->external_author_profile_link,
                    'is_owner' => $ownerResearcherId !== null && (int) $author->internal_author_id === $ownerResearcherId,
                ])
                ->values()
                ->all(),
        ];
    }

    private function syncPublicationAuthors(Publication $publication, int $ownerResearcherId, array $authors, string $ownerName): void
    {
        PublicationAuthor::where('publication_id', $publication->id)->delete();

        $normalized = [];
        foreach ($authors as $author) {
            $name = trim((string) ($author['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $researcherId = isset($author['researcher_id']) && $author['researcher_id'] !== null
                ? (int) $author['researcher_id']
                : null;

            if ($researcherId !== null && $researcherId !== $ownerResearcherId && !Researcher::whereKey($researcherId)->exists()) {
                $researcherId = null;
            }

            $normalized[] = [
                'name' => $name,
                'researcher_id' => $researcherId,
                'external_profile_link' => isset($author['external_profile_link']) && $author['external_profile_link'] !== '' ? trim($author['external_profile_link']) : null,
                'is_self' => (bool) ($author['is_self'] ?? false),
            ];
        }

        if (empty($normalized)) {
            $normalized[] = [
                'name' => $ownerName,
                'researcher_id' => null,
                'external_profile_link' => null,
                'is_self' => true,
            ];
        }

        // Ensure exactly one author is marked as the profile owner.
        if (!collect($normalized)->contains(fn ($author) => $author['is_self'])) {
            $normalized[0]['is_self'] = true;
        }

        $sl = 1;
        foreach ($normalized as $author) {
            PublicationAuthor::create([
                'publication_id' => $publication->id,
                'author_name' => $author['name'],
                'internal_author_id' => $author['is_self'] ? $ownerResearcherId : $author['researcher_id'],
                'external_author_profile_link' => $author['is_self'] ? null : $author['external_profile_link'],
                'sl' => $sl++,
                'is_primary_editor' => false,
                'is_editor' => true,
                'show_in_profile' => $author['is_self'],
            ]);
        }
    }

    private function syncPublicationMeta(Publication $publication, array $meta): void
    {
        PublicationMeta::where('publication_id', $publication->id)->delete();

        foreach ($meta as $key => $value) {
            $key = trim((string) $key);
            if ($key === '') {
                continue;
            }

            if (is_array($value)) {
                $value = json_encode($value);
            }

            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }

            PublicationMeta::create([
                'publication_id' => $publication->id,
                'meta_key' => $key,
                'meta_value' => $value,
            ]);
        }
    }

    private function readImsUser(Request $request): array
    {
        $cookie = $request->cookie('ims_user');
        if (!$cookie) {
            return [];
        }

        $decoded = json_decode($cookie, true);
        return is_array($decoded) ? $decoded : [];
    }

    // ---------------------------------------------------------------------
    // Teaching: courses & supervisions (stored in personnel_additional_data)
    // ---------------------------------------------------------------------

    private function additionalDataRows(string $personnelId, string $group): array
    {
        return PersonnelAdditionalData::where('personnel_id', $personnelId)
            ->where('data_group', $group)
            ->get()
            ->all();
    }

    private function decodeCourses(array $rows): array
    {
        return collect($rows)
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

    private function decodeSupervisions(array $rows): array
    {
        return collect($rows)
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

    private function coursesPayload(string $personnelId): array
    {
        return ['courses' => $this->decodeCourses($this->additionalDataRows($personnelId, 'teacher_courses'))];
    }

    private function supervisionsPayload(string $personnelId): array
    {
        return ['supervisions' => $this->decodeSupervisions($this->additionalDataRows($personnelId, 'teacher_supervisions'))];
    }

    public function listCourses(Request $request): JsonResponse
    {
        $personnelId = $this->readImsUser($request)['personnel_id'] ?? null;
        if (!$personnelId) {
            return response()->json(['status' => 'error', 'message' => 'Could not identify personnel.'], 403);
        }
        return response()->json(['status' => 'success', 'data' => $this->coursesPayload($personnelId)]);
    }

    public function storeCourse(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'course_code' => 'required|string|max:120',
            'title' => 'required|string|max:512',
            'level' => 'nullable|string|max:120',
            'description' => 'nullable|string|max:5000',
            'link' => 'nullable|string|max:1024',
        ]);

        $personnelId = $this->readImsUser($request)['personnel_id'] ?? null;
        if (!$personnelId) {
            return response()->json(['status' => 'error', 'message' => 'Could not identify personnel.'], 403);
        }

        PersonnelAdditionalData::create([
            'personnel_id' => $personnelId,
            'data_group' => 'teacher_courses',
            'data_key' => trim($validated['course_code']),
            'value' => json_encode([
                'course_code' => trim($validated['course_code']),
                'title' => trim($validated['title']),
                'level' => trim((string) ($validated['level'] ?? '')),
                'description' => trim((string) ($validated['description'] ?? '')),
                'link' => trim((string) ($validated['link'] ?? '')),
            ]),
            'value_type' => 'json',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Course added.',
            'data' => $this->coursesPayload($personnelId),
        ]);
    }

    public function updateCourse(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'course_code' => 'required|string|max:120',
            'title' => 'required|string|max:512',
            'level' => 'nullable|string|max:120',
            'description' => 'nullable|string|max:5000',
            'link' => 'nullable|string|max:1024',
        ]);

        $personnelId = $this->readImsUser($request)['personnel_id'] ?? null;
        if (!$personnelId) {
            return response()->json(['status' => 'error', 'message' => 'Could not identify personnel.'], 403);
        }

        $row = PersonnelAdditionalData::where('personnel_id', $personnelId)
            ->where('data_group', 'teacher_courses')
            ->whereKey($id)
            ->first();

        if (!$row) {
            return response()->json(['status' => 'error', 'message' => 'Course not found.'], 404);
        }

        $row->update([
            'data_key' => trim($validated['course_code']),
            'value' => json_encode([
                'course_code' => trim($validated['course_code']),
                'title' => trim($validated['title']),
                'level' => trim((string) ($validated['level'] ?? '')),
                'description' => trim((string) ($validated['description'] ?? '')),
                'link' => trim((string) ($validated['link'] ?? '')),
            ]),
            'value_type' => 'json',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Course updated.',
            'data' => $this->coursesPayload($personnelId),
        ]);
    }

    public function deleteCourse(Request $request, string $id): JsonResponse
    {
        $personnelId = $this->readImsUser($request)['personnel_id'] ?? null;
        if (!$personnelId) {
            return response()->json(['status' => 'error', 'message' => 'Could not identify personnel.'], 403);
        }

        PersonnelAdditionalData::where('personnel_id', $personnelId)
            ->where('data_group', 'teacher_courses')
            ->whereKey($id)
            ->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Course removed.',
            'data' => $this->coursesPayload($personnelId),
        ]);
    }

    public function listSupervisions(Request $request): JsonResponse
    {
        $personnelId = $this->readImsUser($request)['personnel_id'] ?? null;
        if (!$personnelId) {
            return response()->json(['status' => 'error', 'message' => 'Could not identify personnel.'], 403);
        }
        return response()->json(['status' => 'success', 'data' => $this->supervisionsPayload($personnelId)]);
    }

    public function storeSupervision(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'student_name' => 'required|string|max:512',
            'level' => 'nullable|string|max:120',
            'period' => 'nullable|string|max:120',
            'topic' => 'nullable|string|max:512',
            'description' => 'nullable|string|max:5000',
            'link' => 'nullable|string|max:1024',
        ]);

        $personnelId = $this->readImsUser($request)['personnel_id'] ?? null;
        if (!$personnelId) {
            return response()->json(['status' => 'error', 'message' => 'Could not identify personnel.'], 403);
        }

        PersonnelAdditionalData::create([
            'personnel_id' => $personnelId,
            'data_group' => 'teacher_supervisions',
            'data_key' => (string) Str::uuid(),
            'value' => json_encode([
                'student_name' => trim($validated['student_name']),
                'level' => trim((string) ($validated['level'] ?? '')),
                'period' => trim((string) ($validated['period'] ?? '')),
                'topic' => trim((string) ($validated['topic'] ?? '')),
                'description' => trim((string) ($validated['description'] ?? '')),
                'link' => trim((string) ($validated['link'] ?? '')),
            ]),
            'value_type' => 'json',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Supervision added.',
            'data' => $this->supervisionsPayload($personnelId),
        ]);
    }

    public function updateSupervision(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'student_name' => 'required|string|max:512',
            'level' => 'nullable|string|max:120',
            'period' => 'nullable|string|max:120',
            'topic' => 'nullable|string|max:512',
            'description' => 'nullable|string|max:5000',
            'link' => 'nullable|string|max:1024',
        ]);

        $personnelId = $this->readImsUser($request)['personnel_id'] ?? null;
        if (!$personnelId) {
            return response()->json(['status' => 'error', 'message' => 'Could not identify personnel.'], 403);
        }

        $row = PersonnelAdditionalData::where('personnel_id', $personnelId)
            ->where('data_group', 'teacher_supervisions')
            ->whereKey($id)
            ->first();

        if (!$row) {
            return response()->json(['status' => 'error', 'message' => 'Supervision not found.'], 404);
        }

        $row->update([
            'value' => json_encode([
                'student_name' => trim($validated['student_name']),
                'level' => trim((string) ($validated['level'] ?? '')),
                'period' => trim((string) ($validated['period'] ?? '')),
                'topic' => trim((string) ($validated['topic'] ?? '')),
                'description' => trim((string) ($validated['description'] ?? '')),
                'link' => trim((string) ($validated['link'] ?? '')),
            ]),
            'value_type' => 'json',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Supervision updated.',
            'data' => $this->supervisionsPayload($personnelId),
        ]);
    }

    public function deleteSupervision(Request $request, string $id): JsonResponse
    {
        $personnelId = $this->readImsUser($request)['personnel_id'] ?? null;
        if (!$personnelId) {
            return response()->json(['status' => 'error', 'message' => 'Could not identify personnel.'], 403);
        }

        PersonnelAdditionalData::where('personnel_id', $personnelId)
            ->where('data_group', 'teacher_supervisions')
            ->whereKey($id)
            ->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Supervision removed.',
            'data' => $this->supervisionsPayload($personnelId),
        ]);
    }
}
