# Hooks

## Filters

### `codrisk_threshold`
Score at which a COD order is held (1-100). Default is the saved setting (50).

```php
add_filter( 'codrisk_threshold', fn() => 40 );
```

### `codrisk_score`
Final score of an order after the built-in rules.

```php
add_filter( 'codrisk_score', function ( $score, $order ) {
	return $order->get_total() > 500 ? min( 100, $score + 20 ) : $score;
}, 10, 2 );
```

### `codrisk_otp_verified`
Return `false` to mark the order as not verified (adds 60 points). No OTP provider is bundled; use this to connect one.

```php
add_filter( 'codrisk_otp_verified', function ( $verified, $order ) {
	return my_otp_service_verified( $order->get_billing_phone() );
}, 10, 2 );
```

## Scoring rules
"Other orders" never includes the order being scored.

| Signal | Points |
|---|---|
| Phone, email or IP on the blocklist | 100 |
| OTP filter returned false | 60 |
| Other orders, same phone, 24 hours: 1 / 2 or more | 15 / 40 |
| Other orders, same IP, 24 hours: 2-3 / 4 or more | 15 / 30 |
| Other orders, same address and postcode, 7 days: 2 or more | 30 |
| Earlier cancelled, failed or refunded orders, same phone, 90 days: 1 / 2 or more | 25 / 40 |

Scores add up and are capped at 100. Counts are capped at 50 per signal.