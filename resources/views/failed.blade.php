{{--
    Shown instead of the sign-in page when sign-in with One-Stop fails while the application is
    open inside One-Stop: the message is handed to One-Stop, which shows it as an alert.
--}}
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Sign-in problem</title>
  </head>
  <body style="margin: 0; padding: 24px; font: 14px/1.5 system-ui, sans-serif; color: #334155;">
    <p>{{ $message }}</p>
    <p><a href="{{ \Maico\OneStop\OneStop::routeUrl('login_route') }}">Sign in with a password instead</a></p>
    <script>
      window.parent.postMessage({ type: 'one-stop:sign-in-failed', message: @json($message) }, @json($oneStopOrigin));
    </script>
  </body>
</html>
