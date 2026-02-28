---
name: laravel-ffmpeg-development
description: Application integration guidance for protonemedia/laravel-ffmpeg.
license: MIT
metadata:
  author: ProtoneMedia
---

# Laravel FFMPEG Development

## Overview
Use `protonemedia/laravel-ffmpeg.` in a Laravel application.

## When to Activate
- Activate when adding, configuring, or using this package in application code (controllers, jobs, commands, tests, config, routes, Blade, etc.).
- Activate when code references `protonemedia/laravel-ffmpeg.` classes, facades, config, or documented features.

## Scope
- In scope: documented public API usage, configuration, testing patterns, and common integration recipes.
- Out of scope: modifying this package’s internal source code unless the user explicitly says they are contributing to the package.

## Workflow
1. Identify the task (install/setup, configuration, feature usage, debugging, tests, etc.).
2. Read `references/laravel-ffmpeg-guide.md` and focus on the relevant section.
3. Apply the documented patterns and keep examples minimal and Laravel-native.

## Core Concepts
- Prefer the patterns shown in the full documentation and reference.
- Keep examples copy-pastable and aligned with typical Laravel conventions.

## Do and Don't

Do:
- Follow the package’s documented installation and configuration steps.
- Provide examples that compile in a typical Laravel project.
- Call out relevant pitfalls (configuration, queues, filesystem, permissions, testing) when applicable.

Don't:
- Don't invent undocumented methods/options; stick to the docs and reference.
- Don't suggest changing package internals unless the user explicitly wants to contribute upstream.

## References
- `references/laravel-ffmpeg-guide.md`
