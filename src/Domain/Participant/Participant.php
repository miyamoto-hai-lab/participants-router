<?php

declare(strict_types=1);

namespace App\Domain\Participant;

use Illuminate\Database\Eloquent\Model;

class Participant extends Model
{
    // デフォルト値 (設定読み込み前のフォールバック)
    protected $table = 'participants';

    // Configから読み込んだテーブル名を保持する静的プロパティ
    protected static ?string $globalTableName = null;

    protected $fillable = [
        'experiment_id',
        'browser_id',
        'worker_id',
        'condition_group',
        'current_step_index',
        'status',
        'last_heartbeat',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'last_heartbeat' => 'datetime',
    ];

    /**
     * コンストラクタ：インスタンス化されるたびにテーブル名を適用
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        if (self::$globalTableName) {
            $this->setTable(self::$globalTableName);
        }
    }

    /**
     * アプリ起動時にConfigの値をセットするためのメソッド
     */
    public static function setGlobalTableName(string $tableName): void
    {
        self::$globalTableName = $tableName;
    }
}