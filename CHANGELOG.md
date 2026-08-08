# ArtisanPack UI Bookings Changelog

## [Unreleased]

### Added

- Package scaffold generated from `package-blueprint`, following `artisanpack-ui/rbac` for the config layout and `artisanpack-ui/pagespeed-insights` for the provider and test conventions.
- `composer.json` — `ArtisanPackUI\Bookings` PSR-4 namespace, `php ^8.2`, `illuminate/support|database|http|notifications ^10.0|^11.0|^12.0|^13.0`, and required `artisanpack-ui/core`, `artisanpack-ui/hooks`, `artisanpack-ui/security`, `artisanpack-ui/icons`, `nesbot/carbon`, `simshaun/recurr`, and `sabre/vobject` dependencies. `sabre/vobject` is a hard dependency rather than a suggestion because the outbound `.ics` feeds and the CalDAV driver both need RFC 5545 serialization we are not going to hand-roll.
- `suggest` entries for `artisanpack-ui/cms-framework`, `livewire-ui-components`, `forms`, `media-library`, `google`, `microsoft`, `apple`, and `accessibility`. Every one of these is soft: the package has to stay usable in a plain Laravel app, a headless API, or a widget embedded in a site that runs no ArtisanPack CMS at all.
- `Providers\BookingsServiceProvider`, auto-discovered by Laravel, with the `bookings` singleton binding.
- `config/artisanpack/bookings.php` covering timezone, slot interval, availability cache, booking window, cancellation, series, notifications, calendar sync, webhooks, admin and public surfaces, retention, and multi-tenancy. Published under the `bookings-config` tag to `config/artisanpack/bookings.php`, and merged under the matching `artisanpack.bookings` key — Laravel derives the config key from the published path, so a bare `bookings` key would have meant a published file the package never reads.
- Every calendar driver ships disabled and `calendar.default_sync_mode` is `outbound`. Two-way sync stays opt-in per connection because it hands an external calendar the power to suppress availability.
- Code style toolchain — `pint.json`, `.php-cs-fixer.dist.php`, and `phpcs.xml`, all three covering `config/` alongside `src/` and `tests/`, with `lint`, `fix`, `cs`, `cs:fix`, and `test` composer scripts. `pint.json` is generated from `artisanpack-ui/code-style-pint`'s `ArtisanPackUIPreset` with five Laravel-preset rules turned off: `phpdoc_no_package` and `no_superfluous_phpdoc_tags` strip the `@package`/`@subpackage`/`@param`/`@return` tags the house PHPDoc standard requires, and `declare_parentheses`, `trim_array_spaces`, and `no_spaces_around_offset` strip the WordPress-style spacing PHP-CS-Fixer's custom fixers add. Left on, Pint and PHP-CS-Fixer would have reformatted each other on alternate runs; `composer fix` now runs Pint first and settles the spacing with PHP-CS-Fixer, and both report clean on the result.
- Pest + Orchestra Testbench setup with a base `TestCase` and coverage of the boot, binding, facade, helper, publish-tag, and config-default paths.

### Changed

- `security` and `icons` are required at `^2.0`, not the `^1.0` written in plan §3.1. Both packages shipped a 2.x major after the plan was written, and `^1.0` would have pinned bookings to a superseded major — and failed to install alongside the rest of the ecosystem, which is already on 2.x.
