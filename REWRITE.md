# 9.x implementation status and acceptance contract

This file tracks the Laravel-native rewrite. The [design proposal](https://ampcode.com/artifacts/9-x-rewrite-feature-contract-and-laravel-native-api-640fbf41-d6df-45af-b75a-249a8018ba15) defines the target API and priorities.

## Current implementation

The first increment implements `FFMpeg::disk($disk)->open($key)->probe()` through Laravel Process. A disk can be a name or an `Illuminate\Contracts\Filesystem\Filesystem` instance. `open()` also accepts `Illuminate\Http\File` and `UploadedFile`. Creating a media descriptor performs no transfers or process launches.

Local disks and file objects use their local paths. Other filesystem instances stream into a private workspace with transfer-size checks. Known remote manifests are rejected because staging a playlist alone loses its dependencies. This detection is not a sandbox for hostile media.

Probing requires FFprobe 9.0.x. It reads format, streams, and chapters together. `Metadata` and `Stream` retain raw fields through `get()` and `toArray()`. Duration is nullable, frame rates retain their rational source values, and the default video accessor excludes attached pictures. Width and height are encoded dimensions, not rotation-adjusted display dimensions.

The process boundary clears inherited application environment variables, limits JSON capture to 16 MiB, and retains only the last 64 KiB of stderr. Internal callbacks drain both streams without Symfony retaining a second copy. `laravel-ffmpeg.timeout` sets the total deadline. Optional `laravel-ffmpeg.idle_timeout` sets the silence deadline. Both use seconds. Failures expose a `MediaException` with `phase`, `category`, and bounded diagnostics, without a raw credential-bearing exception chain. Workspaces are removed after the child stops, including on failure.

The old API and dependency remain temporarily while their replacements are built. The new `disk()` entry point does not invoke the old driver. Export builders, URL inputs, input options, display geometry, and encoding are not implemented on the new descriptor yet. There is no legacy fallback from the new probe path. The completed release must remove the old implementation and dependency together.

## Acceptance scenarios

Every row is required for 9.0. Existing tests are executable evidence of the old workflow, not proof that the new implementation preserves it. A row remains open until its Laravel-native acceptance scenarios pass. PHPUnit discovery can be inspected with `vendor/bin/phpunit --list-tests`.

| Contract | Required acceptance scenario | Existing evidence or new test | Native status |
| --- | --- | --- | --- |
| Disk inputs and probing | Probe a nested key, supplied filesystem, local file, and upload. Retain source bytes and disk context. | `NativeProbeTest` | Implemented |
| Private staging | Transfer a non-local input once per execution. Check permissions, truncation, failed reads, and cleanup. Reject unsupported manifest dependencies. | `NativeProbeTest` | Implemented for one probe |
| Metadata | Preserve chapters, tags, rational rates, null duration, color fields, all streams, and separate cover art from motion video. | `NativeProbeTest` | Implemented for raw data and basic accessors |
| Process capture | Drain a noisy child, cap metadata output, remove application secrets, terminate on total and idle deadlines, and reap before cleanup. | `NativeProbeTest` | Implemented for probing |
| Network inputs | Read explicit public URLs. Stage authenticated finite downloads through Http. Keep per-input headers separate and redact signed URLs. | `MediaOpenerTest`, `ExportTest` | Open |
| Scoped and read-only filesystems | Preserve prefixes and wrappers. Never write through a read-only adapter or escape a destination root. | `DiskTest`, new wrapper scenarios required | Open |
| Ordinary encoding | Encode audio and video to different disks. Verify codecs, duration, channels, geometry, and write options. | `ExportTest` | Open |
| Independent operations | Start two exports from the same media descriptor without inheriting settings or temporary files. | `ExportTest`, `MediaOpenerTest` | Open |
| Stream selection | Use multilingual audio, forced subtitles, cover art, and chapters. Verify ordered, optional, and negative maps and resulting dispositions. | `HlsExportTest`, new multilingual fixture required | Open |
| Metadata preservation | Copy global tags. Preserve chapters only for an unchanged timeline unless explicitly transformed. Test remove and override controls. | New acceptance fixture required | Open |
| Quality and passes | Compare CRF and target-bitrate contracts. Reject conflicts. Prove explicit two-pass encoding with isolated pass logs. | `ExportTest`, README pass-progress contract | Open |
| Copy and clipping | Remux without changing codecs. Distinguish accurate transcoding from keyframe copy cuts. Preserve audio offsets and VFR. | `CopyTest`, new timing fixture required | Open |
| Filters and resources | Apply ordered audio and video filters with punctuation, Unicode, fonts, and staged subtitles. Reject filters on copied streams. | `AddFilterTest`, `LegacyFilterMappingTest`, `ExportTest` | Open |
| Multiple inputs and outputs | Compose mixed-disk inputs and map separate audio and video to different output disks. Verify stream identity and common-filter branching. | `ExportTest` | Open |
| Concatenation | Copy compatible inputs and report mismatches. Normalize heterogeneous inputs only under an explicit geometry, timing, and audio policy. | `ExportTest` | Open |
| Frames | Seek to a selected timestamp. Return image bytes and save an image with verified dimensions and content. Fail when no frame exists. | `FrameTest` | Open |
| Image sequences and timelapse | Read a numbered image sequence at a declared input rate and verify output timing. | `ExportTest` | Open |
| Storyboards and VTT | Verify tile order, dimensions, quality, margin, padding, and cue coordinates. Use different VTT and image directories. | `TileTest`, `VTTPreviewThumbnailsGeneratorTest` | Open |
| Waveform images | Generate and inspect an actual waveform image from audio. | `MediaOpenerTest` only checks the old object type. New rendered-output acceptance required. | Open |
| Watermarks | Apply local and staged remote images, offsets, scaling, and optional image preprocessing to ordinary and HLS output. | `WatermarkTest`, `WatermarkFactoryTest`, `HlsExportTest` | Open |
| Analysis | Inspect volume-analysis output and nonzero status without requiring a media artifact. | `VolumeDetectTest` | Open |
| Progress and events | Parse fragmented protocol records. Handle unknown duration and multiple passes. Stop on callback failure. Complete only after validation and publication. | `ExportTest`, new failure scenarios required | Open |
| Results and publication | Validate nonempty expected artifacts before remote writes. Stream files, handle false Storage returns, report partial publication, and clean only owned temporary files. | `ExportTest`, `TileTest`, new failure scenarios required | Open |
| Adaptive HLS | Produce multiple MPEG-TS variants, audio-only media with artwork, silent video, first or all audio, copy output, and custom nested paths. Verify every playlist reference. | `HlsExportTest`, `HLSPlaylistGeneratorTest` | Open |
| Encrypted HLS | Decrypt AES-128 segments and verify IVs, supplied keys, key rotation boundaries, private key storage, and failure cleanup. | `EncryptedHlsExportTest` | Open |
| Dynamic HLS | Rewrite master, variant, segment, and key URLs relative to their containing playlists. Verify response integration and missing or invalid input errors. | `DynamicHLSPlaylistTest` | Open |
| Extension points | Inspect scoped argument arrays without execution. Preserve custom filters, playlist hooks, and output configuration without arbitrary driver forwarding. | `BeforeSavingTest`, README | Open |

## Compiler contracts for the next increment

- An input's options precede its `-i`. Output maps, codecs, filters, metadata, and container options stay with that output. Flags may repeat.
- Without explicit maps, ordinary single-input exports select the first motion-video and audio streams when present. Audio-only and silent video are valid. Explicit maps replace these defaults and preserve declaration order.
- `map('0:a:0?')` can omit a missing stream but cannot conceal an invalid input index. Negative maps remove previously selected streams. Per-stream output options address the resulting output order.
- `video('libx264', crf: 23, preset: 'medium')` and `video('libx264', bitrate: '2500k')` are alternative high-level rate-control choices. Supplying both fails before launch. A normal encode has one pass.
- `clip(start: 12.5, duration: 8, seek: 'accurate')` selects the presentation interval beginning at 12.5 seconds and ending before 20.5 seconds. Copying an affected stream requires explicit keyframe mode. It never silently transcodes.
- Timeline changes omit chapters unless an explicit transform or mapping makes them valid. Multi-input composition requires an explicit metadata source.
- Fonts, logos, LUTs, subtitle files, and literal-text files belong to the execution workspace. Literal text, paths, and FFmpeg expressions require different escaping. A graph compiles to one argument value, not a shell fragment.
- Compilation and inspection allocate no workspace, download nothing, and do not mutate a pending builder. Raw options cannot replace reserved filenames, binary selection, progress transport, or pass-log paths.

These contracts require compiler tests and real media tests before the encoding implementation is considered complete.

## Remaining sequence

1. Implement the compiler contracts, output representation, progress parser, result records, validation, and remote publication. Extend the process tests to application callback failures before adding encoding convenience methods.
2. Preserve ordinary encoding, composition, concatenation, frames, timelapse, watermarks, waveform images, storyboards, and VTT.
3. Preserve adaptive and encrypted HLS and dynamic playlist responses against the same execution contract.
4. Replace the README and upgrade guide, then remove the legacy driver and dependency tree. No parallel legacy backend remains in the released package.
5. Pass every acceptance row on PHP 8.4 and 8.5, Laravel 12 and 13, and matching tested FFmpeg and FFprobe builds. Pin setup and CI to the tested stable patch. Measure performance before making speed claims.

Optional follow-ups remain ordered as loudness normalization, fMP4 HLS and alternate groups, then waveform-peaks data. They do not replace an open 9.0 acceptance row.

## Test commands

`vendor/bin/phpunit tests/NativeProbeTest.php` runs process and Storage contract tests. The real-media case requires `FFMPEG_9_FFPROBE` to identify an installed FFprobe 9.0.x binary and reports a skip when that variable is absent. This is temporary while the legacy suite still uses the distribution build.

`FFMPEG_9_FFPROBE=/opt/ffmpeg-9.0.2/bin/ffprobe vendor/bin/phpunit` runs both suites when that binary is installed. The native test uses FFprobe 9.0.2. Legacy encoding tests still use the separately configured legacy binaries. Passing those tests does not establish 9.x encoding support.
