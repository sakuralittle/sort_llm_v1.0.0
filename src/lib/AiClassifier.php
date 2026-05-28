<?php
declare(strict_types=1);

/**
 * Ollama 公文分類器
 * ----------------------------------------------------------
 * 由 relay/run_classify.php 重構而來：
 *   - 同步 cURL POST 到 /api/chat
 *   - 取 message.content
 *   - 白名單比對 target_units，命中即視為 'ok'
 * ----------------------------------------------------------
 */
final class AiClassifier
{
    private string $baseUrl;
    private string $model;
    private int    $timeout;
    /** @var string[] */
    private array  $targetUnits;

    public function __construct(array $aiConfig)
    {
        $this->baseUrl     = rtrim((string)$aiConfig['base_url'], '/');
        $this->model       = (string)$aiConfig['model'];
        $this->timeout     = (int)($aiConfig['timeout_sec'] ?? 90);
        $this->targetUnits = $aiConfig['target_units'] ?? [];
    }

    public function model(): string   { return $this->model; }
    public function baseUrl(): string { return $this->baseUrl; }

    /**
     * 對單筆公文做分類
     *
     * @param array{來文機關:string,來文字:string,來文主旨:string} $doc
     * @return array{status:'ok'|'unknown'|'error', pred:string, raw:string, ms:float}
     */
    public function classify(array $doc): array
    {
        $kindLine = '';
        if (!empty($doc['來文字'])) {
            $kindLine = "來文字：{$doc['來文字']}\n";
        }
        $user = "來文機關：{$doc['來文機關']}\n"
              . $kindLine
              . "來文主旨：{$doc['來文主旨']}";

        $r = $this->httpPost($this->baseUrl . '/api/chat', [
            'model'    => $this->model,
            'stream'   => false,
            'messages' => [['role' => 'user', 'content' => $user]],
            'options'  => ['temperature' => 0, 'num_predict' => 12],
        ]);

        if ($r['code'] !== 200) {
            return [
                'status' => 'error',
                'pred'   => '',
                'raw'    => $r['err'] !== '' ? $r['err'] : ('HTTP ' . $r['code']),
                'ms'     => $r['ms'],
            ];
        }

        $data = json_decode((string)$r['body'], true);
        $raw  = trim($data['message']['content'] ?? '');

        foreach ($this->targetUnits as $u) {
            if (mb_strpos($raw, $u) !== false) {
                return ['status' => 'ok', 'pred' => $u, 'raw' => $raw, 'ms' => $r['ms']];
            }
        }
        return ['status' => 'unknown', 'pred' => '', 'raw' => $raw, 'ms' => $r['ms']];
    }

    /**
     * 健康檢查：GET /api/tags 並確認 model 存在
     * @return array{ok:bool, msg:string}
     */
    public function ping(): array
    {
        $ch = curl_init($this->baseUrl . '/api/tags');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) {
            return ['ok' => false, 'msg' => "AI 主機無法連線（HTTP $code, $err）"];
        }
        $tags  = json_decode((string)$body, true);
        $names = array_map(fn($m) => $m['name'] ?? '', $tags['models'] ?? []);
        if (!in_array($this->model, $names, true)) {
            return ['ok' => false, 'msg' => "AI 主機可連，但找不到模型 {$this->model}（現有：" . implode(', ', $names) . ")"];
        }
        return ['ok' => true, 'msg' => 'AI 主機與模型 OK'];
    }

    /**
     * @return array{ms:float, code:int, body:string, err:string}
     */
    private function httpPost(string $url, array $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $t0   = microtime(true);
        $resp = curl_exec($ch);
        $ms   = (microtime(true) - $t0) * 1000;
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [
            'ms'   => $ms,
            'code' => (int)$code,
            'body' => $resp === false ? '' : (string)$resp,
            'err'  => (string)$err,
        ];
    }
}
