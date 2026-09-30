<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $name
 * @property string $connection
 * @property string $status
 * @property int $record_count
 * @property \Carbon\CarbonInterface|null $created_at
 * @property \Carbon\CarbonInterface|null $updated_at
 * @property \Carbon\CarbonInterface|null $last_rebalanced_at
 * @method static static create(array<string, mixed> $attributes = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static> query()
 * @method static \Illuminate\Database\Eloquent\Builder<static> where(string $column, mixed $operator = null, mixed $value = null, string $boolean = 'and')
 */
class ShardMetadata extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'shard_metadata';

    public function getTable(): string
    {
        $configuredTable = app(\Laravel\RedisShard\Support\RedisShardConfig::class)->metadataTable();
        if ($configuredTable !== null) {
            return $configuredTable;
        }

        return parent::getTable();
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'connection',
        'status',
        'created_at',
        'updated_at',
        'record_count',
        'last_rebalanced_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'last_rebalanced_at' => 'datetime',
        'record_count' => 'integer',
    ];

    /**
     * Get active shards.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getActiveShards()
    {
        return static::where('status', 'active')->get();
    }

    /**
     * Mark a shard as inactive.
     *
     * @return bool
     */
    public function markInactive(): bool
    {
        $this->status = 'inactive';

        return $this->save();
    }

    /**
     * Mark a shard as active.
     *
     * @return bool
     */
    public function markActive(): bool
    {
        $this->status = 'active';

        return $this->save();
    }

    /**
     * Update the record count for this shard.
     *
     * @param int $count
     * @return bool
     */
    public function updateRecordCount(int $count): bool
    {
        $this->record_count = $count;

        return $this->save();
    }

    /**
     * Mark this shard as rebalanced.
     *
     * @return bool
     */
    public function markRebalanced(): bool
    {
        $this->last_rebalanced_at = now();

        return $this->save();
    }
}
