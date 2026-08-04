<?php

/**
 * Returns the importmap for this application.
 *
 * - "path" is a path inside the asset mapper system. Use the
 *     "debug:asset-map" command to see the full list of paths.
 *
 * - "entrypoint" (JavaScript only) set to true for any module that will
 *     be used as an "entrypoint" (and passed to the importmap() Twig function).
 *
 * The "importmap:require" command can be used to add new entries to this file.
 */
return [
    'app' => [
        'path' => './assets/app.js',
        'entrypoint' => true,
    ],
    '@hotwired/stimulus' => [
        'version' => '3.2.2',
    ],
    '@symfony/stimulus-bundle' => [
        'path' => '@symfony/stimulus-bundle/loader.js',
    ],
    'intl-messageformat' => [
        'version' => '10.7.18',
    ],
    'tslib' => [
        'version' => '2.8.1',
    ],
    '@formatjs/fast-memoize' => [
        'version' => '2.2.7',
    ],
    '@formatjs/icu-messageformat-parser' => [
        'version' => '2.11.4',
    ],
    '@formatjs/icu-skeleton-parser' => [
        'version' => '1.8.16',
    ],
    '@symfony/ux-translator' => [
        'path' => '@symfony/ux-translator/translator_controller.js',
    ],
    '@symfony/ux-live-component' => [
        'path' => '@symfony/ux-live-component/live_controller.js',
    ],
    '@hotwired/turbo' => [
        'version' => '8.0.23',
    ],
    'leaflet' => [
        'version' => '1.9.4',
    ],
    'leaflet/dist/leaflet.css' => [
        'version' => '1.9.4',
        'type' => 'css',
    ],
    'leaflet/dist/leaflet.min.css' => [
        'version' => '1.9.4',
        'type' => 'css',
    ],
];
