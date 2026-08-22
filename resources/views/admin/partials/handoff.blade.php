{{--
    Translates the admin components' browser events into page navigation.

    The list and editor components talk to their host in Livewire events rather
    than reaching for each other — a list dispatches `bookings-edit-service`, it
    does not mount the editor itself — because which of a modal, a panel, or a
    fresh page answers that intent is the host's layout decision, not theirs. In
    the package's own layout the answer is a query-string reload: the chosen row
    travels in the URL and the editor remounts on it. A host with cms-framework,
    or its own richer shell, wires the same events differently.

    Pass `$handoff` as a list of rules. Each rule names the event in `on` and one
    action:

    - `set` + `from` — set that query parameter to the payload field named by
      `from` (with `default` when the field is null) and reload.
    - `remove` — delete that query parameter and reload.
    - `visit` — navigate to that URL, substituting `__ID__` with the payload
      field named by `from`.

    @package    ArtisanPack_UI
    @subpackage Bookings

    @since      1.0.0
--}}
@php( $handoff = $handoff ?? [] )
@if ( ! empty( $handoff ) )
	<script>
		document.addEventListener('livewire:init', () => {
			const rules = @json( $handoff );

			rules.forEach((rule) => {
				Livewire.on(rule.on, (payload = {}) => {
					if (rule.visit) {
						const id = rule.from ? (payload[rule.from] ?? '') : '';
						window.location = rule.visit.replace('__ID__', id);
						return;
					}

					const url = new URL(window.location);

					if (rule.remove) {
						url.searchParams.delete(rule.remove);
					}

					if (rule.set) {
						const value = rule.from
							? (payload[rule.from] ?? rule.default ?? '')
							: (rule.value ?? '');
						url.searchParams.set(rule.set, value);
					}

					window.location = url;
				});
			});
		});
	</script>
@endif
