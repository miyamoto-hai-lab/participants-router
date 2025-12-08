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
        $config = $experiments[$experimentId]['config'];

        // 2. 既存参加者の確認 (レジューム機能)
        $participant = Participant::where('experiment_id', $experimentId)
            ->where('browser_id', $browserId)
            ->first();

        if ($participant) {
            $this->logger->info("Resume participant: $browserId");
            return $this->getCurrentStepResponse($participant, $config);
        }

        // 3. Access Control (簡易実装: 正規表現チェック)
        if (isset($config['access_control']['rules'])) {
            foreach ($config['access_control']['rules'] as $rule) {
                if ($rule['type'] === 'regex') {
                    $val = $properties[$rule['field']] ?? '';
                    if (preg_match('/' . $rule['pattern'] . '/', (string)$val)) {
                        if ($rule['action'] === 'deny') {
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
        
        // 各群の現在人数(完了 + 3分以内アクティブ)を集計
        $activeLimit = (new \DateTime())->modify('-3 minutes');
        
        $counts = [];
        foreach (array_keys($conditions) as $group) {
            $count = Participant::where('experiment_id', $experimentId)
                ->where('condition_group', $group)
                ->where(function ($query) use ($activeLimit) {
                    $query->where('status', 'completed')
                          ->orWhere('last_heartbeat', '>=', $activeLimit);
                })
                ->count();
            $counts[$group] = $count;
        }

        // 割り当て可能なグループを探す
        $candidates = [];
        foreach ($conditions as $group => $condConfig) {
            if ($counts[$group] < $condConfig['limit']) {
                $candidates[] = $group;
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
        if ($assignmentStrategy === 'minimum_count') {
            // 候補の中で一番人数が少ないものを選ぶ
            usort($candidates, fn($a, $b) => $counts[$a] <=> $counts[$b]);
            $targetGroup = $candidates[0];
        } else {
            // ランダム
            $targetGroup = $candidates[array_rand($candidates)];
        }

        // 5. 保存
        $participant = new Participant();
        // log_error($this->tableName);
        // $participant->setTable($this->tableName);
        $participant->experiment_id = $experimentId;
        $participant->browser_id = $browserId;
        $participant->worker_id = $properties['worker_id'] ?? null;
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
}