<?php

declare(strict_types=1);

namespace App\Domain\Router;

use App\Domain\Participant\Participant;
use App\Application\Settings\SettingsInterface;
use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Log\LoggerInterface;

class RouterService
{
    private $settings;
    private $logger;
    private $tableName;

    public function __construct(SettingsInterface $settings, LoggerInterface $logger)
    {
        $this->settings = $settings;
        $this->logger = $logger;
        $this->tableName = $settings->get('db_table');
    }

    /**
     * 参加割り当て (Assign)
     */
    public function assign(string $experimentId, string $browserId, array $properties): array
    {
        // 1. 設定の取得
        $experiments = $this->settings->get('experiments');
        if (!isset($experiments[$experimentId])) {
            return ['status' => 'error', 'message' => 'Experiment ID not found'];
        }
        if (!$experiments[$experimentId]["enable"]) {
            return ['status' => 'error', 'message' => 'Experiment is disabled'];
        }
        $config = $experiments[$experimentId]['config'];

        // 2. 既存参加者の確認 (レジューム機能)
        $participant = Participant::where('experiment_id', $experimentId)
            ->where('browser_id', $browserId)
            ->first();

        if ($participant) {
            $this->logger->info("Resume participant: $browserId");
            $participant->last_heartbeat = new \DateTime();
            $participant->save();
            return $this->getCurrentStepResponse($participant, $config);
        }

        // 3. Access Control
        if (isset($config['access_control']['condition'])) {
            // 条件ツリーを評価 (true = 許可, false = 拒否)
            $isAllowed = $this->evaluateCondition($config['access_control']['condition'], $properties);

            if (!$isAllowed) {
                return [
                    'status' => 'ok',
                    'url' => $config['access_control']['deny_redirect'] ?? null,
                    'message' => 'Access denied'
                ];
            }
        }

        // 4. ルーティング (ハートビート & ハードキャップ)
        $groups = $config['groups'];
        $assignmentStrategy = $config['assignment_strategy'] ?? 'minimum_count';
        
        // 各群の現在人数(完了 + アクティブ)を集計
        $heartbeatInterval = $config['heartbeat_intervalsec'] ?? 0;
        $activeLimit = null;
        if ($heartbeatInterval >= 1) {
            $activeLimit = (new \DateTime())->modify("-{$heartbeatInterval} seconds");
        }
        
        $active_counts = [];
        $total_counts = [];
        foreach (array_keys($groups) as $group) {
            $query = Participant::where('experiment_id', $experimentId)
                ->where('condition_group', $group);
            
            $total_counts[$group] = $query->count();

            if ($activeLimit) {
                $query->where(function ($q) use ($activeLimit) {
                    $q->where('status', 'completed')
                          ->orWhere('last_heartbeat', '>=', $activeLimit);
                });
                $active_counts[$group] = $query->count();
            } else {
                $active_counts[$group] = $total_counts[$group];
            }
        }

        // 割り当て可能なグループを探す
        $candidates = [];
        foreach ($groups as $group => $condConfig) {
            if ($active_counts[$group] < $condConfig['limit']) {
                $candidates[] = [
                    "group" => $group,
                    "active_count" => $active_counts[$group],
                    "total_count" => $total_counts[$group]
                ];
            }
        }

        if (empty($candidates)) {
            return [
                'status' => 'ok', 
                'url' => $config['fallback_url'], 
                'message' => 'Full'
            ];
        }

        // 割り当て戦略
        $targetGroup = null;
        if ($assignmentStrategy === 'minimum') {
            usort($candidates, function($a, $b) {
                $cmp1 = $a['active_count'] <=> $b['active_count'];
                if ($cmp1 !== 0) {
                    return $cmp1;
                }
                return $a['total_count'] <=> $b['total_count'];
            });
            $targetGroup = $candidates[0]['group'];
        } else {
            $targetGroup = $candidates[array_rand($candidates)]['group'];
        }

        // 5. 保存
        $participant = new Participant();
        $participant->experiment_id = $experimentId;
        $participant->browser_id = $browserId;
        $participant->condition_group = $targetGroup;
        $participant->current_step_index = 0;
        $participant->status = 'assigned';
        $participant->metadata = $properties;
        $participant->save();

        return $this->getCurrentStepResponse($participant, $config);
    }

    /**
     * 条件ツリーを再帰的に評価する
     */
    private function evaluateCondition(array $condition, array $properties): bool
    {
        // ALL_OF (AND)
        if (isset($condition['all_of'])) {
            foreach ($condition['all_of'] as $subCondition) {
                // 一つでもfalseなら全体としてfalse (短絡評価)
                if (!$this->evaluateCondition($subCondition, $properties)) {
                    return false;
                }
            }
            return true;
        }

        // ANY_OF (OR)
        if (isset($condition['any_of'])) {
            foreach ($condition['any_of'] as $subCondition) {
                // 一つでもtrueなら全体としてtrue (短絡評価)
                if ($this->evaluateCondition($subCondition, $properties)) {
                    return true;
                }
            }
            return false;
        }

        // NOT
        if (isset($condition['not'])) {
            return !$this->evaluateCondition($condition['not'], $properties);
        }

        // 末端のルール評価
        return $this->checkRule($condition, $properties);
    }

    /**
     * 個別のルール(Regex, Fetch)を評価する
     */
    private function checkRule(array $rule, array $properties): bool
    {
        $type = $rule['type'] ?? '';
        $result = false;

        if ($type === 'regex') {
            $val = $properties[$rule['field']] ?? '';
            $result = (bool)preg_match('/' . $rule['pattern'] . '/', (string)$val);
        
        } elseif ($type === 'fetch') {
            $url = $this->resolvePlaceholders($rule['url'], $properties);
            $method = $rule['method'] ?? 'GET';
            $headers = $this->resolvePlaceholders($rule['headers'] ?? [], $properties);
            $headers['Content-Type'] = 'application/json';
            $body = $this->resolvePlaceholders($rule['body'] ?? [], $properties);
            $expectedStatus = $rule['expected_status'] ?? null; // ステータスコード指定があれば優先

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            // タイムアウト設定などを入れたほうが安全
            curl_setopt($ch, CURLOPT_TIMEOUT, 10); 
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            // サーバーエラー系は一律false (拒否) とする運用の場合
            if ($httpCode >= 500) {
                return false; 
            }

            if ($expectedStatus) {
                // ステータスコードが指定値と一致するか
                $result = ($httpCode == $expectedStatus);
            } else {
                // JSONレスポンスのチェック (後方互換性のため)
                $json = json_decode($response, true);
                if ($json && isset($json['return'])) {
                    $result = (bool)$json['return'];
                } else {
                    $result = ($httpCode >= 200 && $httpCode < 300);
                }
            }
        }

        // ルール単体に "negate": true がある場合の反転処理
        // (notラッパーを使わずに単体ルールで反転したい場合のサポート)
        if (!empty($rule['negate'])) {
            return !$result;
        }

        return $result;
    }

    /**
     * 次のステップへ (Next)
     */
    public function next(string $experimentId, string $browserId, array $properties): array
    {
        $experiments = $this->settings->get('experiments');
        $config = $experiments[$experimentId]['config'] ?? null;
        if (!$config) return ['status' => 'error', 'message' => 'Config not found'];

        $participant = Participant::where('experiment_id', $experimentId)
            ->where('browser_id', $browserId)
            ->first();

        if (!$participant) return ['status' => 'error', 'message' => 'Participant not found'];

        // 現在のステップ情報を取得
        $groupConfig = $config['groups'][$participant->condition_group];
        $steps = $groupConfig['steps'];
        $currentIndex = $participant->current_step_index;

        // 次へ進める
        // ※ 本来は分岐ロジック(transitions)をここで評価してジャンプ先を決めるが、
        //    今回は簡易的にインデックスを+1する実装とする。
        //    (config.jsoncの複雑なtransitionsに対応するには、ここを拡張する)
        
        $nextIndex = $currentIndex + 1;

        if ($nextIndex >= count($steps)) {
            // 完了
            $participant->status = 'completed';
            $participant->save();
            return ['status' => 'ok', 'url' => null, 'message' => 'Experiment completed'];
        }

        $participant->current_step_index = $nextIndex;
        $participant->last_heartbeat = new \DateTime(); // ついでに生存更新
        $participant->save();

        return $this->getCurrentStepResponse($participant, $config);
    }

    /**
     * ハートビート更新
     */
    public function heartbeat(string $experimentId, string $browserId): void
    {
        $participant = Participant::where('experiment_id', $experimentId)
            ->where('browser_id', $browserId)
            ->first();
        
        if ($participant) {
            $participant->last_heartbeat = new \DateTime();
            $participant->save();
        }
    }

    /**
     * 現在のステップのURLを返すヘルパー
     */
    private function getCurrentStepResponse(Participant $participant, array $config): array
    {
        $group = $participant->condition_group;
        $steps = $config['groups'][$group]['steps'];
        $index = $participant->current_step_index;

        if (!isset($steps[$index])) {
            return ['status' => 'ok', 'url' => null, 'message' => 'Completed'];
        }

        $step = $steps[$index];
        // 文字列ならそのままURL、オブジェクトならurlプロパティ
        $url = is_string($step) ? $step : ($step['url'] ?? null);

        // URLにパラメータを付与 (browser_idなど)
        if ($url) {
            $query = parse_url($url, PHP_URL_QUERY);
            $url .= ($query ? '&' : '?') . 'browser_id=' . $participant->browser_id;
        }

        return [
            'status' => 'ok',
            'url' => $url,
            'message' => null
        ];
    }

    /** * ${propertiesのキー}形式のプレースホルダーを解決するヘルパー
     */
    private function resolvePlaceholders($input, array $properties)
    {
        // 配列の場合は再帰的に処理
        if (is_array($input)) {
            $result = [];
            foreach ($input as $key => $value) {
                $result[$key] = $this->resolvePlaceholders($value, $properties);
            }
            return $result;
        }

        // 文字列でない場合はそのまま返す
        if (!is_string($input)) {
            return $input;
        }

        // 文字列の場合はプレースホルダーを置換
        return preg_replace_callback('/\$\{([^}]+)\}/', function ($matches) use ($properties) {
            $key = $matches[1];
            return (string)($properties[$key] ?? $matches[0]);
        }, $input);
    }}