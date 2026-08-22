<?php

/**
 * Admin availability editor.
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

use ArtisanPackUI\Bookings\Enums\AvailabilityOverrideType;
use ArtisanPackUI\Bookings\Models\ServiceProvider;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Edits a provider's weekly hours and their single-date exceptions.
 *
 * Two sets of rows against one provider. The schedules are the recurring week —
 * "Mondays, nine to five" — authored as wall-clock time in the provider's own
 * timezone and never as UTC, so a nine o'clock start stays nine o'clock across a
 * daylight-saving change. The overrides are the days that break the pattern: a
 * day taken off, or a day worked at other hours. Both are edited here together
 * because they answer the same question — when can this provider be booked — and
 * an administrator setting a Christmas Eve closure is thinking about the same
 * calendar as the one they set the weekly hours on.
 *
 * The provider is loaded through the site-scoped model, so a provider id from
 * another tenant is a 404 rather than a door: nothing on this component names a
 * site, and the schedules and overrides — which are scoped only through their
 * provider — are always reached from that scoped provider, never queried
 * directly by a client-supplied id.
 *
 * A save replaces the whole set rather than diffing it. These rows carry no
 * external references, an administrator edits the week as a whole, and replacing
 * it is both simpler to reason about and impossible to leave half-applied.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class AvailabilityEditor extends Component
{
    /**
     * The provider whose availability is being edited.
     *
     * Locked: it is the row every schedule and override is written against, and
     * a client that could repoint it could rewrite the availability of a
     * provider the administrator never opened — including, across a tenant
     * boundary, one the site-scoped list would never have shown them.
     *
     * @since 1.0.0
     *
     * @var int
     */
    #[Locked]
    public int $providerId = 0;

    /**
     * The provider's name, shown so the administrator knows whose week this is.
     *
     * @since 1.0.0
     *
     * @var string
     */
    #[Locked]
    public string $providerName = '';

    /**
     * The provider's timezone, shown because the times are read against it.
     *
     * @since 1.0.0
     *
     * @var string
     */
    #[Locked]
    public string $timezone = '';

    /**
     * The recurring weekly windows.
     *
     * Each row is `day_of_week`, `start_time_local`, `end_time_local`,
     * `effective_from`, `effective_until`, and `is_available`. Times are `H:i`
     * wall-clock strings; the effective dates are `Y-m-d` or blank.
     *
     * @since 1.0.0
     *
     * @var array<int, array<string, mixed>>
     */
    public array $schedules = [];

    /**
     * The single-date exceptions.
     *
     * Each row is `date`, `type`, `start_time_local`, `end_time_local`, and
     * `reason`. The times are only meaningful when the type is custom hours.
     *
     * @since 1.0.0
     *
     * @var array<int, array<string, mixed>>
     */
    public array $overrides = [];

    /**
     * Loads the provider and their current availability into the form.
     *
     * @since 1.0.0
     *
     * @param  int  $providerId  The provider whose availability to edit.
     *
     * @return void
     */
    public function mount( int $providerId ): void
    {
        $provider = ServiceProvider::query()->findOrFail( $providerId );

        $this->providerId   = $provider->getKey();
        $this->providerName = $provider->name;
        $this->timezone     = $provider->timezone;

        $this->schedules = $provider->availabilitySchedules()
            ->orderBy( 'day_of_week' )
            ->orderBy( 'start_time_local' )
            ->get()
            ->map( fn ( $schedule ): array => [
                'day_of_week'      => $schedule->day_of_week,
                'start_time_local' => substr( (string) $schedule->start_time_local, 0, 5 ),
                'end_time_local'   => substr( (string) $schedule->end_time_local, 0, 5 ),
                'effective_from'   => $schedule->effective_from?->toDateString() ?? '',
                'effective_until'  => $schedule->effective_until?->toDateString() ?? '',
                'is_available'     => $schedule->is_available,
            ] )
            ->all();

        $this->overrides = $provider->availabilityOverrides()
            ->orderBy( 'date' )
            ->get()
            ->map( fn ( $override ): array => [
                'date'             => $override->date->toDateString(),
                'type'             => $override->type->value,
                'start_time_local' => substr( (string) $override->start_time_local, 0, 5 ),
                'end_time_local'   => substr( (string) $override->end_time_local, 0, 5 ),
                'reason'           => (string) $override->reason,
            ] )
            ->all();
    }

    /**
     * Adds a blank weekly window to the form.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function addSchedule(): void
    {
        $this->schedules[] = [
            'day_of_week'      => 1,
            'start_time_local' => '09:00',
            'end_time_local'   => '17:00',
            'effective_from'   => '',
            'effective_until'  => '',
            'is_available'     => true,
        ];
    }

    /**
     * Removes a weekly window from the form.
     *
     * The array is reindexed so the rows stay a clean list rather than one with
     * a hole in it, which keeps the wire keys and validation paths lined up with
     * what the administrator sees.
     *
     * @since 1.0.0
     *
     * @param  int  $index  The row to remove.
     *
     * @return void
     */
    public function removeSchedule( int $index ): void
    {
        unset( $this->schedules[ $index ] );

        $this->schedules = array_values( $this->schedules );
    }

    /**
     * Adds a blank single-date exception to the form.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function addOverride(): void
    {
        $this->overrides[] = [
            'date'             => '',
            'type'             => AvailabilityOverrideType::Unavailable->value,
            'start_time_local' => '',
            'end_time_local'   => '',
            'reason'           => '',
        ];
    }

    /**
     * Removes a single-date exception from the form.
     *
     * @since 1.0.0
     *
     * @param  int  $index  The row to remove.
     *
     * @return void
     */
    public function removeOverride( int $index ): void
    {
        unset( $this->overrides[ $index ] );

        $this->overrides = array_values( $this->overrides );
    }

    /**
     * Validates the form and replaces the provider's availability with it.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function save(): void
    {
        $this->validate();
        $this->assertTimeWindowsClose();

        $provider = ServiceProvider::query()->findOrFail( $this->providerId );

        DB::transaction( function () use ( $provider ): void {
            $provider->availabilitySchedules()->delete();
            $provider->availabilityOverrides()->delete();

            foreach ( $this->schedules as $schedule ) {
                $provider->availabilitySchedules()->create( [
                    'day_of_week'      => (int) $schedule['day_of_week'],
                    'start_time_local' => $schedule['start_time_local'],
                    'end_time_local'   => $schedule['end_time_local'],
                    'effective_from'   => '' === trim( (string) $schedule['effective_from'] ) ? null : $schedule['effective_from'],
                    'effective_until'  => '' === trim( (string) $schedule['effective_until'] ) ? null : $schedule['effective_until'],
                    'is_available'     => (bool) $schedule['is_available'],
                ] );
            }

            foreach ( $this->overrides as $override ) {
                $isCustomHours = AvailabilityOverrideType::CustomHours->value === $override['type'];

                $provider->availabilityOverrides()->create( [
                    'date'             => $override['date'],
                    'type'             => $override['type'],
                    'start_time_local' => $isCustomHours ? $override['start_time_local'] : null,
                    'end_time_local'   => $isCustomHours ? $override['end_time_local'] : null,
                    'reason'           => '' === trim( (string) $override['reason'] ) ? null : $override['reason'],
                ] );
            }
        } );

        $this->dispatch( 'bookings-provider-availability-saved', providerId: $this->providerId );
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
        $this->dispatch( 'bookings-provider-availability-editor-cancelled' );
    }

    /**
     * Gets the weekday labels an administrator picks a schedule's day from.
     *
     * Sunday-indexed, 0–6, to match the column and Carbon's `dayOfWeek`.
     *
     * @since 1.0.0
     *
     * @return array<int, string> The day-number-to-label map.
     */
    public function weekdays(): array
    {
        return [
            0 => __( 'Sunday' ),
            1 => __( 'Monday' ),
            2 => __( 'Tuesday' ),
            3 => __( 'Wednesday' ),
            4 => __( 'Thursday' ),
            5 => __( 'Friday' ),
            6 => __( 'Saturday' ),
        ];
    }

    /**
     * Gets the override types an administrator picks an exception's kind from.
     *
     * @since 1.0.0
     *
     * @return array<string, string> The value-to-label map.
     */
    public function overrideTypes(): array
    {
        return [
            AvailabilityOverrideType::Unavailable->value => __( 'Unavailable all day' ),
            AvailabilityOverrideType::CustomHours->value => __( 'Custom hours' ),
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
        return view( 'bookings::livewire.admin.availability-editor', [
            'weekdays'        => $this->weekdays(),
            'overrideTypes'   => $this->overrideTypes(),
            'customHoursType' => AvailabilityOverrideType::CustomHours->value,
        ] );
    }

    /**
     * Confirms every time window closes after it opens.
     *
     * Validation's rules match a field against a constant or a format; a window
     * that ends before it starts needs one field compared against another in the
     * same row, which the array rules cannot reach across. Checking it here,
     * after {@see self::validate()} has confirmed the times are times at all,
     * keeps a backwards window from being saved as a window with no minutes in
     * it — one that would silently offer no slots. The failures are thrown the
     * same way {@see self::validate()} throws its own, so they land in the error
     * bag and halt the save before it writes anything.
     *
     * @since 1.0.0
     *
     * @throws ValidationException When a window closes before it opens, or an
     *                             effective window ends before it starts.
     *
     * @return void
     */
    protected function assertTimeWindowsClose(): void
    {
        $messages = [];

        foreach ( $this->schedules as $index => $schedule ) {
            if ( $schedule['end_time_local'] <= $schedule['start_time_local'] ) {
                $messages[ "schedules.{$index}.end_time_local" ] = __( 'The end time must be after the start time.' );
            }

            if (
                '' !== trim( (string) $schedule['effective_from'] )
                && '' !== trim( (string) $schedule['effective_until'] )
                && $schedule['effective_until'] < $schedule['effective_from']
            ) {
                $messages[ "schedules.{$index}.effective_until" ] = __( 'The end date must not be before the start date.' );
            }
        }

        foreach ( $this->overrides as $index => $override ) {
            if (
                AvailabilityOverrideType::CustomHours->value === $override['type']
                && $override['end_time_local'] <= $override['start_time_local']
            ) {
                $messages[ "overrides.{$index}.end_time_local" ] = __( 'The end time must be after the start time.' );
            }
        }

        if ( [] !== $messages ) {
            throw ValidationException::withMessages( $messages );
        }
    }

    /**
     * Gets the validation rules the form is checked against.
     *
     * The times are validated as `H:i` or `H:i:s` wall-clock strings — never as
     * dates — because that is exactly what the column holds and what the
     * provider's timezone is later read against. A custom-hours override must
     * carry a window; a day marked unavailable must not, and its times are
     * dropped on save rather than validated, so a leftover time from a type the
     * administrator changed their mind about does not have to be cleared by hand.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> The rules.
     */
    protected function rules(): array
    {
        $time = 'date_format:H:i,H:i:s';

        return [
            'schedules'                     => [ 'array', 'max:100' ],
            'schedules.*.day_of_week'       => [ 'required', 'integer', 'between:0,6' ],
            'schedules.*.start_time_local'  => [ 'required', $time ],
            'schedules.*.end_time_local'    => [ 'required', $time ],
            'schedules.*.effective_from'    => [ 'nullable', 'date' ],
            'schedules.*.effective_until'   => [ 'nullable', 'date' ],
            'schedules.*.is_available'      => [ 'boolean' ],

            'overrides'                     => [ 'array', 'max:100' ],
            'overrides.*.date'              => [ 'required', 'date' ],
            'overrides.*.type'              => [ 'required', Rule::enum( AvailabilityOverrideType::class ) ],
            'overrides.*.start_time_local'  => [ 'nullable', 'required_if:overrides.*.type,' . AvailabilityOverrideType::CustomHours->value, $time ],
            'overrides.*.end_time_local'    => [ 'nullable', 'required_if:overrides.*.type,' . AvailabilityOverrideType::CustomHours->value, $time ],
            'overrides.*.reason'            => [ 'nullable', 'string', 'max:255' ],
        ];
    }
}
