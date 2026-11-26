<?php

return [

    /*
    |--------------------------------------------------------------------------
    | FFmpeg Binary
    |--------------------------------------------------------------------------
    |
    | Here you may specify the full path to the FFmpeg executable. Otherwise,
    | it is resolved through the PATH, which may differ between your shell,
    | the web server and queue workers. It must report release 9.0 or later.
    |
    */

    'ffmpeg' => [
        'binaries' => env('FFMPEG_BINARIES', 'ffmpeg'),

        // Set to false to omit the default -threads option.
        'threads' => 12,
    ],

    /*
    |--------------------------------------------------------------------------
    | FFprobe Binary
    |--------------------------------------------------------------------------
    |
    | Here you may specify the full path to the FFprobe executable. Both of
    | the binaries must report the same release, so make sure that this
    | executable belongs to the same build as the FFmpeg binary above.
    |
    */

    'ffprobe' => [
        'binaries' => env('FFPROBE_BINARIES', 'ffprobe'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Version Check
    |--------------------------------------------------------------------------
    |
    | Before using the binaries, the package verifies that both report the
    | same release, 9.0 or later. Setting this option to false skips that
    | check, but only the releases that it would accept are supported.
    |
    */

    'verify_version' => env('FFMPEG_VERIFY_VERSION', true),

    /*
    |--------------------------------------------------------------------------
    | Option Restrictions
    |--------------------------------------------------------------------------
    |
    | Raw input, output, and global options must be on the allow list of the
    | package. Setting this option to false passes the others to FFmpeg as
    | is, except for those the package sets itself, such as -i and -map.
    |
    */

    'restrict_options' => true,

    /*
    |--------------------------------------------------------------------------
    | Process Timeout
    |--------------------------------------------------------------------------
    |
    | This value is the total deadline, in seconds, of each FFmpeg or FFprobe
    | process. The timeout method may set a budget per operation, but the
    | first limit reached wins. Setting it to null removes the deadline.
    |
    */

    'timeout' => 3600,

    /*
    |--------------------------------------------------------------------------
    | Process Idle Timeout
    |--------------------------------------------------------------------------
    |
    | This value limits how long, in seconds, a child process may run without
    | producing any output. It is separate from the total deadline above and
    | is disabled when null. The idleTimeout method may lower it further.
    |
    */

    'idle_timeout' => null,

    /*
    |--------------------------------------------------------------------------
    | Queued Conversions
    |--------------------------------------------------------------------------
    |
    | These options are the defaults for exports that run on a queue worker.
    | Arguments given to the queued method override them, and the callback
    | given to configureJob runs last, so it may change any job setting.
    |
    */

    'queue' => [
        'connection' => null,
        'queue' => null,
        'tries' => 1,
        'backoff' => 0,
        // null derives the worker timeout from the operation or process limit.
        'timeout' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Events
    |--------------------------------------------------------------------------
    |
    | Setting this option to false suppresses every package event, including
    | QueuedExportCompleted. Callbacks, publication and queue chains still
    | run, so keep them enabled for the listeners your application needs.
    |
    */

    'enable_events' => env('FFMPEG_ENABLE_EVENTS', true),

    /*
    |--------------------------------------------------------------------------
    | Credential Redaction
    |--------------------------------------------------------------------------
    |
    | Here you may enable the redaction of signed URLs and other credentials
    | in diagnostics, process summaries, analyze() output and probe results.
    | The arguments that are given to FFmpeg itself will never be changed.
    |
    */

    'redact_credentials' => env('FFMPEG_REDACT_CREDENTIALS', false),

    /*
    |--------------------------------------------------------------------------
    | Temporary Files Root
    |--------------------------------------------------------------------------
    |
    | Each operation creates a private workspace within this directory where
    | it stages media for FFmpeg. The directory must exist and be writable
    | by the users that run your web server and your queue workers.
    |
    */

    'temporary_files_root' => env('FFMPEG_TEMPORARY_FILES_ROOT', sys_get_temp_dir()),

];
