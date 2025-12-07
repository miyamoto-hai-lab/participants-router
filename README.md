# participants-router
1つのURLから実験条件別URLにルーティングするPHPバックエンドです。全被験者に1つの共通URLを渡すだけでよいので、複数条件の二重参加を防止できます。

# Participants-Router API 仕様書 (v1.0)

## 1. 共通仕様

### ベースURL

`/router`

### レスポンスフォーマット

すべてのAPIレスポンスは以下のJSON形式で統一されます。

JSON

```
{
  "status": "ok",       // 成功時は "ok", 失敗時は "error"
  "url": "https://...", // 次に遷移すべきURL (エラー時や完了時は null)
  "message": null       // エラーメッセージや補足情報 (通常は null)
}
```

---

## 2. エンドポイント詳細

### 2-1. 実験参加・条件割り当て

初回アクセス時に呼び出します。参加可否の判定（Access Control）と、実験条件への割り当てを行います。

- **Endpoint**: `POST /router/assign`
- **Content-Type**: `application/json`

#### リクエストボディ

JSON

```
{
  "experiment_id": "math_test_2025", // config.jsoncで定義した実験ID
  "properties": {                    // 判定に使用するユーザー属性データ
    "worker_id": "cw_12345678",
    "source": "crowdworks",
    "age": 25,
    "browser": "chrome"
  }
}
```

#### レスポンスのパターン

**A. 参加許可 & 割り当て成功**

JSON

```
{
  "status": "ok",
  "url": "https://survey.example.com/consent?cond=A", // 条件の最初のステップURL
  "message": null
}
```

**B. 参加拒否 (Access Controlによる拒否)**
設定されたリダイレクトURL（除外ページなど）を返します。

JSON

```
{
  "status": "ok",
  "url": "https://lab.example.com/screening-out", // 拒否時の遷移先
  "message": "Access denied by policy."
}
```

**C. 実験無効化 / 定員到達**
`fallback_url` を返します。

JSON

```
{
  "status": "ok",
  "url": "https://lab.example.com/full",
  "message": "Experiment is disabled or full."
}
```

**D. エラー (ID不一致など)**

JSON

```
{
  "status": "error",
  "url": null,
  "message": "Experiment ID not found."
}
```

---

### 2-2. 次のステップへの遷移

実験進行中に呼び出します。現在のURLとユーザーデータをもとに、次の遷移先を決定します。

- **Endpoint**: `POST /router/next`
- **Content-Type**: `application/json`
- **Cookie**: セッションIDが必要（サーバー側で割り当てられた条件を識別するため）

#### リクエストボディ

JSON

```
{
  "experiment_id": "math_test_2025",
  "current_url": "https://task.example.com/math_test", // 現在ユーザーがいるURL
  "properties": {                                      // 分岐判定に使用するデータ
    "score": 3,
    "elapsed_time": 300,
    "is_finished": true
  }
}
```

#### レスポンスのパターン

**A. 次のステップへ遷移 (通常/分岐)**

JSON

```
{
  "status": "ok",
  "url": "https://task.example.com/review", // 次のURL (または分岐先のURL)
  "message": null
}
```

**B. ループ (同じステップに留まる)**

JSON

```
{
  "status": "ok",
  "url": "https://task.example.com/math_test", // current_urlと同じ
  "message": null
}
```

**C. 実験終了 (次のステップがない)**

JSON

```
{
  "status": "ok",
  "url": null,
  "message": "Experiment completed."
}
```

**D. エラー (セッション切れ、URLがフローに存在しない等)**

JSON

```
{
  "status": "error",
  "url": null,
  "message": "Session expired or invalid flow."
}
```

---

## 3. 設定ファイル記述例 (`config.jsonc`)

### ケース1: シンプルな例

**要件**:

- 一直線のフロー（同意書 → タスク → アンケート）。
- 条件分岐なし。
- アクセス制限は「CrowdWorksからのアクセスのみ許可」という単純な正規表現チェック。

コード スニペット

```
{
    "experiments": {
        "simple_survey": {
            "enable": true,
            "meta": { "owner": "Student A" },
            "config": {
                // CrowdWorks以外は拒否してGoogleへ飛ばす
                "access_control": {
                    "policy": "deny",
                    "default_redirect_url": "https://google.com",
                    "rules": [
                        { "field": "source", "pattern": "^crowdworks$", "action": "allow" }
                    ]
                },

                "assignment_strategy": "random", // ランダム割り当て
                "fallback_url": "https://lab.example.com/closed",
                
                "conditions": {
                    "group_A": {
                        "limit": 100,
                        "steps": [
                            // ID指定なしの簡易記法 (上から順に進むだけ)
                            "https://survey.example.com/consent",
                            "https://task.example.com/simple_task_v1",
                            "https://survey.example.com/questionnaire",
                            "https://survey.example.com/thankyou"
                        ]
                    }
                }
            }
        }
    }
}
```

---

### ケース2: 複雑な例 (フル機能)

**要件**:

- 外部APIを使って重複参加チェックを行う。
- タスクの成績によって「補習ページ」へ分岐させる。
- タスクをクリアするまでループさせる。
- 外部APIを使ってタスク完了判定を行う。

コード スニペット

```
{
    "experiments": {
        "complex_cognitive_task": {
            "enable": true,
            "meta": { "owner": "Researcher B" },
            "config": {
                // ------------------------------------------------------------
                // Access Control: 外部APIと正規表現の併用
                // ------------------------------------------------------------
                "access_control": {
                    "policy": "allow",
                    "deny_redirect": "https://lab.example.com/error",
                    "rules": [
                        // 1. テストユーザーを除外 (正規表現)
                        { 
                            "type": "regex",
                            "field": "worker_id", 
                            "pattern": "^test_.*", 
                            "action": "deny" 
                        },
                        // 2. 過去の参加者DB(外部API)に問い合わせて重複チェック
                        {
                            "type": "api",
                            "url": "https://api.lab-server.com/check_duplicate",
                            "action": "deny", // APIがtrue(重複あり)を返したら拒否
                            "redirect_url": "https://lab.example.com/already-done"
                        }
                    ]
                },

                "assignment_strategy": "minimum_count", // 均等割り当て
                "fallback_url": "https://lab.example.com/full",

                "conditions": {
                    "condition_X": {
                        "limit": 50,
                        "steps": [
                            // Step 1: 同意書 (簡易記法)
                            "https://survey.example.com/consent",

                            // Step 2: 練習タスク (分岐あり)
                            {
                                "id": "practice_task",
                                "url": "https://task.example.com/practice",
                                "transitions": [
                                    // スコアが低い(0-3点)なら、補習(tutorial)へ
                                    {
                                        "field": "score",
                                        "pattern": "^[0-3]$",
                                        "goto": "tutorial_phase"
                                    }
                                    // それ以外は次の main_task へ自動遷移
                                ]
                            },

                            // Step 2.5: 補習 (ここからは main_task へ合流)
                            {
                                "id": "tutorial_phase",
                                "url": "https://task.example.com/tutorial"
                            },

                            // Step 3: 本番タスク (ループと外部API判定)
                            {
                                "id": "main_task",
                                "url": "https://task.example.com/main",
                                "transitions": [
                                    // 外部APIで完了判定。APIが true を返したら終了ページ(end)へ
                                    {
                                        "type": "api",
                                        "url": "https://api.task-server.com/validate_completion",
                                        "goto": "end_phase"
                                    },
                                    // 完了していなければ自分自身(main_task)に戻ってループ
                                    {
                                        "type": "regex",
                                        "field": "status", // ダミー判定 (常にtrueになるようにしてelse的な役割)
                                        "pattern": ".*",
                                        "goto": "main_task"
                                    }
                                ]
                            },

                            // Step 4: 終了
                            {
                                "id": "end_phase",
                                "url": "https://survey.example.com/debriefing"
                            }
                        ]
                    }
                }
            }
        }
    }
}
```