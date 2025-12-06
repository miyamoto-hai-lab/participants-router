# participants-router
1つのURLから実験条件別URLにルーティングするPHPバックエンドです。全被験者に1つの共通URLを渡すだけでよいので、複数条件の二重参加を防止できます。

## 使い方
[`config.jsonc`](config.jsonc)を編集することで簡単にルーティング設定を変更できます．
```jsonc
{
    // データベース接続設定
    // SQLiteなら: "sqlite://../database.sqlite"
    // MySQLなら: "mysql://user:pass@localhost:3306/db_name"
    "database_url": "sqlite://../database.sqlite",

    // 条件設定
    "conditions": {
        "condition_A": {
            "url": "https://www.google.com/search?q=Condition+A",
            "limit": 50
        },
        "condition_B": {
            // ここにもコメントが書けます
            "url": "https://www.google.com/search?q=Condition+B",
            "limit": 50
        }
    }
}
```