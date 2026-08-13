<?php

/**
 * Admin provider editor.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Livewire\Admin;

use ArtisanPackUI\Bookings\Models\Scopes\BelongsToSiteScope;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Creates a service provider or edits everything about one but their availability.
 *
 * The weekly hours are deliberately not here. A provider's identity — their
 * name, their timezone, the weight that tilts the round-robin toward them — is
 * edited in place: the row is loaded, changed, and saved over. Their
 * availability is a different shape of work, a set of weekly windows and
 * per-date exceptions, and editing the two through one save would make "save
 * the provider" quietly rewrite a schedule on every unrelated tweak. It lives
 * in {@see AvailabilityEditor} and this component links across to it.
 *
 * The timezone is never blank, because availability is authored as wall-clock
 * time against it: a provider without a zone has schedules that mean nothing. It
 * defaults to UTC and is chosen from the list of IANA names rather than typed.
 *
 * The image is a soft dependency on the media library. When that package is
 * installed a picker sets {@see self::$imageMediaId}; when it is not, a plain
 * URL still names an image, so neither the form nor a provider's avatar depends
 * on a package the host may not have.
 *
 * Site scoping is left to the models. A provider loaded here came through the
 * site-scoped query that {@see ProvidersIndex} lists from, and a save stamps the
 * site in context on create, so nothing on this component names a tenant — the
 * one thing a client could set to reach across to another.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class ProviderEditor extends Component
{
    /**
     * The context a media-library selection must carry to reach this component.
     *
     * The picker is shared, so a page can run more than one at once. The context
     * is how a selection says which field asked for it, and matching on it stops
     * an image chosen for something else from landing on the provider.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const MEDIA_CONTEXT = 'bookings-provider-image';

    /**
     * The provider being edited, or null while creating one.
     *
     * Locked: it is the row every save writes over, and a client that could
     * repoint it could edit a provider the administrator never opened —
     * including, if the id crossed a tenant boundary, one the site-scoped list
     * would never have shown them.
     *
     * @since 1.0.0
     *
     * @var int|null
     */
    #[Locked]
    public ?int $providerId = null;

    /**
     * The provider's public name.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $name = '';

    /**
     * The slug the provider is addressed by, unique within its site.
     *
     * Left blank, it is derived from the name on save.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $slug = '';

    /**
     * The provider's contact email, if any.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $email = '';

    /**
     * The provider's contact phone number, if any.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $phone = '';

    /**
     * The provider's public biography.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $bio = '';

    /**
     * The IANA timezone the provider's availability is authored against.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $timezone = 'UTC';

    /**
     * The media-library id of the provider's image, when that package is present.
     *
     * @since 1.0.0
     *
     * @var int|null
     */
    public ?int $imageMediaId = null;

    /**
     * A plain URL for the provider's image, used when there is no media library.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $imageUrl = '';

    /**
     * How heavily the round-robin assigner favours this provider.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public int $roundRobinWeight = 1;

    /**
     * Where the provider sits in the administrator's own ordering.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public int $sortOrder = 0;

    /**
     * Whether the provider can currently be assigned bookings.
     *
     * @since 1.0.0
     *
     * @var bool
     */
    public bool $isActive = true;

    /**
     * The provider's free-form metadata, as a JSON string.
     *
     * Held as text rather than an array so the administrator edits exactly what
     * is stored and a malformed document is caught by validation instead of
     * being silently dropped. Decoded to an array on save.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $metadataJson = '';

    /**
     * Loads the provider being edited, or leaves the form blank to create one.
     *
     * @since 1.0.0
     *
     * @param  int|null  $providerId  The provider to edit, or null to create one.
     *
     * @return void
     */
    public function mount( ?int $providerId = null ): void
    {
        if ( null === $providerId ) {
            return;
        }

        $provider = ServiceProvider::query()->findOrFail( $providerId );

        $this->providerId       = $provider->getKey();
        $this->name             = $provider->name;
        $this->slug             = $provider->slug;
        $this->email            = (string) $provider->email;
        $this->phone            = (string) $provider->phone;
        $this->bio              = (string) $provider->bio;
        $this->timezone         = $provider->timezone;
        $this->imageMediaId     = $provider->image_media_id;
        $this->imageUrl         = (string) $provider->image_url;
        $this->roundRobinWeight = $provider->round_robin_weight;
        $this->sortOrder        = $provider->sort_order;
        $this->isActive         = $provider->is_active;
        $this->metadataJson     = null === $provider->metadata
            ? ''
            : (string) json_encode( $provider->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
    }

    /**
     * Receives an image chosen from the media library.
     *
     * The listener is always registered, but only a selection carrying this
     * component's context is taken: the picker is shared across a page, and a
     * broadcast meant for another field would otherwise overwrite the provider's
     * image with it.
     *
     * @since 1.0.0
     *
     * @param  array<int, array<string, mixed>>  $media  The selected media items.
     * @param  string|null  $context  The context the selection was made for.
     *
     * @return void
     */
    #[On( 'media-selected' )]
    public function mediaSelected( array $media, ?string $context = null ): void
    {
        if ( self::MEDIA_CONTEXT !== $context || [] === $media ) {
            return;
        }

        $selected = $media[0];

        $this->imageMediaId = isset( $selected['id'] ) ? (int) $selected['id'] : null;

        if ( isset( $selected['url'] ) && is_string( $selected['url'] ) ) {
            $this->imageUrl = $selected['url'];
        }
    }

    /**
     * Clears the provider's image back to none.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function clearImage(): void
    {
        $this->imageMediaId = null;
        $this->imageUrl     = '';
    }

    /**
     * Validates the form and creates or updates the provider.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function save(): void
    {
        $this->validate();

        $slug = '' !== trim( $this->slug )
            ? str( $this->slug )->slug()->value()
            : str( $this->name )->slug()->value();

        $this->slug = $slug;

        $attributes = [
            'name'               => $this->name,
            'slug'               => $slug,
            'email'              => '' === trim( $this->email ) ? null : $this->email,
            'phone'              => '' === trim( $this->phone ) ? null : $this->phone,
            'bio'                => '' === trim( $this->bio ) ? null : $this->bio,
            'timezone'           => $this->timezone,
            'image_media_id'     => $this->imageMediaId,
            'image_url'          => '' === trim( $this->imageUrl ) ? null : $this->imageUrl,
            'round_robin_weight' => $this->roundRobinWeight,
            'sort_order'         => $this->sortOrder,
            'is_active'          => $this->isActive,
            'metadata'           => '' === trim( $this->metadataJson )
                ? null
                : json_decode( $this->metadataJson, true ),
        ];

        if ( null === $this->providerId ) {
            $provider         = ServiceProvider::query()->create( $attributes );
            $this->providerId = $provider->getKey();
        } else {
            ServiceProvider::query()->findOrFail( $this->providerId )->update( $attributes );
        }

        $this->dispatch( 'bookings-provider-saved', providerId: $this->providerId );
    }

    /**
     * Signals that the administrator wants to leave without saving.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function cancel(): void
    {
        $this->dispatch( 'bookings-provider-editor-cancelled' );
    }

    /**
     * Asks the shared media library to open its picker for the provider image.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function chooseImage(): void
    {
        $this->dispatch( 'open-media-modal', context: self::MEDIA_CONTEXT );
    }

    /**
     * Determines whether the media library is installed alongside the package.
     *
     * The image field degrades to a plain URL without it, so the picker button
     * is only offered when there is a picker to open.
     *
     * @since 1.0.0
     *
     * @return bool True when the media library can be reached.
     */
    public function mediaLibraryAvailable(): bool
    {
        return class_exists( \ArtisanPackUI\MediaLibrary\Models\Media::class );
    }

    /**
     * Gets the IANA timezone names an administrator can pick between.
     *
     * @since 1.0.0
     *
     * @return list<string> The timezone identifiers.
     */
    public function timezones(): array
    {
        return timezone_identifiers_list();
    }

    /**
     * Renders the editor.
     *
     * @since 1.0.0
     *
     * @return View The rendered view.
     */
    public function render(): View
    {
        return view( 'bookings::livewire.admin.provider-editor', [
            'timezones'             => $this->timezones(),
            'mediaLibraryAvailable' => $this->mediaLibraryAvailable(),
        ] );
    }

    /**
     * Gets the validation rules the form is checked against.
     *
     * The slug's uniqueness is scoped to the site in context and ignores the row
     * being edited, so re-saving a provider without touching their slug does not
     * collide with itself. A blank slug is allowed through because save derives
     * one from the name; the uniqueness check still runs on what that produces.
     *
     * The site is read from the context, not from the row, for the same reason
     * {@see ServiceEditor::rules()} reads it there: a provider being created has
     * no row yet, and pinning the check to `site_id IS NULL` would let a
     * duplicate slug pass validation only to fail the database's
     * `UNIQUE(site_id, slug)` as a 500.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> The rules.
     */
    protected function rules(): array
    {
        $siteId = BelongsToSiteScope::currentSiteId();

        return [
            'name'             => [ 'required', 'string', 'max:255' ],
            'slug'             => [
                'nullable',
                'string',
                'max:255',
                Rule::unique( 'service_providers', 'slug' )
                    ->where( 'site_id', $siteId )
                    ->ignore( $this->providerId ),
            ],
            'email'            => [ 'nullable', 'email', 'max:255' ],
            'phone'            => [ 'nullable', 'string', 'max:50' ],
            'bio'              => [ 'nullable', 'string' ],
            'timezone'         => [ 'required', 'string', 'timezone' ],
            'imageMediaId'     => [ 'nullable', 'integer', 'min:1' ],

            // A stored image may be an absolute `http(s)` URL or a path rooted at
            // the site, but never a `javascript:` or `data:` scheme: the column
            // feeds an avatar somewhere downstream, and a scheme that carries
            // script is one this field has no reason to accept.
            'imageUrl'         => [ 'nullable', 'string', 'max:500', 'regex:#^(https?://|/)#i' ],
            'roundRobinWeight' => [ 'required', 'integer', 'min:1' ],
            'sortOrder'        => [ 'required', 'integer', 'min:0' ],
            'isActive'         => [ 'boolean' ],

            // `json` alone passes any valid JSON, including a bare string or
            // number, which the model's `array` cast would then hand back as a
            // scalar and break the `array<string, mixed>` its column promises.
            // The closure narrows that to a JSON object — an empty one included,
            // which decodes to `[]` — and rejects a list or a scalar.
            'metadataJson'     => [
                'nullable',
                'string',
                'json',
                function ( string $attribute, mixed $value, Closure $fail ): void {
                    $decoded = json_decode( (string) $value, true );

                    if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
                        return;
                    }

                    if ( ! is_array( $decoded ) || ( [] !== $decoded && array_is_list( $decoded ) ) ) {
                        $fail( __( 'The metadata must be a JSON object.' ) );
                    }
                },
            ],
        ];
    }
}
