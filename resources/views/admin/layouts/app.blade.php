{{--
    The package's own admin layout.

    The chrome the staff-facing screens render inside when cms-framework is not
    installed. It is deliberately spare: the package ships no CSS build, so a
    standalone host is expected to publish this view and replace it with its own,
    exactly as cms-framework's `cms::admin.layouts.app` is meant to be. Publish it
    with

        php artisan vendor:publish --tag=bookings-views

    which writes to `resources/views/vendor/bookings/`, where Laravel resolves it
    ahead of this file.

    The section and stack contract matches cms-framework's admin layout on
    purpose — `title` (escaped, plain text) and `content` (markup), plus `styles`
    and `scripts` stacks — so a page extending whichever layout the installation
    provides needs no branch of its own.

    @package    ArtisanPack_UI
    @subpackage Bookings

    @since      1.0.0
--}}
@php
    use ArtisanPackUI\Bookings\Support\AdminNav;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace( '_', '-', app()->getLocale() ) }}">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>@yield( 'title', __( 'Bookings' ) ) &middot; {{ config( 'app.name' ) }}</title>
	<style>
		:root { color-scheme: light dark; }
		body { margin: 0; font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; line-height: 1.5; }
		.bookings-admin { display: flex; min-height: 100vh; align-items: stretch; }
		.bookings-admin__sidebar { flex: 0 0 16rem; padding: 1.5rem 1rem; border-right: 1px solid rgba(127, 127, 127, .3); }
		.bookings-admin__brand { display: block; margin-bottom: 1.5rem; font-weight: 700; text-decoration: none; color: inherit; }
		.bookings-admin__menu { margin: 0; padding: 0; list-style: none; }
		.bookings-admin__menu a { display: block; padding: .4rem .6rem; border-radius: .375rem; text-decoration: none; color: inherit; }
		.bookings-admin__menu a[aria-current="page"] { background: rgba(127, 127, 127, .18); font-weight: 600; }
		.bookings-admin__main { flex: 1 1 auto; min-width: 0; padding: 1.5rem 2rem; }
	</style>
	@livewireStyles
	@stack( 'styles' )
</head>
<body>
	<div class="bookings-admin">
		<nav class="bookings-admin__sidebar" aria-label="{{ __( 'Bookings admin' ) }}">
			<a href="{{ AdminNav::url( 'artisanpack.bookings.admin.bookings' ) }}" class="bookings-admin__brand">
				{{ __( 'Bookings' ) }}
			</a>

			<ul class="bookings-admin__menu">
				@foreach ( AdminNav::items() as $item )
					<li wire:key="bookings-admin-nav-{{ $item['slug'] }}">
						<a
							href="{{ AdminNav::url( $item['route'] ) }}"
							@if ( request()->routeIs( $item['route'] ) ) aria-current="page" @endif
						>
							{{ $item['label'] }}
						</a>
					</li>
				@endforeach
			</ul>
		</nav>

		<main class="bookings-admin__main">
			<h1>@yield( 'title', __( 'Bookings' ) )</h1>

			@yield( 'content' )
		</main>
	</div>

	@livewireScripts
	@stack( 'scripts' )
</body>
</html>
