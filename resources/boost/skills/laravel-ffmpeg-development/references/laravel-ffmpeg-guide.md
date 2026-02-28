# laravel-ffmpeg development guide

For full documentation, see the README: https://github.com/protonemedia/laravel-ffmpeg#readme

## At a glance
This package integrates **FFmpeg** into Laravel using Laravel's filesystem disks.

## Local setup
- Install dependencies: `composer install`
- Keep the dev loop package-focused (avoid adding app-only scaffolding).

## Testing
- Run: `composer test` (preferred) or the repository’s configured test runner.
- Add regression tests for bug fixes.

## Notes & conventions
- Requires FFmpeg/FFprobe binaries in CI/dev; avoid tests that depend on local machine state.
- Be careful with disk configuration and path handling (local vs cloud).
- Prefer integration tests around the public fluent API (facades/builders) instead of internal classes.
