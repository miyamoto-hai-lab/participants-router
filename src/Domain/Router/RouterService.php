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

    public function __construct(SettingsInterface $settings, LoggerInterface $logger)
    {
        $this->settings = $settings;
        $this->logger = $logger;
    }

    /**
     * 参加割り当て (Assign)
     */
    public function assign(string $experimentId, string $participantId, array $properties): array
    {
        // 1. 設定の取得
        $experiments = $this->settings->get('experiments');
        if (!isset($experiments[$experimentId])) {
            return [
                'data' => ['status' => 'error', 'message' => 'Experiment ID not found'],
                'statusCode' => 404
            ];
        }
        if (!$experiments[$experimentId]["enable"]) {
            return [
                'data' => ['status' => 'error', 'message' => 'Experiment is disabled'],
                'statusCode' => 403
            ];
        }
        $config = $experiments[$experimentId]['config'];

        // 2. 既存参加者の確認 (レジューム機能)
        $participant = Participant::where('experiment_id', $experimentId)
            ->where('participant_id', $participantId)
            ->first();

        if ($participant) {
            $this->logger->info("Resume participant: $participantId");
            // ハートビート更新
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
                    'data' => [
                        'status' => 'ok',
                        'url' => $config['access_control']['deny_redirect'] ?? null,
                        'message' => 'Access denied'
                    ],
                    'statusCode' => 200
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
                'data' => [
                    'status' => 'ok',
                    'url' => $config['fallback_url'],
                    'message' => 'Full'
                ],
                'statusCode' => 200
            ];
        }

        // 割り当て戦略
        $targetGroup = null;
        if ($assignmentStrategy === 'minimum') {
            usort($candidates, function ($a, $b) {
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
        $participant->participant_id = $participantId;
        $participant->condition_group = $targetGroup;
        $participant->current_step_index = 0;
        $participant->status = 'assigned';
        $participant->properties = $properties;
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
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            // サーバーエラー系とTooManyRequestsはもう一度だけアクセスしてみる
            if ($httpCode >= 500 || $httpCode == 429) {
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($httpCode >= 500 || $httpCode == 429) {
                    return false; // それでもダメなら拒否
                }
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
    public function next(string $experimentId, string $participantId, string $currentUrl, array $properties): array
    {
        $experiments = $this->settings->get('experiments');
        $config = $experiments[$experimentId]['config'] ?? null;
        if (!$config) {
            return [
            'data' => ['status' => 'error', 'message' => 'Config not found'],
            'statusCode' => 500
            ];
        }

        $participant = Participant::where('experiment_id', $experimentId)
            ->where('participant_id', $participantId)
            ->first();

        if (!$participant) {
            return [
                'data' => ['status' => 'error', 'message' => 'Participant not found'],
                'statusCode' => 404
            ];
        } else {
            // ハートビート更新
            $participant->last_heartbeat = new \DateTime();
            $participant->save();
        }

        if ($participant->status === 'completed') {
            return $this->getCurrentStepResponse($participant, $config);
        }

        // 現在のステップ情報を取得
        $groupConfig = $config['groups'][$participant->condition_group];
        $steps = $groupConfig['steps'];

        // 次へ進める
        // 現在のURL(current_url)を評価して、次のURLを決定する
        // [現在のページの評価方法について]
        // stepsに定義されているURLとの一致で評価する。
        // クエリパラメータについては、各stepで定義されているものだけを使用し、それ以外の余計なパラメータがついていても無視する。
        // もし、一致するURLが複数ある場合は、より一致率が高いURLに評価する。
        // 例） current_url: /page?param1=value1&param2=value2&param3=value3
        //      steps: [
        //          "/page?param1=value1",
        //          "/page?param2=value1&param2=value2"
        //      ]
        //      という設定の場合、
        //      current_urlはstepsの2番目のURLに一致する。（1番目のURLとも一致するが2番目のURLのほうがより多くのパラメータと一致しているため）
        // [次のステップの決定方法について]
        // 一致したURLの次のURLを渡す。
        // transitionを使った複雑な定義には現時点では対応しない。

        // 1. 現在のURL情報を解析
        $currentBaseUrl = strtok($currentUrl, '?#'); // クエリパラメータとフラグメントを除く
        $currentBaseUrl = rtrim($currentBaseUrl, '/'); // 末尾のスラッシュを削除
        $currentQueryStr = parse_url($currentUrl, PHP_URL_QUERY) ?? '';
        parse_str($currentQueryStr, $currentUrlParameters);

        $currentIndex = $participant->current_step_index;
        $foundIndex = -1;

        // 2. 探索順序の決定 (現在地、次、その他全体の順)
        $allIndexes = array_keys($steps);
        $searchOrder = array_unique(array_merge(
            [$currentIndex, $currentIndex + 1],
            $allIndexes
        ));

        foreach ($searchOrder as $index) {
            if (!isset($steps[$index])) {
                continue;
            }

            $step = $steps[$index];
            $stepUrl = is_array($step) ? $step['url'] : $step;

            // プレースホルダー置換
            $resolvedStepUrl = $this->resolvePlaceholders(
                $stepUrl,
                array_merge($participant->properties ?? [], $properties)
            );

            $stepBaseUrl = strtok($resolvedStepUrl, '?#'); // クエリパラメータとフラグメントを除く
            $stepBaseUrl = rtrim($stepBaseUrl, '/'); // 末尾のスラッシュを削除
            $stepQueryStr = parse_url($resolvedStepUrl, PHP_URL_QUERY) ?? '';
            parse_str($stepQueryStr, $stepUrlParameters);

            // ベースURL一致確認
            if ($stepBaseUrl === $currentBaseUrl) {
                // ステップ定義にある全パラメータが現在のURLに含まれているか確認
                $matchedParams = array_intersect_assoc($stepUrlParameters, $currentUrlParameters);

                if (count($matchedParams) === count($stepUrlParameters)) {
                    $foundIndex = $index;
                    break; // 最適なインデックスが見つかったのでループ終了
                }
            }
        }

        // 3. 次のステップへの遷移処理
        if ($foundIndex !== -1) {
            // ステップを1つ進める
            $nextIndex = $foundIndex + 1;
            $participant->current_step_index = $nextIndex;
            $participant->save();
            return $this->getCurrentStepResponse($participant, $config, $properties);
        } else {
            // 4. [エラー] どのステップにもマッチしなかった場合
            return [
                'data' => [
                    'status' => 'error',
                    'url' => null,
                    'message' => 'No matching step found for the current URL.'
                ],
                'statusCode' => 404
            ];
        }
    }

    /**
     * ハートビート更新
     */
    public function heartbeat(string $experimentId, string $participantId): void
    {
        $participant = Participant::where('experiment_id', $experimentId)
            ->where('participant_id', $participantId)
            ->first();

        if ($participant) {
            $participant->last_heartbeat = new \DateTime();
            $participant->save();
        }
    }

    /**
     * 現在のステップのURLを返すヘルパー
     */
    private function getCurrentStepResponse(Participant $participant, array $config, array $properties = []): array
    {
        $group = $participant->condition_group;
        $steps = $config['groups'][$group]['steps'];
        $index = $participant->current_step_index;
        if (isset($steps[$index]) && $participant->status !== 'completed') {
                // 次のページがある場合
                $step = $steps[$index];
                // 文字列ならそのままURL、オブジェクトならurlプロパティ
                $url = is_string($step) ? $step : ($step['url'] ?? null);

                // プレースホルダー置換
                $url = $this->resolvePlaceholders($url, array_merge($participant->properties ?? [], $properties));

                return [
                    'data' => [
                        'status' => 'ok',
                        'url' => $url,
                        'message' => null
                    ],
                    'statusCode' => 200
                ];
        } else {
            // [完了] 次のページがない場合
            $participant->status = 'completed';
            $participant->save();
            return [
                'data' => [
                    'status' => 'ok',
                    'url' => null,
                    'message' => 'Experiment completed'
                ],
                'statusCode' => 200
            ];
        }
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
    }
}
