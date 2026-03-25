<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\RedisShard\Traits\Shardable;

/**
 * Example User model demonstrating Laravel Redis Sharding usage.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class User extends Authenticatable
{
    use HasFactory, Notifiable, Shardable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    /**
     * The shard key for this model.
     * This determines which field is used for shard assignment.
     * If not specified, the primary key is used.
     *
     * @var string|null
     */
    protected ?string $shardKey = 'email';

    /**
     * Get the user's orders.
     * 
     * Note: Cross-shard relationships require special handling.
     * This is a simplified example.
     */
    public function orders()
    {
        // In a real application, you'd need to implement cross-shard relationships
        // or ensure related data is on the same shard
        return $this->hasMany(Order::class);
    }

    /**
     * Get the user's profile.
     * 
     * Example of keeping related data on the same shard.
     */
    public function profile()
    {
        return $this->hasOne(UserProfile::class);
    }

    /**
     * Scope to find users by email across shards.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $email
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByEmail($query, string $email)
    {
        return $query->where('email', $email);
    }

    /**
     * Scope to find active users.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->whereNotNull('email_verified_at');
    }

    /**
     * Get the shard this user belongs to.
     *
     * @return string
     */
    public function getShardAttribute(): string
    {
        return $this->getShardConnection();
    }

    /**
     * Custom method to demonstrate shard-aware operations.
     *
     * @return array
     */
    public function getShardInfo(): array
    {
        return [
            'shard_connection' => $this->getShardConnection(),
            'shard_key' => $this->getShardKey(),
            'shard_key_value' => $this->getShardKeyValue(),
        ];
    }

    /**
     * Example of a method that works with the current shard.
     *
     * @return int
     */
    public function getShardUserCount(): int
    {
        $shardConnection = $this->getShardConnection();
        return static::on($shardConnection)->count();
    }

    /**
     * Example method to migrate this user to a different shard.
     * 
     * Note: This is a complex operation that should be done carefully.
     *
     * @param string $targetShard
     * @return bool
     */
    public function migrateToShard(string $targetShard): bool
    {
        $currentShard = $this->getShardConnection();
        
        if ($currentShard === $targetShard) {
            return true; // Already on target shard
        }

        try {
            // Create a copy on the target shard
            $newUser = $this->replicate();
            $newUser->setConnection($targetShard);
            $newUser->save();

            // Update shard locator
            app('shard.locator')->register(
                $this->getTable(),
                $this->getShardKeyValue(),
                $targetShard
            );

            // Delete from current shard
            $this->delete();

            return true;
        } catch (\Exception $e) {
            // Log error and return false
            logger()->error('Failed to migrate user to shard: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Boot method to set up model events.
     */
    protected static function boot()
    {
        parent::boot();

        // Example: Log when users are created on shards
        static::created(function ($user) {
            logger()->info('User created on shard', [
                'user_id' => $user->id,
                'email' => $user->email,
                'shard' => $user->getShardConnection(),
            ]);
        });

        // Example: Clean up shard locator when user is deleted
        static::deleted(function ($user) {
            app('shard.locator')->forget(
                $user->getTable(),
                $user->getShardKeyValue()
            );
            
            logger()->info('User deleted from shard', [
                'user_id' => $user->id,
                'email' => $user->email,
                'shard' => $user->getShardConnection(),
            ]);
        });
    }
}
