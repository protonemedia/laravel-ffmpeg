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

    /*
     * Opt in to replace credentials in exception diagnostics, process summaries and
     * commands, analyze() output and probeQuery() results:
     *
     * - the whole openUrl() URL with [url], and the whole RTSP URL with [rtsp];
     * - the whole query string with [query];
     * - a query value with [redacted] when its name contains token, sig, key, auth,
     *   pass, secret, credential or policy, or when it has 16 or more characters;
     * - the RTSP user:password pair and the password alone with [redacted].
     *
     * Shorter query values with other names, such as dl=1, and RTSP usernames are
     * kept because they also occur inside numbers and metadata. Matching is a plain
     * string replacement, longest first. Arguments passed to FFmpeg never change.
     */
    'redact_credentials' => env('FFMPEG_REDACT_CREDENTIALS', false),

    'temporary_files_root' => env('FFMPEG_TEMPORARY_FILES_ROOT', sys_get_temp_dir()),
];
