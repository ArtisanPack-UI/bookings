---
title: Media Library
---

# Media Library

When `artisanpack-ui/media-library` is installed, services and providers can carry an image chosen from the media library rather than only a raw URL.

## The image fields

Both `Models\Service` and `Models\ServiceProvider` carry two image columns:

| Column | Meaning |
| --- | --- |
| `image_media_id` | A reference into the media library, used when the package is installed |
| `image_url` | A plain URL fallback, used when it is not |

With media-library installed, the admin editors let a staff member pick an image through the media modal and store its id in `image_media_id`. Without it, set `image_url` to any URL and the widget and admin screens use that.

```php
use ArtisanPackUI\Bookings\Models\Service;

// With media-library
$service->update( [ 'image_media_id' => $media->id ] );

// Without it
$service->update( [ 'image_url' => 'https://cdn.example.test/discovery-call.jpg' ] );
```

Resolving `image_media_id` to a URL goes through the media library's own helpers, so image sizes, WebP/AVIF conversion, and alt text are handled there. See the [media-library documentation](https://github.com/ArtisanPack-UI/media-library) for image sizing and management.
