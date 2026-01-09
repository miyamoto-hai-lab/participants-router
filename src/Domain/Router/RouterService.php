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
            $participant->last_heartbeat = new \DateTime(); // ついでに生存更新
            $participant->save();
            return $this->getCurrentStepResponse($participant, $config);
        }

        // 3. Access Control
        if (isset($config['access_control']['rules'])) {
            foreach ($config['access_control']['rules'] as $rule) {
                if ($rule['type'] === 'regex') {
                    $negate = $rule['negate'] ?? false;
                    // metadata (properties) から値を取得して検証
                    $val = $properties[$rule['field']] ?? '';
                    $matched = preg_match('/' . $rule['pattern'] . '/', (string)$val);
                    if ($negate) {
                        $matched = !$matched;
                    }
                    if ($matched) {
                        if ($rule['action'] === 'allow') {
                            break;
                        } else {
                            return [
                                'status' => 'ok',
                                'url' => $config['access_control']['deny_redirect'] ?? null,
                                'message' => 'Access denied'
                            ];
                        }
                    }
                } elseif ($rule['type'] === 'fetch') {
                    $url = $this->resolvePlaceholders($rule['url'], $properties);
                    $method = $rule['method'] ?? 'GET';
                    $headers = $this->resolvePlaceholders($rule['headers'] ?? [], $properties);
                    $headers['Content-Type'] = 'application/json';
                    $body = $this->resolvePlaceholders($rule['body'] ?? [], $properties);
                    $negate = $rule['negate'] ?? false;

                    $ch = curl_init($url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                    $response = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);

                    if ($httpCode > 500 && $httpCode < 600) {
                        continue;
                    }

                    $json = json_decode($response, true);
                    
                    if ($json && isset($json['return'])) {
                        // returnフィールドがある場合は jsonの"return"で判定
                        $return = ['return' => (bool)$json['return']];
                    } else {
                        // 結果がJSONではないまたはreturnフィールドがない場合はHTTPステータスコードで判定
                        $return = ['return' => ($httpCode >= 200 && $httpCode < 300)];
                    }
                    if ($negate) {
                        $return = !$return;
                    }
                    if ($return) {
                        if ($rule['action'] === 'allow') {
                            break;
                        } else {
                            return [
                                'status' => 'ok',
                                'url' => $config['access_control']['deny_redirect'] ?? null,
                                'message' => 'Access denied'
                            ];
                        }
                    }
                }
            }
        }

        // 4. ルーティング (ハートビート & ハードキャップ)
        $conditions = $config['conditions'];
        $assignmentStrategy = $config['assignment_strategy'] ?? 'minimum_count';
        
        // 各群の現在人数(完了 + アクティブ)を集計
        $heartbeatInterval = $config['heartbeat_intervalsec'] ?? 0;
        $activeLimit = null;
        if ($heartbeatInterval >= 1) {
            // modify() needs string like "-180 seconds"
            $activeLimit = (new \DateTime())->modify("-{$heartbeatInterval} seconds");
        }
        
        $active_counts = [];
        $total_counts = [];
        foreach (array_keys($conditions) as $group) {
            $query = Participant::where('experiment_id', $experimentId)
                ->where('condition_group', $group);
            // total_countsの計算
            $total_counts[$group] = $query->count();

            // ハートビート有効時のみ、完了 or 生存でフィルタリング
            // (無効時は全件カウント)
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
        foreach ($conditions as $group => $condConfig) {
            if ($active_counts[$group] < $condConfig['limit']) {
                $candidates[] = [
                    "group" => $group,
                    "active_count" => $active_counts[$group],
                    "total_count" => $total_counts[$group]
                ];
            }
        }

        if (empty($candidates)) {
            // 満員
            return [
                'status' => 'ok', 
                'url' => $config['fallback_url'], 
                'message' => 'Full'
            ];
        }

        // 最小割り当て戦略
        $targetGroup = null;
        if ($assignmentStrategy === 'minimum') {
            // 候補の中で一番人数が少ないものを選ぶ
            usort($candidates, function($a, $b) {
                // active_count が異なる場合は active_count で比較
                $cmp1 = $a['active_count'] <=> $b['active_count'];
                if ($cmp1 !== 0) {
                    return $cmp1;
                }
                // 同点の場合は total_count で比較
                return $a['total_count'] <=> $b['total_count'];
            });
            $targetGroup = $candidates[0]['group'];
        } else {
            // ランダム
            $targetGroup = $candidates[array_rand($candidates)]['group'];
        }

        // 5. 保存
        $participant = new Participant();
        // log_error($this->tableName);
        // $participant->setTable($this->tableName);
        $participant->experiment_id = $experimentId;
        $participant->browser_id = $browserId;
        // $participant->worker_id = $properties['worker_id'] ?? null; // Removed check logic uses metadata
        $participant->condition_group = $targetGroup;
        $participant->current_step_index = 0;
        $participant->status = 'assigned';
        $participant->metadata = $properties;
        $participant->save();

        return $this->getCurrentStepResponse($participant, $config);
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
        $groupConfig = $config['conditions'][$participant->condition_group];
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
        $steps = $config['conditions'][$group]['steps'];
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