{{--
    Put <x-one-stop::head /> in the <head> of your layout. While One-Stop sign-in is enabled it marks
    the page with the "in-one-stop" class when it is shown inside One-Stop (the only site allowed to
    frame it), so you can hide or show parts of the page:

      class="standalone-only"   hidden inside One-Stop (e.g. Sign out, Lock screen, your own logo bar)
      class="one-stop-only"     shown only inside One-Stop
--}}
@if (\Maico\OneStop\OneStop::enabled())
<style>html.in-one-stop .standalone-only{display:none!important}html:not(.in-one-stop) .one-stop-only{display:none!important}</style>
<script>if (window.self !== window.top) document.documentElement.classList.add('in-one-stop');</script>
@endif
