<?php

/**
 * Intake validation exception.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Exceptions;

use Illuminate\Support\MessageBag;

/**
 * The answers given to a service's intake form were rejected.
 *
 * Carries a {@see MessageBag} rather than a flat message so a form can put each
 * complaint back beside the field it belongs to. That is the whole reason this
 * is a domain exception and not a `ValidationException`: the intake fields are
 * authored by an administrator at runtime, so the caller — a Livewire
 * component, a controller, a form-submission listener — decides how the errors
 * reach the screen, and this only has to say what they are.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class IntakeValidationException extends BookingException
{
    /**
     * The per-field complaints.
     *
     * @since 1.0.0
     *
     * @var MessageBag
     */
    protected MessageBag $errors;

    /**
     * Constructs the exception.
     *
     * @since 1.0.0
     *
     * @param  MessageBag  $errors  The per-field complaints.
     */
    public function __construct( MessageBag $errors )
    {
        parent::__construct( 'The intake answers given are not valid.' );

        $this->errors = $errors;
    }

    /**
     * Gets the per-field complaints.
     *
     * @since 1.0.0
     *
     * @return MessageBag The errors, keyed by intake field name.
     */
    public function errors(): MessageBag
    {
        return $this->errors;
    }
}
