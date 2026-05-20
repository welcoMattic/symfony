Apple Push Notification service (APNs) Notifier
================================================

Provides [Apple Push Notification service (APNs)](https://developer.apple.com/documentation/usernotifications)
integration for Symfony Notifier.

DSN example
-----------

```
APNS_DSN=apns://KEY_ID:TEAM_ID@default?privateKey=BASE64_ENCODED_P8_KEY&topic=COM.EXAMPLE.APP
```

where:
 - `KEY_ID` is your Apple 10-character Key ID
 - `TEAM_ID` is your Apple 10-character Team ID
 - `BASE64_ENCODED_P8_KEY` is your `.p8` private key content, base64-encoded
 - `COM.EXAMPLE.APP` is your app's bundle identifier

For sandbox (development) environment, use the `apns-sandbox` scheme:

```
APNS_DSN=apns-sandbox://KEY_ID:TEAM_ID@default?privateKey=BASE64_ENCODED_P8_KEY&topic=COM.EXAMPLE.APP
```

Resources
---------

 * [Contributing](https://symfony.com/doc/current/contributing/index.html)
 * [Report issues](https://github.com/symfony/symfony/issues) and
   [send Pull Requests](https://github.com/symfony/symfony/pulls)
   in the [main Symfony repository](https://github.com/symfony/symfony)
