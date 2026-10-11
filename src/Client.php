<?php

declare(strict_types=1);

namespace SellDataToAI;

/**
 * Client for the AI Data Value Score API at https://www.selldatatoai.com/api/
 * Uses ext-curl and ext-json only. Every method returns the decoded JSON as an array.
 */
final class Client
{
    public const VERSION = '1.0.1';
    public const DEFAULT_BASE = 'https://www.selldatatoai.com/api/v1';
    public const BATCH_MAX = 100;

    private string $apiKey;
    private string $baseUrl;
    private int $timeout;
    /** @var int seconds between polls */
    public int $pollInterval = 5;
    /** @var int longest wait for one batch, in seconds */
    public int $maxWait = 300;

    public function __construct(string $apiKey, string $baseUrl = self::DEFAULT_BASE, int $timeout = 60)
    {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('An API key is required. Plans: https://www.selldatatoai.com/pricing/');
        }
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = $timeout;
    }

    /** Score one company domain. Full URLs are accepted. */
    public function score(string $domain): array
    {
        $domain = trim($domain);
        if ($domain === '') {
            throw new \InvalidArgumentException('domain is empty');
        }
        return $this->request('GET', '/score', ['domain' => $domain]);
    }

    /** Submit 1 to 100 domains. One lookup per valid, unique domain. */
    public function submitBatch(array $domains): array
    {
        $n = count($domains);
        if ($n === 0 || $n > self::BATCH_MAX) {
            throw new \InvalidArgumentException('A batch takes 1 to ' . self::BATCH_MAX . ' domains');
        }
        return $this->request('POST', '/score/batch', [], ['domains' => array_values($domains)]);
    }

    /** Poll a batch. Polling is free. */
    public function getBatch(string $id): array
    {
        return $this->request('GET', '/score/batch', ['id' => $id]);
    }

    /** Poll until the batch is done or maxWait passes. */
    public function waitForBatch(string $id): array
    {
        $stop = time() + $this->maxWait;
        while (true) {
            $b = $this->getBatch($id);
            if (($b['status'] ?? '') === 'done') {
                return $b;
            }
            if (time() > $stop) {
                throw new ApiException(0, 'batch_timeout', "Batch $id still processing", $b);
            }
            sleep($this->pollInterval);
        }
    }

    /** Score any number of domains in batches of 100; items come back in input order. */
    public function scoreMany(array $domains): array
    {
        $out = [];
        foreach (array_chunk(array_values($domains), self::BATCH_MAX) as $chunk) {
            $b = $this->submitBatch($chunk);
            if (($b['status'] ?? '') !== 'done') {
                $b = $this->waitForBatch($b['id']);
            }
            foreach ($b['results'] ?? [] as $item) {
                $out[] = $item;
            }
        }
        return $out;
    }

    /** Plan, lookups used this month and lookups remaining. */
    public function usage(): array
    {
        return $this->request('GET', '/usage');
    }

    private function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $url = $this->baseUrl . $path . ($query ? '?' . http_build_query($query) : '');
        $headers = [
            'X-API-Key: ' . $this->apiKey,
            'Accept: application/json',
            'User-Agent: selldatatoai-php/' . self::VERSION . ' (+https://www.selldatatoai.com)',
        ];
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CUSTOMREQUEST => $method,
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $text = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($text === false) {
            throw new ApiException(0, 'transport', $err ?: 'request failed');
        }
        $data = json_decode((string) $text, true);
        if ($status >= 200 && $status < 300 && is_array($data)) {
            return $data;
        }
        $code = is_array($data) ? ($data['error'] ?? null) : null;
        $msg = is_array($data) ? ($data['message'] ?? "HTTP $status") : "HTTP $status";
        throw new ApiException($status, $code, $msg, $data ?? $text);
    }
}
