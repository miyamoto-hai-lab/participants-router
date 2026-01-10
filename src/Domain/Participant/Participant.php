<?php

declare(strict_types=1);

namespace App\Domain\Participant;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $experiment_id
 * @property string $participant_id
 * @property string $condition_group
 * @property int $current_step_index
 * @property string $status
 * @property array $properties
 * @property \DateTime $created_at
 * @property \DateTime $updated_at
 * @property \DateTime|null $last_heartbeat
 * @method static \Illuminate\Database\Eloquent\Builder|Participant where($column, $operator = null, $value = null, $boolean = 'and')
 * @method static \Illuminate\Database\Eloquent\Builder|Participant first($columns = ['*'])
 */
class Participant extends Model
{
    // デフォルト値 (設定読み込み前のフォールバック)
    protected $table = 'participants';

    // Configから読み込んだテーブル名を保持する静的プロパティ
    protected static ?string $globalTableName = null;

    protected $fillable = [
        'experiment_id',
        'participant_id',
        'condition_group',
        'current_step_index',
        'status',
        'last_heartbeat',
        'properties',
    ];

    protected $casts = [
        'properties' => 'array',
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
