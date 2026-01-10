# participants-router

**日本語** | [English](README_en.md)
&emsp;&emsp;
[![Tests](https://github.com/miyamoto-hai-lab/participants-router/actions/workflows/tests.yml/badge.svg)](https://github.com/miyamoto-hai-lab/participants-router/actions/workflows/tests.yml)

**participants-router** は、心理学実験やオンライン調査のために設計された、PHP製のバックエンドルーティングシステムです。
参加者ごとに一意の実験条件を割り当て、複数の実験ステップ（同意書、タスク、アンケートなど）への遷移を管理します。

## 特徴

- **単一URL配布**: 全ての参加者に同じURL（エントリポイント）を配布するだけで、自動的に条件別のURLへ誘導します。
- **重複参加防止**: ブラウザID等をキーにして参加状況を管理し、重複参加を防止・制御します。
- **柔軟な割り当て戦略**: 参加者数の最小化（Minimal Group Assignment）やランダム割り当てに対応。
- **アクセス制御**: 正規表現や外部API（CrowdWorksなど）と連携した高度な参加条件（スクリーニング）設定が可能。
- **ハートビート監視**: 参加者の離脱を検知するためのハートビートAPIを提供。
- **ステートフルな進行管理**: 参加者が現在どのステップにいるかをDBで管理し、リロードや再アクセス時も正しい位置から再開（Resume）できます。

## 動作要件

- **Webサーバー**: Apache, Nginx など
- **PHP**: 8.3 以上推奨
- **データベース**: MySQL, PostgreSQL, SQLite (PDO対応のDB)
- **Composer**: PHPパッケージ管理ツール (https://getcomposer.org/)

## 導入手順

1. **リポジトリのクローン**
   ```shell
   git clone https://github.com/miyamoto-hai-lab/participants-router.git
   cd participants-router
   ```

2. **依存ライブラリのインストール**
   [Composer](https://getcomposer.org/)を使って依存パッケージをインストールします。
   ```shell
   composer install
   ```

3. **データベース設定**
   `config.jsonc` でを設定するだけで完了です。
   アプリケーション起動時に、必要なテーブル（デフォルト: `participants_routes`）が自動的に作成されます。
   
   SQLiteを使用する場合、指定したパスにデータベースファイルが存在しなければ自動的に作成されます。

3. **設定ファイルの編集**
   `config.jsonc` を環境に合わせて編集します。  
   データベース接続情報や実験設定などを記述します。

   [Visual Studio Code](https://code.visualstudio.com/)などで編集すると、`config.shema.json`を基に設定項目の説明が表示されます。

4. **Webサーバーへの配置**
   ステップ2で生成された`vendor`ディレクトリも含むすべてのファイルをWebサーバーの公開ディレクトリ（ドキュメントルート）または、そこからアクセス可能な場所に配置します。

   APIへの初回アクセス時に、必要なテーブル（デフォルト: `participants_routes`）が自動的に作成されます。  
   SQLiteを使用する場合、指定したパスにデータベースファイルが存在しなければ自動的に作成されます。
   


## 設定方法 (`config.jsonc`)

設定ファイルは JSONC (JSON with Comments) 形式で記述します。主な設定項目は以下の通りです。

### 基本設定

```jsonc
{
    "$schema": "./config.schema.json",
    // APIのベースパス (例: "/api/router")
    "base_path": "/api/router",

    // データベース接続設定
    "database": {
        "url": "mysql://user:pass@localhost/dbname", // または sqlite://./db.sqlite
        "table": "participants_routes"
    },

    "experiments": {
        // 実験ID (APIリクエスト時に使用)
        "my_experiment_v1": {
            "enable": true, // falseにするとアクセスを停止
            "config": { ... } // 実験ごとの詳細設定
        }
    }
}
```

### 実験設定 (`config`)の詳細

| キー | 説明 |
| :--- | :--- |
| `access_control` | 参加条件（スクリーニング）ルール。正規表現や外部API連携が可能。 |
| `assignment_strategy` | 割り当て戦略。`minimum` (人数の少ない条件へ) または `random`。 |
| `fallback_url` | 満員時や実験無効時にリダイレクトさせるURL。 |
| `heartbeat_intervalsec` | 有効な参加者としてカウントする時間枠（秒）。この時間以内にハートビートがない参加者は「離脱」とみなされ、人数カウントから除外される場合があります。 |
| `groups` | 実験条件（群）の定義。 |

#### Access Controlの設定

`access_control` は、実験に参加できるユーザーを制限するための機能です。`all_of` (AND), `any_of` (OR), `not` (NOT) を組み合わせた**論理条件ツリー**として定義します。

**条件演算子:**

| キー | 説明 |
| :--- | :--- |
| `all_of` | リスト内の**全ての**条件が `true` の場合に `true` を返します (AND)。 |
| `any_of` | リスト内の**いずれかの**条件が `true` の場合に `true` を返します (OR)。 |
| `not` | 指定した条件の真偽を**反転**させます (NOT)。 |

**ルール (末端条件):**

論理演算子の末端には、以下のいずれかのルールを記述します。

**1. 正規表現判定 (`type: regex`)**
クライアントから送信された `properties` の値を正規表現でチェックします。

```jsonc
{
    "type": "regex",
    "field": "age",      // チェック対象のプロパティ名
    "pattern": "^2[0-9]$" // 正規表現パターン (例: 20代)
}
```

**2. 外部API問い合わせ (`type: fetch`)**
外部サーバーにHTTPリクエストを送り、その結果に基づいて判定します。
URLやBody内で `${keyname}` 形式のプレースホルダを使用でき、`properties` の値や `browser_id` に置換されます。

```jsonc
{
    "type": "fetch",
    "url": "https://api.example.com/check?id=${crowdworks_id}",
    "method": "GET", // GET (default) or POST
    // "headers": { "Authorization": "Bearer ..." },
    // "body": { "id": "${crowdworks_id}" }, // POSTの場合
    "expected_status": 200 // 成功とみなすHTTPステータス (省略時は200系OK判定)
}
```

**設定例: 複合条件**

「CrowdWorks IDが7桁の数字」**かつ**「外部APIで重複チェックがOK（200が返ってきたらNGなので `not` で反転）」の場合のみ許可する例：

```jsonc
"access_control": {
    "condition": {
        "all_of": [
            {
                "type": "regex",
                "field": "crowdworks_id",
                "pattern": "^\\d{7}$"
            },
            {
                "not": { 
                    "type": "fetch",
                    "url": "https://api.example.com/check_duplicate/${crowdworks_id}",
                    "expected_status": 200 // 重複あり(200)なら true -> not で false(拒否) になる
                }
            }
        ]
    },
    "action": "allow", // 条件が true の時の動作 (現在は allow のみ)
    "deny_redirect": "https://example.com/screened_out.html" // 拒否された場合の遷移先
}
```

#### Groups (条件・ステップ) の設定

実験の進行（ステップ）をURLのリストとして定義します。ユーザーが現在のURLから「次へ」リクエストを送ると、リストの次のURLが返されます。

```jsonc
"groups": {
    "group_A": {
        "limit": 50, // 参加人数上限
        "steps": [
            // STEP 1
            "https://survey.example.com/consent", 
            // STEP 2
            "https://task.example.com/task_A",
            // STEP 3
            "https://survey.example.com/post_survey"
        ]
    },
    "group_B": {
        "limit": 50,
        "steps": [
            "https://survey.example.com/consent",
            "https://task.example.com/task_B", // group_Aと異なるタスク
            "https://survey.example.com/post_survey"
        ]
    }
}
```

## API仕様

クライアント（実験実施用のフロントエンドアプリなど）からは、主に以下の3つのAPIを利用します。すべてのレスポンスはJSON形式です。

### 1. 参加割り当て (Assign)

実験への参加登録を行い、最初のステップのURLを取得します。

- **Endpoint**: `POST /router/assign`
- **Content-Type**: `application/json`

**Request Body:**
```jsonc
{
  "experiment_id": "my_experiment_v1",
  "browser_id": "unique_client_id_abc123", // 実験クライアントを一意に識別するID
  "properties": {
    "crowdworks_id": "1234567", // access_control等の判定に使われる属性
    "age": 25
  }
}
```

> [!TIP]
> **browser_id について**
> `browser_id` は実験クライアント間で一意であり、かつ再アクセス時に復元可能である必要があります。  
> 例えばクラウドワーカー固有のID等の設定も可能ですが、異なるブラウザからの再アクセス時に対応できないため、推奨しません。
> またセッションIDのように実験ページへのアクセス毎に変更されるIDも再アクセスを検知できないため推奨しません。
>
> クライアント側のID生成・管理には、宮本研究室で開発された **[participants-id](https://github.com/miyamoto-hai-lab/participants-id)** ライブラリの使用を推奨します。これを利用することで、ローカルストレージへの適切な永続化とブラウザ固有のID生成が容易に行えます。

**Response (Success):**
```jsonc
{
  "status": "ok",
  "url": "https://survey.example.com/consent", // 遷移すべきURL
  "message": null
}
```

**Response (Denied/Full/Error):**
```jsonc
{
  "status": "ok", // または "error"
  "url": "https://example.com/screened_out.html", // リダイレクト先（設定されている場合）
  "message": "Access denied" // または "Full" 等
}
```

### 2. 次のステップへ (Next)

現在のステップを完了し、次のステップのURLを取得します。システムは現在のURL (`current_url`) を元に進行状況を判定します。

- **Endpoint**: `POST /router/next`
- **Content-Type**: `application/json`

**Request Body:**
```jsonc
{
  "experiment_id": "my_experiment_v1",
  "browser_id": "unique_browser_hash_123",
  "current_url": "https://survey.example.com/consent?user=123", // 現在表示しているURL
  "properties": {
      "score": 100 // 必要に応じてプロパティを更新可能
  }
}
```

**Response (Next Step):**
```jsonc
{
  "status": "ok",
  "url": "https://task.example.com/task_A", // 次のURL
  "message": null
}
```

**Response (Completed):**
```jsonc
{
  "status": "ok",
  "url": null, // 次がない場合はnull
  "message": "Experiment completed"
}
```

### 3. ハートビート (Heartbeat)

参加者が実験を継続中（ブラウザを開いている）であることを通知します。`heartbeat_intervalsec` の設定と連動し、アクティブな参加者数を正確に把握するために使用します。

- **Endpoint**: `POST /router/heartbeat`
- **Content-Type**: `application/json`

**Request Body:**
```jsonc
{
  "experiment_id": "my_experiment_v1",
  "browser_id": "unique_browser_hash_123"
}
```

**Response:**
```jsonc
{
  "status": "ok"
}
```

## クライアント実装例 (jsPsych)

[participants-id](https://github.com/miyamoto-hai-lab/participants-id) ライブラリと [jsPsych](https://www.jspsych.org/) を組み合わせた実装例です。

### 1. 最初の参加割り当て (Assign)

最初の画面で `browser_id` を取得（生成）し、`Assign` APIを叩いて実験URLへ遷移します。

```javascript
// htmlヘッダー等で participants-id ライブラリを読み込んでおく
// <script src="https://cdn.jsdelivr.net/gh/miyamoto-hai-lab/participants-id@v1.0.0/dist/participants-id.min.js"></script>

const APP_NAME = "my_experiment_v1";

// jsPsychのtrialとして定義する例
const loading_process_trial = {
    type: jsPsychHtmlKeyboardResponse,
    stimulus: `<div class="loader"></div><p>実験ページへ遷移中です...</p>`,
    choices: "NO_KEYS",
    on_load: async () => {
        try {
            // 1. participants-idの初期化
            const participant = new ParticipantsIdLib.AsyncParticipant(
                APP_NAME,
                undefined, 
                // IDのバリデーション関数
                (id) => typeof id === "string" && id.length > 0
            );

            // 2. browser_id の取得 (初回は生成、2回目以降はLocalStorageから取得)
            const browserId = await participant.get_browser_id();

            // 3. 属性情報の保存 (必要に応じて)
            // 例: 直前のトライアルで入力させたID等を取得
            // const cwid = jsPsych.data.get().last(1).values()[0].response.cwid;
            // await participant.set_attribute("crowdworks_id", cwid);

            // 4. Serverへ参加リクエスト (Assign)
            const response = await fetch('/api/router/assign', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    experiment_id: APP_NAME,
                    browser_id: browserId,
                    properties: {
                        // access_control等に必要な情報を送信
                        // crowdworks_id: cwid 
                    }
                })
            });

            if (!response.ok) throw new Error('Network response was not ok');
            const result = await response.json();

            // 5. 遷移先URLへリダイレクト
            if (result.data.url) {
                window.location.href = result.data.url;
            } else {
                alert("参加できませんでした: " + (result.data.message || "Unknown error"));
            }

        } catch (e) {
            console.error(e);
            alert("エラーが発生しました");
        }
    }
};
```

### 2. ハートビートとページ遷移 (Heartbeat & Next)

実験中の各ページでは、ハートビートを定期送信しつつ、タスク終了時に `Next` APIを叩いて次のステップへ進みます。

```javascript
// ページ読み込み時に Heartbeat を開始
const participant = new ParticipantsIdLib.AsyncParticipant(APP_NAME, /* ... */);

document.addEventListener("DOMContentLoaded", async () => {
    const browserId = await participant.get_browser_id();

    // 10秒ごとにハートビート送信
    if (browserId) {
        setInterval(() => {
            fetch("/api/router/heartbeat", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({
                    experiment_id: APP_NAME,
                    browser_id: browserId
                })
            }).catch(e => console.error("Heartbeat error:", e));
        }, 10000);
    }
});

// 次へ進む処理 (jsPsychのtrialなど)
const next_step_trial = {
    type: jsPsychHtmlKeyboardResponse,
    stimulus: "処理中...",
    on_load: async () => {
        const browserId = await participant.get_browser_id();
        const currentUrl = window.location.href;

        // Next API を叩く
        const response = await fetch('/api/router/next', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                experiment_id: APP_NAME,
                browser_id: browserId,
                current_url: currentUrl,
                properties: {
                    // スコアなどで分岐する場合
                    // score: 100
                }
            })
        });

        const result = await response.json();
        if (result.data.url) {
            window.location.href = result.data.url;
        } else {
            alert("実験終了です。お疲れ様でした。");
        }
    }
};
```

## ディレクトリ構成・開発者向け情報

詳細なディレクトリ構成やデータベース設計図については、[CONTRIBUTING.md](CONTRIBUTING.md) を参照してください。

- `src/Domain`: ドメインロジック（RouterService, Participantモデルなど）
- `src/Application`: アプリケーション層（Action, Controller）
- `config.jsonc`: 設定ファイル
- `public`: 公開ディレクトリ（index.php等）

## ライセンス
このプロジェクトは[MIT License](LICENSE)で提供されています。
