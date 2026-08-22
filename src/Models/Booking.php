<?php

/**
 * Booking model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Models;

use ArtisanPackUI\Bookings\Casts\UtcDateTime;
use ArtisanPackUI\Bookings\Database\Factories\BookingFactory;
use ArtisanPackUI\Bookings\Enums\BookingAssignmentStrategy;
use ArtisanPackUI\Bookings\Enums\BookingStatus;
use ArtisanPackUI\Bookings\Models\Concerns\BelongsToSite;
use ArtisanPackUI\Bookings\Models\Concerns\ErasesPersonalData;
use ArtisanPackUI\Bookings\Services\ManageTokenService;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One appointment.
 *
 * `start_time` and `end_time` are points in time and are stored in UTC;
 * `customer_timezone` records the zone the customer was in when they booked, so
 * the same instant can be rendered back to them the way they chose it.
 *
 * The manage token is stored only as a SHA-256 hash. The plain token exists in
 * the confirmation email and the widget URL and nowhere else, so a leaked
 * database row does not hand an attacker the ability to cancel somebody's
 * booking. {@see self::pullPlainManageToken()} is the only way to read it back,
 * it works only on the instance that minted it, and it works once.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 *
 * @property int $id
 * @property int|string|null $site_id
 * @property string $booking_number
 * @property int $service_id
 * @property int|null $provider_id
 * @property int|null $series_id
 * @property int|null $series_index
 * @property Carbon|null $detached_from_series_at
 * @property string $customer_name
 * @property string $customer_email
 * @property string|null $customer_phone
 * @property string $customer_timezone
 * @property Carbon $start_time
 * @property Carbon $end_time
 * @property BookingStatus $status
 * @property BookingAssignmentStrategy $assignment_strategy
 * @property int $intake_schema_version
 * @property array<string, mixed>|null $intake_data
 * @property string|null $notes
 * @property string $manage_token_hash
 * @property Carbon|null $pii_erased_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Booking extends Model
{
    use BelongsToSite;
    use ErasesPersonalData;
    use HasFactory;
    use SoftDeletes;

    /**
     * The value the personal columns hold once a booking has been erased.
     *
     * A fixed placeholder rather than a null so an erased row still reads as
     * populated — {@see self::isPiiErased()} is what says it is not real.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const PII_PLACEHOLDER = '[erased]';

    /**
     * The attributes that are mass assignable.
     *
     * `manage_token_hash` is absent on purpose. A caller who could set the hash
     * directly could set it to the hash of a token they already know, which is
     * the whole attack the hashing exists to prevent. Tokens are minted, never
     * assigned — see {@see self::newToken()}.
     *
     * See {@see Service::$fillable} for why `site_id` and `pii_erased_at` are
     * not on this list either.
     *
     * @since 1.0.0
     *
     * @var list<string>
     */
    protected $fillable = [
        'booking_number',
        'service_id',
        'provider_id',
        'series_id',
        'series_index',
        'customer_name',
        'customer_email',
        'customer_phone',
        'customer_timezone',
        'start_time',
        'end_time',
        'status',
        'assignment_strategy',
        'intake_schema_version',
        'intake_data',
        'notes',
    ];

    /**
     * The attributes that should be cast.
     *
     * Declared as a property rather than through the `casts()` method Laravel 11
     * introduced. The method does not exist on Laravel 10, where it is not
     * overriding anything and is simply never called — so every cast on every
     * model would quietly do nothing, and a JSON column would come back as a
     * string with no error to notice. The property is read by every version the
     * package's constraints allow.
     *
     * @since 1.0.0
     *
     * @var array<string, string>
     */
    protected $casts = [
        'provider_id'             => 'integer',
        'series_index'            => 'integer',
        'detached_from_series_at' => 'datetime',
        'customer_timezone'       => 'string',
        'start_time'              => UtcDateTime::class,
        'end_time'                => UtcDateTime::class,
        'status'                  => BookingStatus::class,
        'assignment_strategy'     => BookingAssignmentStrategy::class,
        'intake_schema_version'   => 'integer',
        'intake_data'             => 'array',
        'pii_erased_at'           => 'datetime',
    ];

    /**
     * The attributes hidden from array and JSON output.
     *
     * The hash is not the plain token, but it is still the only secret the row
     * holds, and an admin API that serialised a booking wholesale would publish
     * it to anybody who could read the response.
     *
     * @since 1.0.0
     *
     * @var list<string>
     */
    protected $hidden = [ 'manage_token_hash' ];

    /**
     * The plain manage token minted on this instance, if any.
     *
     * Never persisted and never serialised — {@see self::pullPlainManageToken()}
     * hands it over once and clears it.
     *
     * @since 1.0.0
     *
     * @var string|null
     */
    protected ?string $plainManageToken = null;

    /**
     * Mints a manage token and returns the plain value alongside its hash.
     *
     * The plain token is returned here and nowhere else. Store the hash; put the
     * plain value in the confirmation email and the manage URL, and then forget
     * it — there is deliberately no way to recover it from a saved booking.
     *
     * @since 1.0.0
     *
     * @return array{token: string, hash: string} The plain token and its hash.
     */
    public static function newToken(): array
    {
        return self::manageTokens()->mint();
    }

    /**
     * Hashes a plain manage token the way the column stores it.
     *
     * @since 1.0.0
     *
     * @param  string  $token  The plain manage token.
     *
     * @return string The SHA-256 hash of the token.
     */
    public static function hashToken( string $token ): string
    {
        return self::manageTokens()->hash( $token );
    }

    /**
     * Gets the service that owns everything to do with manage tokens.
     *
     * Resolved out of the container rather than newed up, so an application that
     * has rebound {@see ManageTokenService} gets its own implementation here too
     * — including on the token minted by the `creating` hook below.
     *
     * @since 1.0.0
     *
     * @return ManageTokenService The manage token service.
     */
    public static function manageTokens(): ManageTokenService
    {
        return Container::getInstance()->make( ManageTokenService::class );
    }

    /**
     * Gets the service that was booked.
     *
     * @since 1.0.0
     *
     * @return BelongsTo<Service, $this> The service relationship.
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo( Service::class );
    }

    /**
     * Gets the provider the booking is assigned to, if any.
     *
     * @since 1.0.0
     *
     * @return BelongsTo<ServiceProvider, $this> The provider relationship.
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo( ServiceProvider::class, 'provider_id' );
    }

    /**
     * Gets the recurrence rule this booking was generated from, if any.
     *
     * @since 1.0.0
     *
     * @return BelongsTo<BookingSeries, $this> The series relationship.
     */
    public function series(): BelongsTo
    {
        return $this->belongsTo( BookingSeries::class, 'series_id' );
    }

    /**
     * Gets the external calendar events this booking was synced to.
     *
     * @since 1.0.0
     *
     * @return HasMany<CalendarEvent, $this> The calendar events relationship.
     */
    public function calendarEvents(): HasMany
    {
        return $this->hasMany( CalendarEvent::class );
    }

    /**
     * Gets the notifications sent about this booking.
     *
     * @since 1.0.0
     *
     * @return HasMany<NotificationLog, $this> The notification log relationship.
     */
    public function notificationLogs(): HasMany
    {
        return $this->hasMany( NotificationLog::class );
    }

    /**
     * Finds a booking by its plain manage token.
     *
     * @since 1.0.0
     *
     * @param  string  $token  The plain manage token from the URL or email.
     *
     * @return static|null The matching booking, or null when the token is unknown.
     */
    public static function findByManageToken( string $token ): ?static
    {
        $booking = static::query()->where( 'manage_token_hash', self::hashToken( $token ) )->first();

        if ( null === $booking ) {
            return null;
        }

        // The indexed lookup found the row; the constant-time compare is what
        // decides it is the right one. See {@see ManageTokenService::verify()}.
        return self::manageTokens()->verifyFor( $booking, $token ) ? $booking : null;
    }

    /**
     * Takes the plain manage token minted on this instance.
     *
     * Returns null on every call after the first, and on any booking loaded from
     * the database — the plain token exists only in memory, only on the object
     * that created it, and only until somebody asks for it.
     *
     * @since 1.0.0
     *
     * @return string|null The plain manage token, or null when there is none to give.
     */
    public function pullPlainManageToken(): ?string
    {
        $token                  = $this->plainManageToken;
        $this->plainManageToken = null;

        return $token;
    }

    /**
     * Detaches this occurrence from the rule that generated it.
     *
     * The link to the series is kept — the occurrence still came from it, and
     * an admin screen showing "part of a weekly series" should keep saying so.
     * What changes is that re-materialising the rule must leave this row alone:
     * somebody has moved or edited this one occurrence, and regenerating it from
     * the rule would throw that away.
     *
     * @since 1.0.0
     *
     * @return bool True when the booking was detached by this call.
     */
    public function detachFromSeries(): bool
    {
        if ( null === $this->series_id || $this->isDetachedFromSeries() ) {
            return false;
        }

        $this->detached_from_series_at = $this->freshTimestamp();

        return $this->save();
    }

    /**
     * Determines whether this occurrence has been edited away from its series.
     *
     * @since 1.0.0
     *
     * @return bool True when re-materialisation must skip this booking.
     */
    public function isDetachedFromSeries(): bool
    {
        return null !== $this->detached_from_series_at;
    }

    /**
     * Determines whether this booking currently holds its provider's slot.
     *
     * @since 1.0.0
     *
     * @return bool True when the booking occupies the slot.
     */
    public function occupiesSlot(): bool
    {
        return $this->status->occupiesSlot();
    }

    /**
     * Overwrites the booking's personal data in place, marking it erased.
     *
     * The booking has to keep existing — it may be the row somebody was invoiced
     * for — so this redacts the columns a person is identifiable by rather than
     * deleting the row. It writes the same shape {@see BookingFactory::erased()}
     * produces: the name becomes the {@see self::PII_PLACEHOLDER} the NOT NULL
     * columns need, the email a reserved `.invalid` address, and the phone, the
     * intake answers, and the free-text notes — all of which can carry personal
     * data — are dropped. `pii_erased_at` is set in the same write, because
     * {@see self::isPiiErased()} reading that marker is the only thing that tells
     * an erased row from a real one once the placeholders make it look populated.
     *
     * The same transaction erases the copies of that data the booking's history
     * scattered elsewhere, because a booking that reads `[erased]` while a copy
     * of the name sits in the next table is not erased at all:
     *
     * - The notification log is the second place the contact details land — in
     *   `recipient`, and, when a send failed, quoted back inside `error`. Only
     *   the customer-facing channels are touched: staff sends record an internal
     *   notifiable reference rather than an address, so {@see NotificationLog}'s
     *   erasure contract keeps the `database` channel's rows intact as the record
     *   of who was told.
     * - Webhook deliveries hold a whole copy of the booking, name and email and
     *   all, inside a JSON payload that cannot be usefully redacted in place —
     *   so {@see WebhookDelivery}'s contract is to delete the booking's rows
     *   rather than rewrite them, which is what happens here.
     * - The series a recurring booking belongs to is the template its occurrences
     *   are built from, so it carries the same customer data. It is redacted only
     *   once this is the last occurrence still holding intact data: redacting it
     *   while a sibling occurrence is live would blank the name every future
     *   occurrence is materialised with.
     *
     * Erasure is not a state transition and fires no lifecycle hook. A booking
     * already erased is left as it is, so running it a second time neither
     * re-redacts nor moves the timestamp.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function erasePersonalData(): void
    {
        if ( $this->isPiiErased() ) {
            return;
        }

        $this->getConnection()->transaction( function (): void {
            $this->forceFill( [
                'customer_name'  => self::PII_PLACEHOLDER,
                'customer_email' => 'erased@example.invalid',
                'customer_phone' => null,
                'intake_data'    => null,
                'notes'          => null,
                'pii_erased_at'  => $this->freshTimestamp(),
            ] )->save();

            $this->notificationLogs()
                ->where( 'channel', '!=', 'database' )
                ->update( [
                    'recipient' => self::PII_PLACEHOLDER,
                    'error'     => null,
                ] );

            // Deliveries carry no booking_id column; each is tied to a booking
            // only by the id inside its payload, which is where the customer copy
            // sits too.
            WebhookDelivery::query()
                ->where( 'payload->data->booking->id', $this->getKey() )
                ->delete();

            $this->eraseSeriesIfLastOccurrence();
        } );
    }

    /**
     * Gets the start time rendered in the customer's own timezone.
     *
     * @since 1.0.0
     *
     * @return Carbon The start time in the customer's zone.
     */
    public function startTimeForCustomer(): Carbon
    {
        return $this->start_time->copy()->setTimezone( $this->customer_timezone );
    }

    /**
     * Gets the start time rendered in the provider's working timezone.
     *
     * The zone staff read the diary in, which is the provider's and not the
     * customer's: an administrator scanning the morning's appointments needs
     * them in the order and at the times the provider will actually work them,
     * and a booking taken from Auckland shown at its Auckland clock face reads
     * as belonging to a different day.
     *
     * Falls back to the application's zone when the booking has no provider
     * attached — an unassigned booking still has to be shown to somebody, and
     * the application's zone is the only other one the installation has stated.
     *
     * @since 1.0.0
     *
     * @return Carbon The start time in the provider's zone.
     */
    public function startTimeForProvider(): Carbon
    {
        $timezone = $this->provider?->timezone;

        if ( ! is_string( $timezone ) || '' === $timezone ) {
            $timezone = (string) config( 'app.timezone', 'UTC' );
        }

        return $this->start_time->copy()->setTimezone( $timezone );
    }

    /**
     * Scopes a query to the bookings that hold a provider's slot.
     *
     * @since 1.0.0
     *
     * @param  Builder<Booking>  $query  The query being built.
     *
     * @return Builder<Booking> The scoped query.
     */
    public function scopeActive( Builder $query ): Builder
    {
        return $query->whereIn( $this->qualifyColumn( 'status' ), BookingStatus::activeValues() );
    }

    /**
     * Erases the parent series once no occupied-by-real-data occurrence remains.
     *
     * Called from within {@see self::erasePersonalData()}'s transaction, after
     * this booking has been marked erased. A standalone booking has no series and
     * nothing to do here; a series is only redacted when every other occurrence
     * of it is already erased, so an ongoing series keeps the template its future
     * occurrences need until the last of them has been erased too.
     *
     * The series row is taken with `lockForUpdate()` before its occurrences are
     * counted, so two requests erasing the last two occurrences at once cannot
     * both read the other as still intact and both decline to redact it. The
     * second to arrive blocks until the first commits, then sees the occurrence
     * it raced marked erased and does the redaction the first correctly left.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function eraseSeriesIfLastOccurrence(): void
    {
        if ( null === $this->series_id ) {
            return;
        }

        $series = BookingSeries::query()
            ->whereKey( $this->series_id )
            ->lockForUpdate()
            ->first();

        if ( ! $series instanceof BookingSeries || $series->isPiiErased() ) {
            return;
        }

        $intactSiblingExists = static::query()
            ->where( 'series_id', $series->getKey() )
            ->whereKeyNot( $this->getKey() )
            ->notPiiErased()
            ->exists();

        if ( ! $intactSiblingExists ) {
            $series->erasePersonalData();
        }
    }

    /**
     * Bootstraps the model.
     *
     * Two invariants are enforced here rather than left to every caller.
     *
     * A booking is unusable without a manage token — the customer can never
     * cancel or reschedule it — so one is minted on create when the caller has
     * not asked for its plain value themselves. The plain token is kept on the
     * instance so the confirmation mail can still collect it.
     *
     * And `series_index` is meaningless without a series: it is the occurrence's
     * position within one. Leaving a stale index on a booking whose series was
     * deleted would let a later re-materialisation match on a position in a rule
     * that no longer exists.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected static function booted(): void
    {
        static::creating( function ( Booking $booking ): void {
            if ( null === $booking->getAttribute( 'booking_number' ) ) {
                $booking->setAttribute( 'booking_number', self::generateBookingNumber() );
            }

            if ( null === $booking->getAttribute( 'manage_token_hash' ) ) {
                $minted                     = self::newToken();
                $booking->manage_token_hash = $minted['hash'];
                $booking->plainManageToken  = $minted['token'];
            }
        } );

        static::saving( function ( Booking $booking ): void {
            // On an existing row, only when the column was actually loaded. A
            // partially hydrated booking — `select( 'id', 'status' )` on an
            // admin list, say — has no `series_id` attribute at all, and reading
            // one back gives null whether the booking has a series or not.
            // Acting on that would let an unrelated status update wipe the
            // position data re-materialisation depends on, across every
            // occurrence in the list.
            //
            // A booking being inserted is the opposite case: every attribute it
            // will have is already on it, so an absent `series_id` really does
            // mean there is no series.
            if ( $booking->exists && ! array_key_exists( 'series_id', $booking->getAttributes() ) ) {
                return;
            }

            if ( null === $booking->series_id ) {
                $booking->series_index = null;
            }
        } );
    }

    /**
     * Creates a new factory instance for the model.
     *
     * @since 1.0.0
     *
     * @return BookingFactory The factory instance.
     */
    protected static function newFactory(): BookingFactory
    {
        return BookingFactory::new();
    }

    /**
     * Generates a booking reference.
     *
     * Deliberately not sequential. A guessable reference plus a leaky endpoint
     * is a way to enumerate other people's bookings, and the column is only ever
     * a human-facing label — the manage token is what actually authorises
     * anything.
     *
     * @since 1.0.0
     *
     * @return string The generated booking number.
     */
    protected static function generateBookingNumber(): string
    {
        return 'BK-' . strtoupper( Str::random( 12 ) );
    }
}
