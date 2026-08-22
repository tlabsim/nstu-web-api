<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

use App\Http\Controllers\EntityController;
use App\Http\Controllers\EntityNavigationController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\PostPreviewController;
use App\Http\Controllers\PersonnelController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\TeacherController;
use App\Http\Controllers\PublicationController;
use App\Http\Controllers\ResearchController;
use App\Http\Controllers\ProgramProfileController;
use App\Http\Controllers\SnippetController;
use App\Http\Controllers\GalleryController;

use App\Http\Controllers\Editor\PageController as EditorPageController;
use App\Http\Controllers\Editor\PostController as EditorPostController;
use App\Http\Controllers\Editor\PostCategoryController;
use App\Http\Controllers\Editor\PostTaggedEntityController;
use App\Http\Controllers\Editor\MenuController as EditorMenuController;
use App\Http\Controllers\Editor\SnippetController as EditorSnippetController;
use App\Http\Controllers\Editor\MediaFolderController as EditorMediaFolderController;
use App\Http\Controllers\Editor\MediaItemController as EditorMediaItemController;
use App\Http\Controllers\Editor\GalleryController as EditorGalleryController;
use App\Http\Controllers\Editor\ProgramProfileController as EditorProgramProfileController;
use App\Http\Controllers\Api\PersonnelSelfEditController;
use App\Http\Controllers\Entity\ProfileController as EntityProfileController;
use App\Http\Controllers\Entity\SettingController as EntitySettingController;


Route::middleware([])->group(function () {
    // 🔹 Entities

    Route::get('/', function () {
        return response()->json(['message' => 'Welcome to the NSTU Web API v1']);
    });

    Route::get('entities', [EntityController::class, 'index']);        // Optional filter: ?type=Department
    Route::get('entity_profiles', [EntityController::class, 'indexProfiles']);        // Optional filter: ?type=Department
    Route::get('entity', [EntityController::class, 'show']);           // Input: ?entity_id or ?slug
    Route::get('entity/menus', [EntityController::class, 'menus']);    // Input: ?entity_id
    Route::get('entity/settings', [EntityController::class, 'settings']); // Input: ?entity_id
    Route::get('entity/navigation', [EntityNavigationController::class, 'show']); // Input: ?entity_id
    Route::get('entity/program-profiles', [ProgramProfileController::class, 'index']);
    Route::get('entity/program-profile', [ProgramProfileController::class, 'show']);

    // 🔹 Static Pages
    Route::get('pages', [PageController::class, 'index']);             // Input: ?entity_id, optional: category_id, subcategory_id
    Route::get('page', [PageController::class, 'show']);               // Input: ?entity_id, ?page_slug

    // 🔹 Posts
    Route::get('posts', [PostController::class, 'index']);             // Input: ?entity_id, optional filters
    Route::get('post', [PostController::class, 'show']);               // Input: ?id
    Route::get('preview/post', [PostPreviewController::class, 'show']);

    // 🔹 Personnel
    Route::get('personnel', [PersonnelController::class, 'show']);     // Input: ?personnel_id
    Route::get('teachers', [TeacherController::class, 'index']);       // Input: ?entity_id, optional filters
    Route::get('teacher', [TeacherController::class, 'show']);         // Input: ?personnel_id, optional: ?entity_id
    Route::get('teacher-directory', [TeacherController::class, 'directory']); // Complete alphabetical teacher directory
    Route::get('search', [SearchController::class, 'index']);          // Input: ?entity_id&q=

    // 🔹 Publications
    Route::get('publication', [PublicationController::class, 'show']); // Input: ?id

    // 🔹 Research
    Route::get('research', [ResearchController::class, 'show']);       // Input: ?id

    // 🔹 Snippets
    Route::get('snippets', [SnippetController::class, 'index']);       // Input: ?entity_id or ?slug    

    // 🔹 Galleries
    Route::get('galleries', [GalleryController::class, 'index']);      // Input: ?entity_id, optional filters
    Route::get('gallery', [GalleryController::class, 'show']);         // Input: ?id OR ?entity_id + ?slug

    // 🔹 Post Categories (Public read access)
    Route::get('post-categories', [PostCategoryController::class, 'index']);
    Route::get('post-categories/{id}', [PostCategoryController::class, 'show']);
    
});

// Self-edit routes (personnel editing own public profile)
Route::middleware(['ims.logged_in'])->group(function () {
    Route::post('self/personnel/short-bio', [\App\Http\Controllers\Api\PersonnelSelfEditController::class, 'updateShortBio']);
    Route::post('self/personnel/biography', [\App\Http\Controllers\Api\PersonnelSelfEditController::class, 'updateBiography']);
    Route::post('self/personnel/education', [\App\Http\Controllers\Api\PersonnelSelfEditController::class, 'updateEducation']);
    Route::post('self/personnel/job-experiences', [\App\Http\Controllers\Api\PersonnelSelfEditController::class, 'updateJobExperiences']);
    Route::post('self/personnel/training-seminars', [\App\Http\Controllers\Api\PersonnelSelfEditController::class, 'updateTrainingSeminars']);
    Route::post('self/personnel/achievements', [\App\Http\Controllers\Api\PersonnelSelfEditController::class, 'updateAchievements']);
    Route::post('self/personnel/external-profiles', [\App\Http\Controllers\Api\PersonnelSelfEditController::class, 'updateExternalProfiles']);
    Route::post('self/personnel/research-interests', [\App\Http\Controllers\Api\PersonnelSelfEditController::class, 'updateResearchInterests']);
    Route::post('self/personnel/photo', [\App\Http\Controllers\Api\PersonnelSelfEditController::class, 'updatePhoto']);
    Route::delete('self/personnel/photo', [\App\Http\Controllers\Api\PersonnelSelfEditController::class, 'deletePhoto']);
    Route::post('self/personnel/research', [\App\Http\Controllers\Api\PersonnelSelfEditController::class, 'storeResearch']);
    Route::post('self/personnel/research/image', [\App\Http\Controllers\Api\PersonnelSelfEditController::class, 'uploadResearchImage']);
    Route::get('self/researchers/search', [\App\Http\Controllers\Api\PersonnelSelfEditController::class, 'searchResearchers']);
    Route::get('self/personnel/research/{research}', [\App\Http\Controllers\Api\PersonnelSelfEditController::class, 'showResearch']);
    Route::get('self/personnel/publications', [\App\Http\Controllers\Api\PersonnelSelfEditController::class, 'listMyPublications']);
    Route::post('self/personnel/publication', [\App\Http\Controllers\Api\PersonnelSelfEditController::class, 'storePublication']);
    Route::get('self/personnel/publication/{publication}', [\App\Http\Controllers\Api\PersonnelSelfEditController::class, 'showPublication']);
    Route::put('self/personnel/publication/{publication}', [\App\Http\Controllers\Api\PersonnelSelfEditController::class, 'updatePublication']);
    Route::delete('self/personnel/publication/{publication}', [\App\Http\Controllers\Api\PersonnelSelfEditController::class, 'deletePublication']);
    Route::put('self/personnel/research/{research}', [\App\Http\Controllers\Api\PersonnelSelfEditController::class, 'updateResearch']);
    Route::delete('self/personnel/research/{research}', [\App\Http\Controllers\Api\PersonnelSelfEditController::class, 'deleteResearch']);
    Route::get('self/personnel/courses', [\App\Http\Controllers\Api\PersonnelSelfEditController::class, 'listCourses']);
    Route::post('self/personnel/course', [\App\Http\Controllers\Api\PersonnelSelfEditController::class, 'storeCourse']);
    Route::put('self/personnel/course/{id}', [\App\Http\Controllers\Api\PersonnelSelfEditController::class, 'updateCourse']);
    Route::delete('self/personnel/course/{id}', [\App\Http\Controllers\Api\PersonnelSelfEditController::class, 'deleteCourse']);
    Route::get('self/personnel/supervisions', [\App\Http\Controllers\Api\PersonnelSelfEditController::class, 'listSupervisions']);
    Route::post('self/personnel/supervision', [\App\Http\Controllers\Api\PersonnelSelfEditController::class, 'storeSupervision']);
    Route::put('self/personnel/supervision/{id}', [\App\Http\Controllers\Api\PersonnelSelfEditController::class, 'updateSupervision']);
    Route::delete('self/personnel/supervision/{id}', [\App\Http\Controllers\Api\PersonnelSelfEditController::class, 'deleteSupervision']);
});

// Only for web curators
// This route group is protected by the 'ims.logged_in_and_role_selected:web_curator'
// The middleware will inject the entity scope based on the selected role
// 'ims.logged_in_and_role_selected:web_curator'
Route::middleware(['ims.logged_in_and_role_selected:web_curator'])->group(function () {
    // Pages
    Route::get('editor/pages', [EditorPageController::class, 'index']);       // List all pages for editor
    Route::post('editor/pages', [EditorPageController::class, 'store']);
    Route::put('editor/pages/{id}', [EditorPageController::class, 'update']);
    Route::delete('editor/pages/{id}', [EditorPageController::class, 'destroy']);

    // Posts
    Route::get('editor/posts', [EditorPostController::class, 'index']);
    Route::get('editor/posts/{id}', [EditorPostController::class, 'show']);
    Route::post('editor/posts', [EditorPostController::class, 'store']);
    Route::put('editor/posts/{id}', [EditorPostController::class, 'update']);
    Route::delete('editor/posts/{id}', [EditorPostController::class, 'destroy']);
    Route::post('editor/posts/{id}/toggle-featured', [EditorPostController::class, 'toggleFeatured']);
    Route::post('editor/posts/{id}/update-status', [EditorPostController::class, 'updateStatus']);

    // Post Categories (Write operations - require authentication)
    Route::post('post-categories', [PostCategoryController::class, 'store']);
    Route::put('post-categories/{id}', [PostCategoryController::class, 'update']);
    Route::delete('post-categories/{id}', [PostCategoryController::class, 'destroy']);
    Route::post('post-categories/{id}/toggle-active', [PostCategoryController::class, 'toggleActive']);
    Route::post('post-categories/reorder', [PostCategoryController::class, 'reorder']);

    // Post Tagged Entities
    Route::get('post-tagged-entities', [PostTaggedEntityController::class, 'index']);           // Input: ?entity_id, ?status, ?post_id
    Route::post('post-tagged-entities', [PostTaggedEntityController::class, 'store']);
    Route::get('post-tagged-entities/{id}', [PostTaggedEntityController::class, 'show']);
    Route::put('post-tagged-entities/{id}', [PostTaggedEntityController::class, 'update']);     // Update status, approved_by
    Route::delete('post-tagged-entities/{id}', [PostTaggedEntityController::class, 'destroy']);

    // Menus, categories, subcategories
    Route::get('categories', [EditorMenuController::class, 'indexCategories']);       // Input: ?entity_id
    Route::get('subcategories', [EditorMenuController::class, 'indexSubcategories']); // Input: ?entity_id, ?category_id
    Route::post('categories', [EditorMenuController::class, 'createCategory']);
    Route::post('categories-all', [EditorMenuController::class, 'updateCategoriesAll']); //Web Curator can update all categories for the entity at once
    Route::post('subcategories', [EditorMenuController::class, 'createSubcategory']);
    Route::delete('categories/{id}', [EditorMenuController::class, 'destroyCategory']);
    Route::delete('subcategories/{id}', [EditorMenuController::class, 'destroySubcategory']);

    // Snippets
    Route::get('editor/snippets', [EditorSnippetController::class, 'index']);
    Route::post('editor/snippets', [EditorSnippetController::class, 'store']);
    Route::put('editor/snippets/{id}', [EditorSnippetController::class, 'update']);
    Route::delete('editor/snippets/{id}', [EditorSnippetController::class, 'destroy']);

    // Media uploads
    Route::post('media/upload', [\App\Http\Controllers\Api\MediaController::class, 'uploadImage']);
    Route::delete('media/delete', [\App\Http\Controllers\Api\MediaController::class, 'deleteImage']);

    // Media library
    Route::get('editor/media/folders', [EditorMediaFolderController::class, 'index']);
    Route::post('editor/media/folders', [EditorMediaFolderController::class, 'store']);
    Route::get('editor/media/folders/{id}', [EditorMediaFolderController::class, 'show']);
    Route::put('editor/media/folders/{id}', [EditorMediaFolderController::class, 'update']);
    Route::delete('editor/media/folders/{id}', [EditorMediaFolderController::class, 'destroy']);
    Route::post('editor/media/folders/reorder', [EditorMediaFolderController::class, 'reorder']);

    Route::get('editor/media/items', [EditorMediaItemController::class, 'index']);
    Route::post('editor/media/items/upload', [EditorMediaItemController::class, 'upload']);
    Route::get('editor/media/items/{id}', [EditorMediaItemController::class, 'show']);
    Route::put('editor/media/items/{id}', [EditorMediaItemController::class, 'update']);
    Route::post('editor/media/items/{id}/move', [EditorMediaItemController::class, 'move']);
    Route::delete('editor/media/items/{id}', [EditorMediaItemController::class, 'destroy']);

    // Galleries
    Route::get('editor/galleries', [EditorGalleryController::class, 'index']);
    Route::post('editor/galleries', [EditorGalleryController::class, 'store']);
    Route::get('editor/galleries/{id}', [EditorGalleryController::class, 'show']);
    Route::put('editor/galleries/{id}', [EditorGalleryController::class, 'update']);
    Route::delete('editor/galleries/{id}', [EditorGalleryController::class, 'destroy']);
    Route::post('editor/galleries/{galleryId}/items', [EditorGalleryController::class, 'addItems']);
    Route::put('editor/galleries/{galleryId}/items/reorder', [EditorGalleryController::class, 'reorderItems']);
    Route::put('editor/galleries/{galleryId}/items/{itemId}', [EditorGalleryController::class, 'updateItem']);
    Route::delete('editor/galleries/{galleryId}/items/{itemId}', [EditorGalleryController::class, 'removeItem']);

    // Entity Profile
    Route::get('entity/profile', [EntityProfileController::class, 'show']);
    Route::put('entity/profile', [EntityProfileController::class, 'update']);

    // Entity Settings
    Route::get('editor/entity/settings', [EntitySettingController::class, 'index']);
    Route::post('editor/entity/settings/create', [EntitySettingController::class, 'store']);
    Route::put('editor/entity/settings/{id}', [EntitySettingController::class, 'updateSingle']);
    Route::delete('editor/entity/settings/{id}', [EntitySettingController::class, 'destroy']);
    Route::put('editor/entity/settings', [EntitySettingController::class, 'update']);

    // Entity program website profiles. Program facts remain canonical in IMS.
    Route::get('editor/program-profiles', [EditorProgramProfileController::class, 'index']);
    Route::get('editor/program-profiles/{programId}', [EditorProgramProfileController::class, 'show']);
    Route::put('editor/program-profiles/{programId}', [EditorProgramProfileController::class, 'upsert']);
    Route::delete('editor/program-profiles/{programId}', [EditorProgramProfileController::class, 'destroy']);
    Route::put('editor/program-profiles-order', [EditorProgramProfileController::class, 'reorder']);
});
