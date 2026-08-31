---
title: Multi-Site
---

# Multi-Site

Site scoping is configured once for the whole ecosystem, in `artisanpack.core.multi_tenant` — **not** in this package's config. Set `ARTISANPACK_MULTI_TENANT_ENABLED=true` (or the `enabled` key) to switch it on, and list resolvers under `artisanpack.core.multi_tenant.resolvers`.

Every owned table carries a nullable `site_id`, and models using `Models\Concerns\BelongsToSite` filter on whatever `ArtisanPackUI\Core\MultiTenancy\SiteContext` reports — so a request cannot be site 2 for one ArtisanPack package while being site 1 for this one.

## Pinning a site

Work that has to target or span a specific site pins one explicitly, which is what a console command looping over sites needs:

```php
use ArtisanPackUI\Core\Facades\ArtisanPackSite;

ArtisanPackSite::forSite( $siteId, fn () => /* every bookings query answers for $siteId */ );
ArtisanPackSite::withoutSite( fn () => /* unscoped, for maintenance work */ );
```

`acrossAllSites()` sees rows in every site, including those written before scoping was enabled.

## Enabling on an existing installation

Enabling scoping on an installation that already holds bookings needs `site_id` backfilled first: rows written while it was off carry a null `site_id`, and the scope matches on equality, so they leave every site-scoped query the moment a site resolves. `acrossAllSites()` still sees them.

Backfill before you switch scoping on, with `bookings:backfill-site-id`:

```bash
php artisan bookings:backfill-site-id --site=1 --dry-run   # preview the per-table counts
php artisan bookings:backfill-site-id --site=1             # stamp the rows
```

`--site` is the identifier every pre-scoping row belongs to — on a single-tenant installation becoming site 1, that is `1`. The command walks each of the seven tables a site owns directly, spans every site with `withoutGlobalScope()` so nothing already scoped is missed, and reaches soft-deleted rows so a booking pruned for retention is stamped too. Only rows whose `site_id` is null are touched; a row already carrying a site is left as it is, so a re-run is safe. `--dry-run` reports the counts and writes nothing, and an out-of-range or missing `--site` is refused rather than guessed. Once every count reads zero, switch `artisanpack.core.multi_tenant.enabled` on.

## Interactions to know

- **Series edits** run pinned to the series' own site, not the ambient one — see [Recurring Bookings](Usage-Recurring-Bookings).
- **Manage / iCal tokens** answer 404 for a token belonging to another site, with the same message as an unknown token — see [Manage Tokens](Usage-Manage-Tokens).
- **Webhook dispatch** left without a site id is scoped to the site in context, which in console is none — and therefore all. Pass the site to `WebhookDispatcher::dispatch()` when you know it. See [Webhooks](Notifications-Webhooks).

## Site resolution is a core contract

Site resolution is not a bookings contract — it is `ArtisanPackUI\Core\Contracts\SiteResolver`, bound once for the whole ecosystem. With cms-framework installed, `artisanpack-ui/core` applies its `ap.cmsFramework.currentSite.resolve` on the package's behalf. See [CMS Framework](Integrations-Cms-Framework).
