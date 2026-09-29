# Contributing

Contributions are welcome.

## Development rules

- Keep the plugin lightweight and avoid unnecessary cron jobs or external dependencies.
- Preserve the shortcode `[mazen_order_tracker]` and existing AJAX action names for backward compatibility.
- Never commit API keys, SMS credentials, customer data, order exports, or production logs.
- Sanitize request input and escape rendered output.
- Any public order-data path must remain protected by ownership verification.
- Keep HPOS compatibility in mind for all WooCommerce order queries.

## Before opening a pull request

1. Run PHP syntax checks.
2. Test email OTP.
3. Test phone OTP with a configured SMS integration.
4. Test valid and invalid OTP attempts.
5. Test order lookup by order number, billing email, and Saudi phone variants.
6. Test both HPOS and legacy order storage when possible.
7. Confirm no customer/order information is visible before OTP verification.
