<?php

return [
    'ffmpeg' => [
        'binaries' => env('FFMPEG_BINARIES', 'ffmpeg'),

        'threads' => 12,   // set to false to omit the default -threads option
    ],

    'ffprobe' => [
        'binaries' => env('FFPROBE_BINARIES', 'ffprobe'),
    ],

    'timeout' => 3600,

    'idle_timeout' => null,

    'queue' => [
        'connection' => null,
        'queue' => null,
        'tries' => 1,
        'backoff' => 0,
        // null derives the worker timeout from the operation or process limit.
        'timeout' => null,
    ],

    'enable_events' => env('FFMPEG_ENABLE_EVENTS', true),

    'temporary_files_root' => env('FFMPEG_TEMPORARY_FILES_ROOT', sys_get_temp_dir()),
];
