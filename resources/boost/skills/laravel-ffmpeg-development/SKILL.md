---
name: laravel-ffmpeg-development
description: Application integration guidance for protonemedia/laravel-ffmpeg.
license: MIT
metadata:
  author: ProtoneMedia
  source: https://github.com/protonemedia/laravel-ffmpeg
---

# Laravel FFMPEG

Guidance for **application developers** using `protonemedia/laravel-ffmpeg` in a Laravel app.

## When to Activate

- You’re adding this package to an app, wiring it into routes/controllers/jobs/commands, or writing tests that use it.
- You’re debugging runtime behaviour coming from this package (configuration, environment requirements, expected outputs).

## Scope

- Focus on **how to use the package’s public API** from a Laravel application.
- Prefer patterns shown in the README and reference doc.

## Do

- Follow the package’s documented configuration steps (publishing config, env vars, middleware, etc.).
- Provide copy-pastable examples that compile in a typical Laravel project.
- Call out common pitfalls (permissions, queueing, test fakes, disk configuration) when relevant.

## Don’t

- Don’t suggest changing this package’s internal source code unless the user explicitly says they are contributing to the package.
- Don’t invent undocumented methods/options; stick to the README/reference.

## Reference

- references/laravel-ffmpeg-guide.md
