# laravel-ffmpeg-development

## When to activate
- You are modifying code in **laravel-ffmpeg**.
- You are reviewing/triaging issues or PRs for **laravel-ffmpeg**.
- You are preparing a release (version bump, tag, changelog).

## Aim
Ship safe improvements while preserving the documented public API and keeping the test suite green.

## Do
- Keep PRs small and focused.
- Treat documented behavior (README) as the supported contract.
- Add or update tests when behavior changes.
- Prefer Laravel conventions (container bindings, facades, config publishing, events).

## Don’t
- Introduce breaking changes without clearly documenting them.
- Add new runtime dependencies without strong justification.
- Bake in app-specific assumptions; keep the package reusable.

## Quick workflow
- Install dependencies: `composer install`
- Run tests: `composer test` (or the repository’s configured test command)
