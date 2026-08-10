<?php

/**
 * Send booking reminders command.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Bookings\Console\Commands;

use ArtisanPackUI\Bookings\Services\ReminderScheduler;
use Illuminate\Console\Command;

use function __;

/**
 * Sends every booking reminder that has come due.
 *
 * Registered on the scheduler every fifteen minutes. Running it more often is
 * harmless and running two at once is harmless, because a reminder is claimed in
 * the notification log before it is sent and the claim is what decides who sends
 * it — see {@see ReminderScheduler}. That is the property worth protecting: this
 * is the one command in the package whose double-execution a customer would
 * notice, in their inbox.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */
class SendBookingRemindersCommand extends Command
{
    /**
     * The console command signature.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected $signature = 'bookings:send-reminders';

    /**
     * The console command description.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected $description = 'Sends booking reminders that have come due.';

    /**
     * Runs the command.
     *
     * @since 1.0.0
     *
     * @param  ReminderScheduler  $reminders  The reminder scheduler.
     *
     * @return int The command exit code.
     */
    public function handle( ReminderScheduler $reminders ): int
    {
        $sent = $reminders->sendDue();

        $this->info( __( ':count reminder(s) sent.', [ 'count' => $sent ] ) );

        return self::SUCCESS;
    }
}
