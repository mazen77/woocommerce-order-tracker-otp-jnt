# SMS Integration

The plugin does not include SMS vendor credentials or make direct external API calls.

For phone/order-number OTP, attach your SMS provider to one of the actions already emitted by the plugin.

## Generic hook

```php
add_action( 'mazen_order_tracker_send_sms', function ( $phone, $message ) {
    // Send $message to $phone using your provider SDK/API.
}, 10, 2 );
```

`$phone` is normalized to an E.164-style value such as `+9665xxxxxxxx` for supported Saudi numbers.

## Existing Taqnyat-compatible hook

If another plugin registers this action, the tracker will use it automatically:

```php
add_action( 'lwp_send_sms_taqnyat', 'your_existing_callback', 10, 2 );
```

Keep API keys in server-side configuration or your SMS integration plugin. Do not commit credentials to this repository.
