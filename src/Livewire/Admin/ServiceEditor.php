<?php

/**
 * Admin service editor.
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

use ArtisanPackUI\Bookings\Enums\ServiceAssignmentStrategy;
use ArtisanPackUI\Bookings\Models\Scopes\BelongsToSiteScope;
use ArtisanPackUI\Bookings\Models\Service;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Creates a service or edits everything about one but its intake form.
 *
 * The form is deliberately not here. A service's core — its name, its duration,
 * the rules that schedule it — is edited in place: the row is loaded, changed,
 * and saved over. Its intake form cannot be, because every past booking records
 * the version of the form it was captured against and has to keep rendering
 * against it. Editing the two through one save would make "save the service"
 * quietly append a form version on every unrelated tweak, so the form lives in
 * {@see IntakeSchemaEditor} and this component links across to it.
 *
 * Site scoping is left to the models. A service loaded here came through the
 * site-scoped query that {@see ServicesIndex} lists from, and a save stamps the
 * site in context on create, so nothing on this component names a tenant — the
 * one thing a client could set to reach across to another.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class ServiceEditor extends Component
{
    /**
     * The service being edited, or null while creating one.
     *
     * Locked: it is the row every save writes over, and a client that could
     * repoint it could edit a service the administrator never opened —
     * including, if the id crossed a tenant boundary, one the site-scoped list
     * would never have shown them.
     *
     * @since 1.0.0
     *
     * @var int|null
     */
    #[Locked]
    public ?int $serviceId = null;

    /**
     * The service's public name.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $name = '';

    /**
     * The slug the service is addressed by, unique within its site.
     *
     * Left blank, it is derived from the name on save. Kept editable because the
     * slug is in the booking URL a customer keeps, so changing the name of a
     * live service must not have to change the link that reaches it.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $slug = '';

    /**
     * The service's description.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $description = '';

    /**
     * How long an appointment lasts, in minutes.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public int $duration = 30;

    /**
     * Minutes held clear before each appointment.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public int $bufferBefore = 0;

    /**
     * Minutes held clear after each appointment.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public int $bufferAfter = 0;

    /**
     * The price, as a decimal string, or blank for a free service.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $price = '';

    /**
     * Whether the service is offered at no charge.
     *
     * @since 1.0.0
     *
     * @var bool
     */
    public bool $isFree = false;

    /**
     * How many bookings one slot can hold.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public int $maxBookingsPerSlot = 1;

    /**
     * Whether the service can currently be booked.
     *
     * @since 1.0.0
     *
     * @var bool
     */
    public bool $isActive = true;

    /**
     * The rule that picks a provider when the customer does not.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $assignmentStrategy = ServiceAssignmentStrategy::Any->value;

    /**
     * The provider a service falls back to, by id.
     *
     * @since 1.0.0
     *
     * @var int|null
     */
    public ?int $defaultProviderId = null;

    /**
     * The service's theme colour, as a hex string.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $color = '';

    /**
     * The timezone the service's clock runs in, or blank to inherit the app's.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $timezone = '';

    /**
     * Loads the service being edited, or leaves the form blank to create one.
     *
     * @since 1.0.0
     *
     * @param  int|null  $serviceId  The service to edit, or null to create one.
     *
     * @return void
     */
    public function mount( ?int $serviceId = null ): void
    {
        if ( null === $serviceId ) {
            return;
        }

        $service = Service::query()->findOrFail( $serviceId );

        $this->serviceId          = $service->getKey();
        $this->name               = $service->name;
        $this->slug               = $service->slug;
        $this->description        = (string) $service->description;
        $this->duration           = $service->duration;
        $this->bufferBefore       = $service->buffer_before;
        $this->bufferAfter        = $service->buffer_after;
        $this->price              = null === $service->price ? '' : (string) $service->price;
        $this->isFree             = $service->is_free;
        $this->maxBookingsPerSlot = $service->max_bookings_per_slot;
        $this->isActive           = $service->is_active;
        $this->assignmentStrategy = $service->assignment_strategy->value;
        $this->defaultProviderId  = $service->default_provider_id;
        $this->color              = (string) $service->color;
        $this->timezone           = (string) $service->timezone;
    }

    /**
     * Validates the form and creates or updates the service.
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
            'name'                  => $this->name,
            'slug'                  => $slug,
            'description'           => '' === trim( $this->description ) ? null : $this->description,
            'duration'              => $this->duration,
            'buffer_before'         => $this->bufferBefore,
            'buffer_after'          => $this->bufferAfter,
            'price'                 => $this->isFree || '' === trim( $this->price ) ? null : $this->price,
            'is_free'               => $this->isFree,
            'max_bookings_per_slot' => $this->maxBookingsPerSlot,
            'is_active'             => $this->isActive,
            'assignment_strategy'   => $this->assignmentStrategy,
            'default_provider_id'   => $this->defaultProviderId,
            'color'                 => '' === trim( $this->color ) ? null : $this->color,
            'timezone'              => '' === trim( $this->timezone ) ? null : $this->timezone,
        ];

        if ( null === $this->serviceId ) {
            $service         = Service::query()->create( $attributes );
            $this->serviceId = $service->getKey();
        } else {
            Service::query()->findOrFail( $this->serviceId )->update( $attributes );
        }

        $this->dispatch( 'bookings-service-saved', serviceId: $this->serviceId );
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
        $this->dispatch( 'bookings-service-editor-cancelled' );
    }

    /**
     * Gets the providers this site can assign the service to.
     *
     * @since 1.0.0
     *
     * @return \Illuminate\Support\Collection<int, ServiceProvider> The providers.
     */
    public function providers(): \Illuminate\Support\Collection
    {
        return ServiceProvider::query()
            ->orderBy( 'name' )
            ->get( [ 'id', 'name' ] );
    }

    /**
     * Gets the assignment strategies an administrator can pick between.
     *
     * @since 1.0.0
     *
     * @return array<string, string> The value-to-label map.
     */
    public function assignmentStrategies(): array
    {
        return [
            ServiceAssignmentStrategy::Any->value             => __( 'Any available provider' ),
            ServiceAssignmentStrategy::RoundRobin->value      => __( 'Round robin' ),
            ServiceAssignmentStrategy::DefaultProvider->value => __( 'Default provider' ),
        ];
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
        return view( 'bookings::livewire.admin.service-editor', [
            'providers'  => $this->providers(),
            'strategies' => $this->assignmentStrategies(),
        ] );
    }

    /**
     * Gets the validation rules the form is checked against.
     *
     * The slug's uniqueness is scoped to the site in context and ignores the row
     * being edited, so re-saving a service without touching its slug does not
     * collide with itself. A blank slug is allowed through because save derives
     * one from the name; the uniqueness check still runs on what that produces.
     *
     * The site is read from the context, not from the row: a service being
     * created has no row yet, and pinning the check to `site_id IS NULL` there —
     * as reading the row would, since it comes back null — would let a duplicate
     * slug pass validation only to fail the database's `UNIQUE(site_id, slug)`
     * as a 500. The context holds the site that will actually be stamped.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> The rules.
     */
    protected function rules(): array
    {
        $siteId = BelongsToSiteScope::currentSiteId();

        return [
            'name'               => [ 'required', 'string', 'max:255' ],
            'slug'               => [
                'nullable',
                'string',
                'max:255',
                Rule::unique( 'services', 'slug' )
                    ->where( 'site_id', $siteId )
                    ->ignore( $this->serviceId ),
            ],
            'description'        => [ 'nullable', 'string' ],
            'duration'           => [ 'required', 'integer', 'min:1' ],
            'bufferBefore'       => [ 'required', 'integer', 'min:0' ],
            'bufferAfter'        => [ 'required', 'integer', 'min:0' ],
            'price'              => [ 'nullable', 'numeric', 'min:0' ],
            'isFree'             => [ 'boolean' ],
            'maxBookingsPerSlot' => [ 'required', 'integer', 'min:1' ],
            'isActive'           => [ 'boolean' ],
            'assignmentStrategy' => [ 'required', Rule::enum( ServiceAssignmentStrategy::class ) ],
            'defaultProviderId'  => [
                'nullable',
                'integer',
                'required_if:assignmentStrategy,' . ServiceAssignmentStrategy::DefaultProvider->value,

                // Scoped through the model, not `Rule::exists`, which issues a raw
                // query that bypasses both the site scope and the soft-delete
                // scope — and would accept a provider from another tenant, or a
                // retired one, that the dropdown never offered.
                function ( string $attribute, mixed $value, Closure $fail ): void {
                    if ( null !== $value && ! ServiceProvider::query()->whereKey( $value )->exists() ) {
                        $fail( __( 'The selected default provider is unavailable.' ) );
                    }
                },
            ],
            'color'              => [ 'nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/' ],
            'timezone'           => [ 'nullable', 'string', 'timezone' ],
        ];
    }
}
