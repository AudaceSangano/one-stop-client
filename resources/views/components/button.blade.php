{{--
    "Sign in with MAICO One-Stop". Renders nothing while One-Stop sign-in is disabled.

    <x-one-stop::button class="btn btn-secondary" />
    <x-one-stop::button class="...">Unlock with MAICO One-Stop</x-one-stop::button>
--}}
@if (\Maico\OneStop\OneStop::enabled())
  <a href="{{ \Maico\OneStop\OneStop::signInUrl() }}" {{ $attributes }}>{{ $slot->isEmpty() ? 'Sign in with MAICO One-Stop' : $slot }}</a>
@endif
