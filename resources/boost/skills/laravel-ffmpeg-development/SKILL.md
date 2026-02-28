---
name: laravel-ffmpeg-development
description: Development guidance for protonemedia/laravel-ffmpeg (FFmpeg facade + filesystem-backed media processing).
license: MIT
metadata:
  author: ProtoneMedia
  source: https://github.com/protonemedia/laravel-ffmpeg
---

# Laravel FFMpeg Development

Use this skill when changing code/docs/tests in `protonemedia/laravel-ffmpeg`.

## Workflow
1. Treat the README/wiki as the public API contract (facade chain, exporters, filters).
2. Consult `references/laravel-ffmpeg-guide.md` for common recipes (HLS, frames, tiles, multiple inputs, encryption).
3. Be careful with backwards compatibility: method chaining order and return types are part of the API.

## Reference
- references/laravel-ffmpeg-guide.md
