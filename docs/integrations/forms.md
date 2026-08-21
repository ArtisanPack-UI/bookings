---
title: Forms
---

# Forms

When `artisanpack-ui/forms` is installed, bookings adds a `booking_slot` field type to the form builder and turns a form submission carrying one into a real booking. It is detected by probing for forms' `FieldTypes` class, so nothing runs without it.

## Auto-registration

The integration wires itself when both gates pass:

1. `forms.auto_register` config is `true` (the default).
2. `artisanpack-ui/forms` is installed.

```php
// config/artisanpack/bookings.php
'forms' => [
    'auto_register' => true,
],
```

Turn the config off to wire the field type and listener by hand.

## The `booking_slot` field

`Integrations\Forms\BookingSlotField` registers the field across forms' hook filters — the palette entry, its category, its settings panel, the builder card preview, the slot-picker render, and validation rules that re-check the picked slot before the submission is stored. In the form builder it appears alongside forms' own field types; a builder configures which **service** it books and maps the customer name / email / phone to other fields on the form.

## Booking from a submission

`Integrations\Forms\FormBookingListener` listens for forms' `FormSubmitted` event. If the submission carries a `booking_slot` field, it reads the service and instant from the picked slot and the customer details from the builder-configured field mappings (with conventional field-name fallbacks), then books through `BookingService::createFromFormSubmission()` — making a form booking indistinguishable from a widget booking:

- it takes the slot lock and guards double-booking,
- it assigns a provider through the service's strategy,
- and it fires `ap.bookings.creating`, `ap.bookings.created`, and `ap.bookings.confirmed`.

A submission with no `booking_slot` field is ignored. Expected booking failures — the slot has gone, the service was withdrawn, the intake answers were invalid — are caught and logged so the form submission itself still succeeds; only unexpected errors surface.

> **Note:** forms' `FormSubmitted` event is dispatched after the submission's values are committed (it implements `ShouldDispatchAfterCommit`), so the listener always sees a complete submission.

## Related

- [Creating a Booking](Usage-Creating-Bookings) — the lifecycle a form booking joins
- [Hooks & Filters](Api-Hooks) — the `ap.bookings.*` hooks a form booking fires
