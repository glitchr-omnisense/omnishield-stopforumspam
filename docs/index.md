# omnishield/stopforumspam

StopForumSpam's public database as an Omnishield list: are this IP address, this e-mail, this name
reported for abuse, at a confidence threshold of the site's - and, with a key, a spammer reported
back. The gateway calls `POST https://api.stopforumspam.org/api` to look up (no key;
`europe.stopforumspam.org` and `us.stopforumspam.org` serve the same) and
`POST https://www.stopforumspam.com/add` to report (an API key). Free for non-commercial use, for
the people who submit a form - never as a firewall (the terms, below).

## Installation

```sh
composer require omnishield/stopforumspam
```

PHP 8.2 or later, `omnisense/omnishield` and `symfony/http-client` (give the factory your
application's client, a `MockHttpClient` in a test). No key to look up; one to report.

## Credentials

None to look up. To report, an **API key** of a StopForumSpam account (`api_key`); without one,
`report()` is a `NotSupportedException` and `capabilities()->reports` is false.

## The sources

Written from StopForumSpam's [API usage](https://www.stopforumspam.com/usage) page as it read on
2026-10-07 - the parameters (`ip`, `email`, `emailhash`, `username`, `json`, `expire`, `nobadall`,
`notorexit`, `badtorexit`), the JSON answer, the confidence, the regional servers, the submission
form (`/add`) and the terms - and from its answers that day.

## Options

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `threshold` | number, 0 to 100 | `50` | no | the confidence from which a part that appears counts; outside 0..100: `InvalidConfigException` |
| `send` | list of `ip`, `email`, `name`, or one comma-separated string | `[ip, email, name]` | no | what of the visitor is sent - and `Capabilities::$reads`; another word: `InvalidConfigException` |
| `hash_email` | bool | `true` | no | the e-mail as its MD5 (`emailhash`), lower-cased and trimmed first; StopForumSpam then normalises nothing (Gmail's dots and `+tags`): `false` sends it in clear and catches more |
| `expire` | int, days | `null` | no | a sighting older than that does not count (`expire`, at least 1) |
| `tor` | string | `neutral` | no | a Tor exit node: `neutral` as listed, `ignore` never counts (even if reported), `block` always counts - decided here, not by `notorexit`/`badtorexit`; another: `InvalidConfigException` |
| `wildcards` | bool | `true` | no | `false` leaves out StopForumSpam's own lists of hostile domains, names and networks (`nobadall`) |
| `api_key` | string | `null` | no | only to report |
| `base_uri` | string | `https://api.stopforumspam.org/api` | no | `https://europe.stopforumspam.org/api` or `https://us.stopforumspam.org/api` to stay in a region |
| `report_uri` | string | `https://www.stopforumspam.com/add` | no | |

`new StopforumspamGatewayFactory(?HttpClientInterface $http = null)`: the application's HTTP
client.

## Capabilities

| | |
|---|---|
| is this identity known (`ReputationInterface::lookup()`) | yes: the parts of `send` |
| reports | with `api_key`: the gateway's own `report(Identity $identity, string $evidence = '')` |
| a score | `confidence`, 0 to 100; `frequency` |
| is this token valid, is this content spam | no |
| third party, cookies | StopForumSpam; no cookie |

## The lookup

One `POST` - so that nothing of the visitor shows in an address a proxy logs - with the parts to
send and `json`. For each part StopForumSpam answers `appears`, `frequency` (255 for its own
blacklists), `lastseen` (UTC), `confidence` (a percentage) and, for an address, `torexit`, `asn`,
`country`.

| `Reputation` | |
|---|---|
| `known` | a part **appears** and its **confidence reaches the threshold** - or a Tor exit node, with `tor: block` |
| `frequency` | the highest of the parts |
| `confidence` | the highest of the parts that appear |
| `lastSeen` | the latest sighting |
| `reasons` | the parts that count: `ip`, `email`, `name`, and `tor` beside `ip` for a Tor exit node |
| `data` | StopForumSpam's answer, part by part (`ip`, `email`, `name`) |

Why both conditions: StopForumSpam gives a confidence even when a part no longer appears (seen
with `expire=1`: `appears: 0`, `confidence: 95.29`), and an address reported three times months
ago appears with a confidence under 1 (1.2.3.4 on 2026-10-07: 0.26).

A part StopForumSpam cannot read (`{"error": "invalid ip address"}`) is left out, its error in
`data`. `{"success": 0, "error": ...}` is a `ProviderException`; no answer, HTTP 5xx or 429 (the
rate exceeded) an `UnreachableException`.

## Reporting

```php
$list->report(new Identity($ip, $email, $name), evidence: $whatTheyPosted);
```

`POST https://www.stopforumspam.com/add` with `username`, `ip_addr`, `email`, `evidence`,
`api_key` - all three parts required, StopForumSpam takes nothing less. HTTP 200 is a report
taken; any other status a refusal, its reason read from the page's paragraph: 401 and 403 an
`InvalidKeyException` (the key), the others a `ProviderException`. Without `api_key`:
`NotSupportedException`, and `capabilities()->reports` is false.

## The terms

From the usage page, 2026-10-07: "Your use of this data and supporting software is
non-commercial"; "The data here can be accessed by any commerical system provided that it is used
for internal use only"; "API queries are limited to 100,000 per day"; "This API is NOT to be used
as a general software firewall. Checking every incoming connection against the API will be treated
as a denial of service attack". Data offered as is, without guarantee.

## Example

A Tor exit node and a hashed e-mail, StopForumSpam's answer given by a `MockHttpClient` that prints
what is sent, then the same address with `tor: ignore` (run in omnishield's workspace on
2026-10-26, offline):

```php
<?php

require 'vendor/autoload.php';

use Omnishield\Model\Identity;
use Omnishield\Stopforumspam\StopforumspamGatewayFactory;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

$http = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
    echo $method, ' ', $url, ': ', $options['body'], "\n";

    return new MockResponse(json_encode(['success' => 1,
        'ip' => ['value' => '185.220.101.1', 'appears' => 1, 'frequency' => 91, 'lastseen' => '2026-10-24 21:14:03', 'confidence' => 95.29, 'torexit' => 1],
        'emailhash' => ['value' => md5('someone@example.org'), 'appears' => 0, 'frequency' => 0],
    ]));
});
$list = (new StopforumspamGatewayFactory($http))->create(['threshold' => 50]);
$reputation = $list->lookup(new Identity('185.220.101.1', 'Someone@example.org'));
printf("known: %s, reasons: %s, frequency %d, confidence %.2f, last seen %s\n", var_export($reputation->known, true), implode(', ', $reputation->reasons), $reputation->frequency, $reputation->confidence, $reputation->lastSeen->format(DATE_ATOM));
$ignoring = (new StopforumspamGatewayFactory($http))->create(['tor' => 'ignore', 'send' => 'ip']);
$known = $ignoring->lookup(new Identity('185.220.101.1'))->known;
echo 'tor: ignore -> known: ', var_export($known, true), "\n";
```

```
POST https://api.stopforumspam.org/api: ip=185.220.101.1&emailhash=a70eaed09677478b42b11fc7a04f4c87&json=
known: true, reasons: ip, tor, frequency 91, confidence 95.29, last seen 2026-10-24T21:14:03+00:00
POST https://api.stopforumspam.org/api: ip=185.220.101.1&json=
tor: ignore -> known: false
```

In Symfony:

```yaml
omnishield:
    gateways:
        reported: { factory: stopforumspam, options: { threshold: 80, send: 'ip,email', api_key: '%env(default::STOPFORUMSPAM_API_KEY)%' } }
```

## Errors

| Exception | When |
|---|---|
| `UnreachableException` | no answer, HTTP 5xx, 429 (the daily limit) |
| `ProviderException` | `{"success": 0, "error": ...}`; an answer that is not JSON (an HTTP 401 or 403 to a lookup included: the lookup sends no key); a report refused (HTTP other than 200), its reason from the page's paragraph |
| `InvalidKeyException` | a report answered HTTP 401 or 403: the API key |
| `NotSupportedException` | `report()` without `api_key` |
| `InvalidConfigException` | an option out of its range; a report without all three parts |

An identity with nothing to send (no part of `send` given) is unknown, and nothing is asked.

## Verified, and not

| | |
|---|---|
| The family's conformance suite (`Omnishield\Testing\GatewayContractTests`) | **green on 2026-10-25**, offline: `Tests/StopforumspamContractTest.php` on the recorded answers; an error object in an answer no longer warns (array to string), a refused key on `/add` (401/403) is an `InvalidKeyException` |
| Lookups without a key | **done on 2026-10-07**, against `api.stopforumspam.org` (`bare --live` and the recorded answers): the documentation's address, e-mail, hashed e-mail and name (none appears today), a Tor exit node (185.220.101.1: appears, frequency 91, confidence 95.29, `torexit`), its sighting past `expire=1`, an address reported long ago (1.2.3.4), a blacklisted e-mail (testing@xrumer.ru: 255, 99.95), a malformed address; `europe.stopforumspam.org` answers the same |
| `notorexit` | **seen not to work as documented** on 2026-10-07: the Tor exit node kept `appears: 1` - hence the `tor` option, decided here |
| Reporting (`/add`) | **not verified in real: no API key.** Its request follows the documentation; its answers (200 empty, 403 with a paragraph) too |
| The 100,000-a-day limit and its 503 | not reached |

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by StopForumSpam. It reaches their service on behalf of whoever runs it, and it does not
redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT licence covers
this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with StopForumSpam's terms
([stopforumspam.com/legal](https://www.stopforumspam.com/legal)), for obtaining whatever they
require (an account, a key, a licence or subscription, an attribution or a citation, a rate limit to
respect), and for any use made of what it returns. The authors publish it free of charge, make no
commercial use of it, and accept no liability for how it is used (see the package's LICENSE).
