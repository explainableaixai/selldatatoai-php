# selldatatoai-php

PHP client for the [Data Asset Score by Sell Data to AI](https://www.selldatatoai.com/). It asks one question about a company website, how much of the data AI buyers want does this company likely hold, and returns the answer as a score from 0 to 100 with the evidence behind it.

- PHP 7.4 or newer, with `ext-curl` and `ext-json`.
- No Composer dependencies.
- PSR-4 autoloading under the `SellDataToAI\` namespace.
- Every method returns the decoded JSON as an associative array.

## Install

Add the repository to `composer.json` and require the package:

```json
{
    "repositories": [
        {"type": "vcs", "url": "https://github.com/explainableaixai/selldatatoai-php"}
    ],
    "require": {
        "selldatatoai/selldatatoai": "^1.0"
    }
}
```

```bash
composer update selldatatoai/selldatatoai
```

Without Composer, copy `src/` into your project and include the two files:

```php
require __DIR__ . '/src/ApiException.php';
require __DIR__ . '/src/Client.php';
```

## First call

```php
<?php
require 'vendor/autoload.php';

use SellDataToAI\Client;

$client = new Client(getenv('SDA_API_KEY'));

$s = $client->score('example.com');

printf("%s scored %d (%s), status %s\n", $s['domain'], $s['data_asset_score'], $s['grade'], $s['status']);
foreach ($s['data_systems'] as $sys) {
    printf("  runs %s: %s\n", $sys['system'], $sys['data_type']);
}
printf("  %d years online, %d before 2023\n", $s['history']['years_online'], $s['history']['pre_ai_years']);
```

Pass a bare domain or any URL on the site. `https://www.example.com/team` and `example.com` give the same answer.

## What comes back

```php
[
    'domain' => 'promega.com',
    'data_asset_score' => 88,
    'grade' => 'A',
    'status' => 'active',
    'verified_active' => true,
    'iab_category' => 'Business and Finance > Industries > Pharmaceutical Industry',
    'country' => 'United States',
    'history' => ['first_seen_year' => 1993, 'years_online' => 33, 'founded_year' => null, 'pre_ai_years' => 30],
    'pre_ai_archive_likely' => true,
    'data_systems' => [
        ['system' => 'Jira / Confluence', 'data_type' => 'Work tickets and internal wiki', 'type' => 'work_tickets'],
        ['system' => 'Miro', 'data_type' => 'Design files', 'type' => 'design'],
    ],
    'likely_data_assets' => [
        ['type' => 'support_tickets', 'label' => 'Support tickets and chat transcripts'],
        ['type' => 'code_history', 'label' => 'Code with commit and issue history'],
    ],
    'strengths' => ['History', 'Knowledge assets', 'Operational systems'],
    'data_coverage' => 'full',
    'in_index' => true,
    'index_size' => 102000000,
    'checked_at' => '2026-10-08T21:30:00+00:00',
    'cached' => true,
]
```

Key by key:

- `data_asset_score`: 0 to 100. `grade`: A to E from the score.
- `status`: `active`, `winding_down_or_acquired`, `parked`, `unreachable` or `error`.
- `verified_active`: the domain resolves, is not expired and the site is not a parking or error page.
- `iab_category` and `country`: from an index of 102 million domains, 99.99% of the active internet.
- `history`: first year seen, years online, stated founding year and years online before 2023.
- `data_systems`: systems of record the company runs, each with the record type it holds.
- `likely_data_assets`: record types the company probably holds.
- `data_coverage`: `full`, `partial` or `limited`.
- `cached`: the result was taken in the last 30 days.

The [Data Asset Score API reference](https://www.selldatatoai.com/api/) documents every field and endpoint.

## Methods

| Method | Endpoint | Lookups |
|---|---|---|
| `score(string $domain): array` | `GET /api/v1/score` | 1 |
| `submitBatch(array $domains): array` | `POST /api/v1/score/batch` | 1 per valid, unique domain |
| `getBatch(string $id): array` | `GET /api/v1/score/batch?id=` | 0 |
| `waitForBatch(string $id): array` | polls until done | 0 |
| `scoreMany(array $domains): array` | batches of 100, input order | 1 per valid, unique domain |
| `usage(): array` | `GET /api/v1/usage` | 0 |

Constructor: `new Client(string $apiKey, string $baseUrl = Client::DEFAULT_BASE, int $timeout = 60)`. Public properties `$pollInterval` (seconds, default 5) and `$maxWait` (seconds, default 300) control batch polling.

## Errors

Failures throw `SellDataToAI\ApiException`. It has `$status` (HTTP code, 0 for network failures and batch timeouts), `$errorCode` (the API error string) and `$body`.

```php
use SellDataToAI\ApiException;

try {
    $s = $client->score($input);
} catch (ApiException $e) {
    switch ($e->errorCode) {
        case 'invalid_domain':
            error_log("skipping $input");
            break;
        case 'monthly_limit_reached':
            exit("No lookups left this month\n");
        case 'missing_api_key':
        case 'invalid_api_key':
            exit("Check the key and the plan\n");
        default:
            throw $e;
    }
}
```

| HTTP | errorCode | Meaning |
|---|---|---|
| 400 | `invalid_domain` | Not a valid domain |
| 400 | `no_domains`, `too_many_domains` | Batch outside 1 to 100 |
| 401 | `missing_api_key`, `invalid_api_key` | No key, or plan not active |
| 404 | `batch_not_found` | Unknown id, or older than 7 days |
| 410 | `not_available` | Company lists are not served by the API |
| 429 | `monthly_limit_reached` | Monthly lookups used, reset on the 1st (UTC) |
| 429 | `batch_busy` | Three batches already open |

`InvalidArgumentException` is thrown before any request for an empty key, an empty domain or a batch outside 1 to 100.

## Examples

### Scoring a partner form in Laravel

```php
// routes/web.php
use Illuminate\Http\Request;
use SellDataToAI\{Client, ApiException};

Route::post('/partners', function (Request $request) {
    $data = $request->validate(['company' => 'required', 'website' => 'required']);
    $client = new Client(config('services.selldatatoai.key'));

    try {
        $s = $client->score($data['website']);
    } catch (ApiException $e) {
        return back()->withErrors(['website' => $e->status === 400 ? 'Website is not valid' : 'Please try again']);
    }

    $partner = \App\Models\Partner::create($data + [
        'data_asset_score' => $s['data_asset_score'],
        'grade' => $s['grade'],
        'systems' => array_column($s['data_systems'], 'system'),
    ]);

    return redirect()->route('partners.show', $partner);
});
```

Add the key in `config/services.php` as `'selldatatoai' => ['key' => env('SDA_API_KEY')]`.

### A cron script that scores new rows in MySQL

```php
<?php
// php score_new.php   (run every hour from cron)
require 'vendor/autoload.php';

$pdo = new PDO('mysql:host=localhost;dbname=crm', 'user', getenv('DB_PASS'));
$client = new SellDataToAI\Client(getenv('SDA_API_KEY'));

$rows = $pdo->query("SELECT id, website FROM accounts WHERE sda_score IS NULL LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) exit;

$left = $client->usage()['remaining'];
$rows = array_slice($rows, 0, $left);

$items = $client->scoreMany(array_column($rows, 'website'));
$upd = $pdo->prepare("UPDATE accounts SET sda_score = ?, sda_grade = ?, sda_status = ? WHERE id = ?");
foreach ($items as $i => $it) {
    if ($it['status'] !== 'done') continue;
    $r = $it['result'];
    $upd->execute([$r['data_asset_score'], $r['grade'], $r['status'], $rows[$i]['id']]);
}
echo count($items), " scored\n";
```

`scoreMany` keeps input order, so item `$i` belongs to row `$i`.

### WordPress shortcode for an internal dashboard

```php
add_shortcode('sda_score', function ($atts) {
    $domain = sanitize_text_field($atts['domain'] ?? '');
    $cached = get_transient('sda_' . md5($domain));
    if ($cached === false) {
        try {
            $cached = (new SellDataToAI\Client(SDA_API_KEY))->score($domain);
            set_transient('sda_' . md5($domain), $cached, 30 * DAY_IN_SECONDS);
        } catch (SellDataToAI\ApiException $e) {
            return '<span>score unavailable</span>';
        }
    }
    return sprintf('<strong>%d</strong> (%s)', $cached['data_asset_score'], esc_html($cached['grade']));
});
```

Use it on logged-in pages only. A public page would spend lookups on every crawler visit.

## Batches

- 1 to 100 domains per call. Invalid rows return `invalid_domain` and are not charged.
- One lookup per valid, unique domain, at submit.
- Domains scored in the last 30 days are `done` in the first answer; the rest usually within a minute.
- Polling is free. Results keep input order and are kept for 7 days.
- Up to three open batches per key.

## Closing companies and their records

A company that shuts down still has its tickets, mailboxes and code for a while. Some owners want to sell those records before the subscriptions lapse, and brokers want to reach them in time. The [startup graveyard](https://www.selldatatoai.com/startup-graveyard/) page explains what happens to that data, and the guide on [selling data when shutting down](https://www.selldatatoai.com/selling-data-when-shutting-down/) gives owners the order of operations.

In the API, these companies show `status` = `winding_down_or_acquired`. Filter on it to build a watch list.

Company owners who want to know where they stand, before any broker calls, can [check your company](https://www.selldatatoai.com/check-your-company/) on the website.

## Lists are separate

The API scores the domains you send. It never returns lists. Sector files are sold once, each with the top 3,000 US companies by score, from $249 a list. The [company lists by sector](https://www.selldatatoai.com/lists/) page shows all of them, for example the [financial services companies list](https://www.selldatatoai.com/lists/financial-services/). Download links arrive by email and work for 30 days, up to 5 downloads per file.

## Plans

| Plan | Monthly price | Lookups included | Batch endpoint |
|---|---|---|---|
| Basic | $99 | 5,000 | no |
| Pro | $299 | 25,000 | yes |
| Scale | $799 | 100,000 | yes |

All plans are paid; there is no trial. Before you subscribe, the demo on the website lets you run 5 checks a day by hand. Lookups reset on the first day of each month, UTC, and `usage()` shows the current count without using one.

## Running it in production

A few habits keep costs predictable and pages fast:

- **Store results.** Save the array as JSON next to the account row, with `checked_at`. Score again only after 30 days, since the API would return the same result until then and still count a lookup.
- **Score off the request path.** Put new domains on a queue (Laravel queues, Symfony Messenger or a plain cron table) and score them in a worker. A slow site can take seconds to score fresh.
- **Check quota first.** Call `usage()` before a large run and trim the list to what is left, as the cron example does.
- **Log the error code, not just the message.** `invalid_domain` rows need a data fix; `monthly_limit_reached` needs a plan change; a 401 means the key or subscription.
- **Keep the key out of the code.** Read it from the environment or your framework's secret store.

## FAQ

### What is selldatatoai-php?

selldatatoai-php is the PHP client for the Data Asset Score API at selldatatoai.com. It scores company websites from 0 to 100 for the data AI buyers want and returns arrays with grade, status, data systems, likely data assets and history.

### Does it need Guzzle?

No. It uses the curl extension directly, so it drops into any framework or plain script.

### Why does `scoreMany` block?

It waits for each batch to finish before sending the next. Run it from the CLI or a queue worker, never inside a web request with a short timeout.

### Is a cached answer free?

No. Every scored domain counts as one lookup. `cached` only tells you the result is from the last 30 days.

### Are the factor weights public?

Only the factor groups are public, with a page on what each one covers. Weights and rules are kept private.

## Links

- PHP cURL documentation: [php.net](https://www.php.net/manual/en/book.curl.php)
- Composer repositories guide: [getcomposer.org](https://getcomposer.org/doc/05-repositories.md)
- Website: https://www.selldatatoai.com/
- Source: https://github.com/explainableaixai/selldatatoai-php

## License

MIT. Copyright (c) 2026 Alpha Quantum (info@alpha-quantum.com)
