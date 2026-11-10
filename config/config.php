<?php

return [

    /*
    |--------------------------------------------------------------------------
    | FFmpeg Binary
    |--------------------------------------------------------------------------
    |
    | Here you may specify the full path to the FFmpeg executable. Otherwise,
    | it is resolved through the PATH, which may differ between your shell,
    | the web server and queue workers. It must report a 9.0.x release.
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
    | the binaries must report the same 9.0.x release, so make sure this
    | executable belongs to the same build as the FFmpeg binary above.
    |
    */

    'ffprobe' => [
        'binaries' => env('FFPROBE_BINARIES', 'ffprobe'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Process Timeout
    |--------------------------------------------------------------------------
    |
    | This value is the total deadline, in seconds, of each FFmpeg or FFprobe
    | process. An operation's timeout method may set its own budget, but it
    | still applies, so the first limit that is reached wins. Set it to
    | null to remove the deadline from the processes entirely.
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
    | QueuedExportCompleted. Callbacks, cancellation, publication and queue
    | chains still run, so keep the events enabled if your listeners keep
    | the completion inventories that your application relies upon.
    |
    */

    'enable_events' => env('FFMPEG_ENABLE_EVENTS', true),

    /*
    |--------------------------------------------------------------------------
    | Credential Redaction
    |--------------------------------------------------------------------------
    |
    | This option is disabled by default. When enabled, credentials will be
    | replaced in exception diagnostics, process summaries and commands,
    | analyze() output, and the probe() and probeQuery() results. The
    | arguments passed to FFmpeg itself never change.
    |
    | The entire openUrl() URL becomes [url], the whole RTSP URL [rtsp], and
    | the whole query string [query]. A query value becomes [redacted] when
    | its name contains token, sig, key, auth, pass, secret, credential or
    | policy, or when it has 16 or more characters, and so do the RTSP
    | user:password pair and the password alone.
    |
    | Shorter query values with other names, such as dl=1, and RTSP usernames
    | are kept, since they also occur inside numbers and metadata. Matching
    | is a plain string replacement, applied longest first.
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
    | by your web server and queue worker users. When it is not set, the
    | system's temporary directory will be used instead.
    |
    */

    'temporary_files_root' => env('FFMPEG_TEMPORARY_FILES_ROOT', sys_get_temp_dir()),

];
