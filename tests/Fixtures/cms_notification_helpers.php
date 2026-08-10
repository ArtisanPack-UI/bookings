<?php

/**
 * Stand-in for cms-framework's notification helpers.
 *
 * That package publishes its notification API as global functions loaded from a
 * `files` autoload entry, and it is a `suggest` rather than a dependency, so the
 * real ones are absent here. These record what they were handed instead of
 * writing anything, which is what lets the CMS channel be tested against the
 * shape it actually calls.
 *
 * Every declaration is guarded, the class included. The file is loaded with
 * `require_once` from the test that needs it, but a guard on the functions alone
 * would still fatal with "Cannot declare class" if it were ever reached by a
 * second path — a composer `files` entry, say — and that failure happens at
 * compile time, before any of the function guards below get a chance to run.
 *
 * @package    ArtisanPack_UI
 * @subpackage Bookings
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

if ( ! class_exists( 'CmsNotificationSpy' ) ) {
    /**
     * Records the notifications the CMS channel asked to be sent.
     *
     * @since 1.0.0
     */
    class CmsNotificationSpy
    {
        /**
         * Sends made by role.
         *
         * @var array<int, array{key: string, role: string, overrides: array<string, mixed>}>
         */
        public array $byRole = [];

        /**
         * Sends made to an explicit id list.
         *
         * @var array<int, array{key: string, ids: array<int, int>, overrides: array<string, mixed>}>
         */
        public array $byIds = [];

        /**
         * Whether the helpers should report nobody wanted the notification.
         *
         * @var bool
         */
        public bool $returnNull = false;

        /**
         * Forgets everything recorded so far.
         *
         * @return void
         */
        public function reset(): void
        {
            $this->byRole     = [];
            $this->byIds      = [];
            $this->returnNull = false;
        }
    }
}

if ( ! function_exists( 'cmsNotificationSpy' ) ) {
    /**
     * Gets the shared spy the stand-in helpers record against.
     *
     * @since 1.0.0
     *
     * @return CmsNotificationSpy The spy.
     */
    function cmsNotificationSpy(): CmsNotificationSpy
    {
        static $spy = null;

        if ( null === $spy ) {
            $spy = new CmsNotificationSpy();
        }

        return $spy;
    }
}

if ( ! function_exists( 'apSendNotification' ) ) {
    /**
     * Records a send to an explicit list of users.
     *
     * @since 1.0.0
     *
     * @param  string  $key  The registered notification key.
     * @param  array<int, int>  $userIds  The users to notify.
     * @param  array<string, mixed>  $overrides  The notification overrides.
     *
     * @return object|null The created notification, or null when nobody wanted it.
     */
    function apSendNotification( string $key, array $userIds, array $overrides = [] ): ?object
    {
        cmsNotificationSpy()->byIds[] = [ 'key' => $key, 'ids' => $userIds, 'overrides' => $overrides ];

        return cmsNotificationSpy()->returnNull ? null : new stdClass();
    }
}

if ( ! function_exists( 'apSendNotificationByRole' ) ) {
    /**
     * Records a send to everybody holding a role.
     *
     * @since 1.0.0
     *
     * @param  string  $key  The registered notification key.
     * @param  string  $role  The role to notify.
     * @param  array<string, mixed>  $overrides  The notification overrides.
     *
     * @return object|null The created notification, or null when nobody wanted it.
     */
    function apSendNotificationByRole( string $key, string $role, array $overrides = [] ): ?object
    {
        cmsNotificationSpy()->byRole[] = [ 'key' => $key, 'role' => $role, 'overrides' => $overrides ];

        return cmsNotificationSpy()->returnNull ? null : new stdClass();
    }
}

if ( ! function_exists( 'apRegisterNotification' ) ) {
    /**
     * Accepts a notification type registration and does nothing with it.
     *
     * @since 1.0.0
     *
     * @param  string  $key  The notification key.
     * @param  string  $title  The default title.
     * @param  string  $content  The default content.
     *
     * @return void
     */
    function apRegisterNotification( string $key, string $title, string $content ): void
    {
    }
}
