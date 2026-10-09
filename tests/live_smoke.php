<?php
// php tests/live_smoke.php example.com   (key in SDA_API_KEY; uses one lookup)
require __DIR__ . '/../src/ApiException.php';
require __DIR__ . '/../src/Client.php';

use SellDataToAI\{Client, ApiException};

$c = new Client((string) getenv('SDA_API_KEY'));
$s = $c->score($argv[1] ?? 'example.com');
echo $s['domain'], ' ', $s['data_asset_score'], ' ', $s['grade'], PHP_EOL;

try {
    (new Client('wrong-key'))->usage();
} catch (ApiException $e) {
    echo 'expected error: ', $e->status, ' ', $e->errorCode, PHP_EOL;
}
