# 9.x implementation status and acceptance contract

This file tracks the Laravel-native rewrite. The [design proposal](https://ampcode.com/artifacts/9-x-rewrite-feature-contract-and-laravel-native-api-640fbf41-d6df-45af-b75a-249a8018ba15) defines the target API and priorities.

## Current implementation

`FFMpeg::disk($disk)->open($key)` returns a media descriptor with native `probe()` and `export()` operations through Laravel Process. A disk can be a name or an `Illuminate\Contracts\Filesystem\Filesystem` instance. `open()` also accepts `Illuminate\Http\File` and `UploadedFile`. Creating a descriptor or inspecting an export performs no transfers or process launches.

Local disks and file objects use their local paths. Other filesystem instances stream into a private workspace with transfer-size checks. Known remote manifests are rejected because staging a playlist alone loses its dependencies. This detection is not a sandbox for hostile media.

Probing requires FFprobe 9.0.x. It reads format, streams, and chapters together. `Metadata` and `Stream` retain raw fields through `get()` and `toArray()`. Duration is nullable, frame rates retain their rational source values, and the default video accessor excludes attached pictures. Width and height are encoded dimensions, not rotation-adjusted display dimensions.

The process boundary clears inherited application environment variables, limits JSON capture to 16 MiB, and retains only the last 64 KiB of stderr. Internal callbacks drain both streams without Symfony retaining a second copy. `laravel-ffmpeg.timeout` sets the total deadline. Optional `laravel-ffmpeg.idle_timeout` sets the silence deadline. Both use seconds. Failures expose a `MediaException` with `phase`, `category`, and bounded diagnostics, without a raw credential-bearing exception chain. Workspaces are removed after the child stops, including on failure.

The single-input, single-output export supports codecs, explicit-unit bitrates, CRF and preset settings, ordered stream maps, audio and video filter chains, metadata and chapter controls, and accurate or keyframe clipping. An explicit map replaces default selection. Selector-specific FFprobe queries validate maps against the staged asset without interpreting FFmpeg's selector grammar in PHP. Empty explicit selection fails rather than allowing FFmpeg to select tracks automatically.

All outputs, including local destinations, are generated in the workspace and published through Storage after a nonempty-file check. This preserves Storage write options and avoids truncating the source when input and destination keys match. Publication is not atomic. A failed upload can leave a partial object. Failures identify the destination and any object whose write returned success before verification failed.

`save()` returns generated-file records and bounded, redacted process summaries. Structured progress separates staging, probing, encoding, validation, and publication. Percentage, speed, and remaining time can be unknown. A callback can cancel by throwing `Exceptions\ExportCancelled`; other callback exceptions propagate unchanged as failures. Both stop and reap the child before workspace cleanup. Lifecycle events work without a progress callback, and completion follows publication and cleanup.

The old API and dependency remain temporarily while their replacements are built. The new `disk()` entry point does not invoke the old driver or fall back to it. URL inputs, display-geometry helpers, two-pass encoding, composition, and the other workflows below remain open. The completed release must remove the old implementation and dependency together.

### Current export API

```php
$result = FFMpeg::disk('uploads')
	->open('original.mov', inputOptions: ['-probesize', '2000000'])
	->export()
	->video('libx264', crf: 23, preset: 'medium')
	->audio('aac', bitrate: '128k')
	->clip(12.5, duration: 8)
	->toDisk('videos', ['visibility' => 'private', 'ContentType' => 'video/mp4'])
	->save('converted.mp4');
```

Input options, `globalOptions()`, and `outputOptions()` accept flat option/value pairs from a closed allowlist. Every allowed option has library-defined arity and value validation. Unknown options, aliases, switches with caller-supplied values, duplicate nonrepeatable options, filename controls, authentication headers, and encoder parameter blobs are rejected. Output `-f` permits only listed single-file muxers. These methods are not unrestricted argv passthrough.

Filters are developer-trusted FFmpeg expressions, not end-user text. They can read local resources or load plugins. The current filters API does not stage fonts, subtitles, LUTs, or other secondary resources. A per-input `-protocol_whitelist file` prevents direct network protocols, but does not isolate local file access or make uploaded media safe. Untrusted media still requires process isolation and resource limits. A demuxer allowlist remains under investigation and is not implemented in this checkpoint.

## Acceptance scenarios

Every row is required for 9.0. Existing tests are executable evidence of the old workflow, not proof that the new implementation preserves it. A row remains open until its Laravel-native acceptance scenarios pass. PHPUnit discovery can be inspected with `vendor/bin/phpunit --list-tests`.

| Contract | Required acceptance scenario | Existing evidence or new test | Native status |
| --- | --- | --- | --- |
| Disk inputs and probing | Probe a nested key, supplied filesystem, local file, and upload. Retain source bytes and disk context. | `NativeProbeTest` | Implemented |
| Private staging | Transfer a non-local input once per execution. Check permissions, truncation, failed reads, and cleanup. Reject unsupported manifest dependencies. | `NativeProbeTest`, `NativeExportTest` | Shared by probe and single-output export |
| Metadata | Preserve chapters, tags, rational rates, null duration, color fields, all streams, and separate cover art from motion video. | `NativeProbeTest` | Implemented for raw data and basic accessors |
| Process capture | Drain a noisy child, cap metadata output, remove application secrets, terminate on total and idle deadlines, and reap before cleanup. | `NativeProbeTest`, `NativeExportTest` | Shared process boundary; real callback cancellation verified |
| Network inputs | Read explicit public URLs. Stage authenticated finite downloads through Http. Keep per-input headers separate and redact signed URLs. | `MediaOpenerTest`, `ExportTest` | Open |
| Scoped and read-only filesystems | Preserve prefixes and wrappers. Never write through a read-only adapter or escape a destination root. | `DiskTest`, new wrapper scenarios required | Open |
| Ordinary encoding | Encode audio and video to different disks. Verify codecs, duration, channels, geometry, and write options. | `NativeExportTest`, `ExportTest` | Basic CRF encode and Storage publication verified; broader media coverage open |
| Independent operations | Start two exports from the same media descriptor without inheriting settings or temporary files. | `NativeExportTest`, `ExportTest`, `MediaOpenerTest` | Single-output builders and repeated real exports verified |
| Stream selection | Use multilingual audio, forced subtitles, cover art, and chapters. Verify ordered, optional, and negative maps and resulting dispositions. | `NativeExportTest`, `HlsExportTest` | Language selection, negative maps, order, dispositions, and absent optional maps verified; subtitle/artwork exports open |
| Metadata preservation | Copy global tags. Preserve chapters only for an unchanged timeline unless explicitly transformed. Test remove and override controls. | `NativeExportTest` | Copy, override, and clip chapter omission verified; broader removal cases open |
| Quality and passes | Compare CRF and target-bitrate contracts. Reject conflicts. Prove explicit two-pass encoding with isolated pass logs. | `NativeExportTest`, `ExportTest`, README pass-progress contract | CRF and conflict checks verified; two-pass open |
| Copy and clipping | Remux without changing codecs. Distinguish accurate transcoding from keyframe copy cuts. Preserve audio offsets and VFR. | `NativeExportTest`, `CopyTest` | Remux, accurate duration, and decoded keyframe identity verified; VFR and offset coverage open |
| Filters and resources | Apply ordered audio and video filters with punctuation, Unicode, fonts, and staged subtitles. Reject filters on copied streams. | `NativeExportTest`, `AddFilterTest`, `LegacyFilterMappingTest`, `ExportTest` | Ordered simple filters and copy conflicts implemented; secondary resources open |
| Multiple inputs and outputs | Compose mixed-disk inputs and map separate audio and video to different output disks. Verify stream identity and common-filter branching. | `ExportTest` | Open |
| Concatenation | Copy compatible inputs and report mismatches. Normalize heterogeneous inputs only under an explicit geometry, timing, and audio policy. | `ExportTest` | Open |
| Frames | Seek to a selected timestamp. Return image bytes and save an image with verified dimensions and content. Fail when no frame exists. | `FrameTest` | Open |
| Image sequences and timelapse | Read a numbered image sequence at a declared input rate and verify output timing. | `ExportTest` | Open |
| Storyboards and VTT | Verify tile order, dimensions, quality, margin, padding, and cue coordinates. Use different VTT and image directories. | `TileTest`, `VTTPreviewThumbnailsGeneratorTest` | Open |
| Waveform images | Generate and inspect an actual waveform image from audio. | `MediaOpenerTest` only checks the old object type. New rendered-output acceptance required. | Open |
| Watermarks | Apply local and staged remote images, offsets, scaling, and optional image preprocessing to ordinary and HLS output. | `WatermarkTest`, `WatermarkFactoryTest`, `HlsExportTest` | Open |
| Analysis | Inspect volume-analysis output and nonzero status without requiring a media artifact. | `VolumeDetectTest` | Open |
| Progress and events | Parse fragmented protocol records. Handle unknown duration and multiple passes. Stop on callback failure. Complete only after validation and publication. | `NativeExportTest`, `ExportTest` | Single-pass lifecycle verified, including real child reaping; multiple passes open |
| Results and publication | Validate nonempty expected artifacts before remote writes. Stream files, handle false Storage returns, report partial publication, and clean only owned temporary files. | `NativeExportTest`, `ExportTest`, `TileTest` | Single-file validation and publication verified; multi-file inventory open |
| Adaptive HLS | Produce multiple MPEG-TS variants, audio-only media with artwork, silent video, first or all audio, copy output, and custom nested paths. Verify every playlist reference. | `HlsExportTest`, `HLSPlaylistGeneratorTest` | Open |
| Encrypted HLS | Decrypt AES-128 segments and verify IVs, supplied keys, key rotation boundaries, private key storage, and failure cleanup. | `EncryptedHlsExportTest` | Open |
| Dynamic HLS | Rewrite master, variant, segment, and key URLs relative to their containing playlists. Verify response integration and missing or invalid input errors. | `DynamicHLSPlaylistTest` | Open |
| Extension points | Inspect scoped argument arrays without execution. Preserve custom filters, playlist hooks, and output configuration without arbitrary driver forwarding. | `NativeExportTest`, `BeforeSavingTest`, README | Pure inspection and allowlisted scoped options implemented; graphs and playlist hooks open |

## Compiler contracts

- An input's options precede its `-i`. Output maps, codecs, filters, metadata, and container options stay with that output. Only explicitly repeatable options may repeat.
- Without explicit maps, ordinary single-input exports select the first motion-video and audio streams when present. Audio-only and silent video are valid. Explicit maps replace these defaults and preserve declaration order.
- `map('0:a:0?')` can omit a missing stream but cannot conceal an invalid input index. Negative maps remove previously selected streams. Per-stream output options address the resulting output order.
- `video('libx264', crf: 23, preset: 'medium')` and `video('libx264', bitrate: '2500k')` are alternative high-level rate-control choices. Supplying both fails before launch. A normal encode has one pass.
- `clip(start: 12.5, duration: 8, seek: 'accurate')` selects the presentation interval beginning at 12.5 seconds and ending before 20.5 seconds. Copying an affected stream requires explicit keyframe mode. It never silently transcodes.
- Timeline changes omit chapters unless an explicit transform or mapping makes them valid. Multi-input composition requires an explicit metadata source.
- Fonts, logos, LUTs, subtitle files, and literal-text files belong to the execution workspace. Literal text, paths, and FFmpeg expressions require different escaping. A graph compiles to one argument value, not a shell fragment.
- Compilation and inspection allocate no workspace, download nothing, and do not mutate a pending builder. Scoped options cannot replace reserved filenames, binary selection, progress transport, or pass-log paths.

The single-output subset has compiler, process-fake, and real-media tests in `NativeExportTest`. Multi-input graphs, secondary resources, and multiple outputs are not implemented.

## Remaining sequence

1. Extend the verified single-output compiler and execution contract to secondary resources, multiple inputs, and multiple outputs. Preserve progress, failure cleanup, and publication guarantees across those extensions.
2. Preserve ordinary encoding, composition, concatenation, frames, timelapse, watermarks, waveform images, storyboards, and VTT.
3. Preserve adaptive and encrypted HLS and dynamic playlist responses against the same execution contract.
4. Replace the README and upgrade guide, then remove the legacy driver and dependency tree. No parallel legacy backend remains in the released package.
5. Pass every acceptance row on PHP 8.4 and 8.5, Laravel 12 and 13, and matching tested FFmpeg and FFprobe builds. Pin setup and CI to the tested stable patch. Measure performance before making speed claims.

Optional follow-ups remain ordered as loudness normalization, fMP4 HLS and alternate groups, then waveform-peaks data. They do not replace an open 9.0 acceptance row.

## Test commands

`vendor/bin/phpunit tests/NativeProbeTest.php tests/NativeExportTest.php` runs the native process and Storage contract tests. Real-media cases require `FFMPEG_9_FFPROBE` to identify an installed FFprobe 9.0.x binary and report skips when that variable is absent. Export acceptance uses a matching `ffmpeg` binary in the same directory, built with libx264. This is temporary while the legacy suite still uses the distribution build.

`FFMPEG_9_FFPROBE=/opt/ffmpeg-9.0.2/bin/ffprobe vendor/bin/phpunit` runs both suites when that binary is installed. Native acceptance uses FFmpeg and FFprobe 9.0.2. Legacy encoding tests still use the separately configured legacy binaries. Passing legacy tests does not establish native support for their workflows. Pinning the matching native build in setup and CI remains a release gate.
