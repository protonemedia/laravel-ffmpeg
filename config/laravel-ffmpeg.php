<?php

return [
    'default_disk' => 'local',

    'ffmpeg.binaries' => env('FFMPEG_BINARIES','ffmpeg'),
    'ffmpeg.threads'  => 12,

    'ffprobe.binaries' => env('FFPROBE_BINARIES','ffprobe'),

    'timeout' => 3600,
];
