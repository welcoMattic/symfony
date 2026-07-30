# Study: ID token signature verification for the OIDC login authenticator

Status: design study (dedicated branch, not for merge as-is)
Scope: `OidcLoginAuthenticator` (Authorization Code Flow), Security/Http
Related: PR symfony/symfony#64954 (login backbone), prototype branch `oidc-response-type`

## 1. Problem statement

The login authenticator added in #64954 does **not** verify the ID token
signature. `OidcIdToken::decode()` only deserializes the JWS payload:

```php
// Security/Http/Authenticator/Oidc/OidcIdToken.php
$payload = (new JWSSerializerManager([new CompactSerializer()]))->unserialize($jwt)->getPayload();
```

This is spec-compliant for the Authorization Code Flow: the ID token is
received directly from the token endpoint over a back channel, so OIDC Core
1.0 Section 3.1.3.7 item 6 allows TLS server validation to stand in for the
signature check.

The catch: **that guarantee is only as strong as the TLS validation of the
HTTP client used for the token-endpoint request.** The token exchange runs
through `OidcClient` over the shared `http_client` service:

```php
// Security/Http/Authenticator/Oidc/OidcClient.php
$this->httpClient->request('POST', $config['token_endpoint'], $options)->toArray();
```

Symfony's HTTP client verifies TLS by default (`verify_peer: true`,
`verify_host: true`), and the factory forces `provider_uri` to be HTTPS. In a
default deployment the model holds.

But nothing in the component detects or compensates for a deployment that
disables TLS verification on that client.

## 2. Threat model

Precondition: the application sets `verify_peer: false` and/or
`verify_host: false` on the HTTP client that serves the OIDC token endpoint
(commonly the shared `framework.http_client`, sometimes done behind
intercepting proxies or for "debugging").

Attack: a network attacker (MITM: rogue Wi-Fi, ARP/DNS spoofing, compromised
proxy, hostile egress) intercepts the back-channel token request and returns a
forged token response with an attacker-chosen, unsigned (or wrongly signed)
ID token: `sub` of any victim, arbitrary claims.

Result: because the signature is never checked and TLS no longer authenticates
the peer, the forged ID token is accepted. With PKCE and `state` protecting
only the front channel, this yields full identity spoofing / account takeover.

Severity: HIGH when the precondition holds; not reachable in a default
(TLS-verifying) deployment.

## 3. What already exists (prototype)

Branch `oidc-response-type` already implements the building blocks, verify by
default, and they are test-covered:

- `Oidc/OidcSignatureVerifier`: fetches `jwks_uri` from discovery, caches the
  JWKS, verifies the JWS with `JWSVerifier::verifyWithKeySet`, and enforces the
  `alg` header against an **allowlist** via `AlgorithmChecker` (this is what
  blocks `alg=none` and algorithm-substitution). Supported: RS/ES/PS 256/384/512.
- `Oidc/OidcJwks`: extracts `use=sig` keys and derives the cache TTL from the
  provider `Cache-Control`/`Expires` headers (capped at 30 days).
- Tests: `OidcSignatureVerifierTest`, `OidcJwksTest`.

Note: that prototype predates the current backbone. Its `OidcIdToken` is the
old static/manual-base64 shape, whereas backbone's `OidcIdToken` is the
JOSE-based instance (clock-injected, `ClaimCheckerManager` for iss/aud/exp).
The follow-up PR must reconcile the two: verifier for the signature, the
backbone `OidcIdToken` for the claim checks.

Conclusion for the audit question: **yes, ID token signature verification is
designed and prototyped.** It is intentionally out of the current login stack
(backbone -> pkce -> ... -> tuning), and tied to the response_type / hybrid
work, where verification becomes mandatory (implicit/hybrid deliver the token
through the user agent, so the TLS-substitution argument no longer applies).

## 4. Proposed rule and its feasibility

Intended rule:

- TLS verification guaranteed  -> ID token signature verification optional.
- `verify_peer: false` / `verify_host: false` -> signature verification mandatory.

### 4.1 Why the literal rule is hard to implement

`HttpClientInterface` is opaque. There is no portable way, at runtime, to read
`verify_peer` / `verify_host` off an arbitrary client. The production client is
typically wrapped (`TraceableHttpClient` -> `RetryableHttpClient` ->
`ScopingHttpClient` -> transport), the flags live in private `defaultOptions`,
and per-request overrides can change them per call. A generic, custom, or
decorated client exposes nothing.

Introspection options and why they are fragile:

- **DI compiler pass** reading `framework.http_client.default_options.verify_peer`
  (and scoped-client config): couples SecurityBundle to FrameworkBundle's
  http_client config shape, misses custom clients, decorators, env-var driven
  options, and per-request overrides. Brittle.
- **Runtime probe** (issue a request and inspect): not reliable and has side
  effects.

So a faithful "detect the flag and react" cannot be built on the HttpClient
contract.

### 4.2 Recommended realization

Achieve the *intent* of the rule without needing to read the flag:

**Primary: verify the ID token signature by default, with an explicit,
documented opt-out.**

- New option `id_token_signature.required` (default `true`).
- When `true`: verify via `OidcSignatureVerifier`, then run the existing claim
  checks on the verified claims.
- When `false`: current decode-only path; document it as safe **only** when the
  token-endpoint connection verifies TLS.

This covers the dangerous case for free: if TLS is off and the deployer did not
explicitly opt out, verification is on and the forgery fails. If they both opt
out AND disable TLS, that is an explicit, documented footgun, no worse than
today but now a deliberate two-step choice instead of a silent default.

**Reinforcement (optional): component-owned TLS posture.**

Give the OIDC token/discovery/jwks requests a dedicated scoped HTTP client with
`verify_peer`/`verify_host` forced to `true`, independent of the app's global
client. Then the back-channel TLS guarantee is owned by the component, and the
signature check becomes genuinely optional hardening rather than the only line
of defense. This is the closest practical equivalent of "react to the flag":
instead of detecting an unsafe client, the component refuses to use one.

Recommendation: ship **verify-by-default** (primary) and consider the scoped
client (reinforcement) in the same PR.

## 5. Integration design (follow-up PR)

Config (OidcLoginFactory):

```yaml
oidc_login:
    # ...
    id_token_signature:
        required: true            # default; false = decode only (TLS-trust)
        algorithms: ['RS256']     # allowlist; never includes "none"
```

Wiring:

- Register `OidcSignatureVerifier` per firewall (args: algorithms, discovery,
  jwks cache pool, http client, jwks TTL). Reuse the firewall `OidcDiscovery`.
- Inject it (nullable) into `OidcLoginAuthenticator`.

Flow change in `authenticate()`:

```php
if ($this->signatureVerifier) {
    $idTokenClaims = $this->signatureVerifier->verify($tokenData['id_token']); // verifies + returns claims
} else {
    $idTokenClaims = $this->idToken->decode($tokenData['id_token']);
}
$this->idToken->validateClaims($idTokenClaims, $issuer, $this->clientId, $storedNonce);
```

Algorithm safety (must-haves, already in the prototype):

- Enforce the `alg` header against the configured allowlist (`AlgorithmChecker`)
  so `alg=none` and unexpected algorithms are rejected.
- Never build an HMAC key from an RSA/EC public key (classic HS256-vs-RS256
  confusion). Restricting the allowlist to the asymmetric family the provider
  actually publishes prevents it.

## 6. Test plan

- Forged/invalid signature is rejected (`required: true`).
- `alg: none` rejected.
- `alg` outside the allowlist rejected.
- Valid signature accepted; claims returned equal the payload.
- JWKS cache: keys fetched once, TTL from headers, capped.
- `required: false`: behavior identical to the current decode path.
- Key rotation: unknown `kid` triggers a JWKS refetch (design choice to settle).

## 7. Recommendation and sequencing

1. Land the current login stack (#64954 and the #15-#20 layers) as is: it is
   spec-compliant and safe under the default TLS-verifying client.
2. In the meantime, ship the Finding 1 hardening (roles/identifier not
   mass-assignable) on the backbone: done, see PR #64954.
3. Follow-up PR (this study): add signature verification, **verify-by-default**,
   built on the `oidc-response-type` prototype (`OidcSignatureVerifier` /
   `OidcJwks`), reconciled with backbone's JOSE-based `OidcIdToken`. Optionally
   add the component-owned scoped HTTP client.
4. Documentation: state explicitly that disabling signature verification is only
   safe when the token-endpoint connection verifies TLS.

## 8. Open questions for reviewers

- Default `true` for `id_token_signature.required`? (Recommended: yes.)
- Ship the dedicated scoped HTTP client, or document the TLS requirement only?
- Handle `kid`-driven JWKS refetch on rotation now or later?
- Merge the two `OidcIdToken` shapes (backbone JOSE vs prototype static) as part
  of this PR.
