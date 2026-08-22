<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Institution & entity website links
    |--------------------------------------------------------------------------
    |
    | These templates populate the breadcrumb links returned by the teacher
    | profile API. Keeping them server-side means the built React bundle does
    | not need to be re-built or re-uploaded when these URLs change.
    |
    | Placeholders:
    |   {slug}  → the entity's URL slug (entities_cache.short_name, falling
    |             back to entity_profiles.slug).
    |
    */

    // Root of the main varsity website ("Home" breadcrumb).
    'home_url' => env('SITE_HOME_URL', 'https://www.nstu.edu.bd'),

    // Base URL of the entity websites, e.g. https://entities.nstu.edu.bd
    'entity_website_base' => env('SITE_ENTITY_WEBSITE_BASE', 'https://entities.nstu.local'),

    // Path template appended to the entity website URL that lists the
    // entity's faculty members.
    'faculty_directory_path' => env('SITE_FACULTY_DIRECTORY_PATH', '/faculty'),

    // Optional query string appended to the faculty directory URL.
    'faculty_directory_query' => env('SITE_FACULTY_DIRECTORY_QUERY', ''),

];
