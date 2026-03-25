<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\RedisShard\Facades\ShardManager;

/**
 * Example controller demonstrating Laravel Redis Sharding usage.
 */
class UserController extends Controller
{
    /**
     * Display a listing of users from all shards.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        // Note: In a real application, you'd need to implement cross-shard queries
        // This is a simplified example showing how to work with sharded data
        
        $users = [];
        $shards = ShardManager::getAvailableShards();
        
        foreach ($shards as $shardName) {
            // Switch to each shard and get users
            $shardUsers = User::on($shardName)->limit(10)->get();
            $users = array_merge($users, $shardUsers->toArray());
        }
        
        return response()->json([
            'users' => $users,
            'total_shards' => count($shards),
            'message' => 'Users retrieved from all shards'
        ]);
    }

    /**
     * Store a new user (automatically sharded).
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
        ]);

        // Create user - the Shardable trait will handle shard assignment
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => bcrypt($request->password),
        ]);

        // Get the shard this user was assigned to
        $shardConnection = ShardManager::getShardConnection('users', $user->email);

        return response()->json([
            'user' => $user,
            'shard' => $shardConnection,
            'message' => 'User created and assigned to shard'
        ], 201);
    }

    /**
     * Display the specified user.
     *
     * @param string $email
     * @return JsonResponse
     */
    public function show(string $email): JsonResponse
    {
        // Determine which shard contains this user
        $shardConnection = ShardManager::getShardConnection('users', $email);
        
        // Query the specific shard
        $user = User::on($shardConnection)->where('email', $email)->first();
        
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        return response()->json([
            'user' => $user,
            'shard' => $shardConnection,
            'message' => 'User retrieved from shard'
        ]);
    }

    /**
     * Update the specified user.
     *
     * @param Request $request
     * @param string $email
     * @return JsonResponse
     */
    public function update(Request $request, string $email): JsonResponse
    {
        $request->validate([
            'name' => 'sometimes|string|max:255',
            'password' => 'sometimes|string|min:8',
        ]);

        // Find the user on the correct shard
        $shardConnection = ShardManager::getShardConnection('users', $email);
        $user = User::on($shardConnection)->where('email', $email)->first();
        
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        // Update user data
        if ($request->has('name')) {
            $user->name = $request->name;
        }
        
        if ($request->has('password')) {
            $user->password = bcrypt($request->password);
        }
        
        $user->save();

        return response()->json([
            'user' => $user,
            'shard' => $shardConnection,
            'message' => 'User updated on shard'
        ]);
    }

    /**
     * Remove the specified user.
     *
     * @param string $email
     * @return JsonResponse
     */
    public function destroy(string $email): JsonResponse
    {
        // Find and delete the user from the correct shard
        $shardConnection = ShardManager::getShardConnection('users', $email);
        $user = User::on($shardConnection)->where('email', $email)->first();
        
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $user->delete();

        return response()->json([
            'message' => 'User deleted from shard',
            'shard' => $shardConnection
        ]);
    }

    /**
     * Get shard distribution statistics.
     *
     * @return JsonResponse
     */
    public function shardStats(): JsonResponse
    {
        $shards = ShardManager::getAvailableShards();
        $stats = [];
        $totalUsers = 0;

        foreach ($shards as $shardName) {
            $userCount = User::on($shardName)->count();
            $stats[$shardName] = $userCount;
            $totalUsers += $userCount;
        }

        return response()->json([
            'total_users' => $totalUsers,
            'shard_distribution' => $stats,
            'total_shards' => count($shards),
            'average_per_shard' => $totalUsers > 0 ? round($totalUsers / count($shards), 2) : 0,
        ]);
    }

    /**
     * Demonstrate cross-shard search (simplified example).
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'query' => 'required|string|min:2',
        ]);

        $query = $request->query;
        $results = [];
        $shards = ShardManager::getAvailableShards();

        // Search across all shards
        foreach ($shards as $shardName) {
            $shardResults = User::on($shardName)
                ->where('name', 'LIKE', "%{$query}%")
                ->orWhere('email', 'LIKE', "%{$query}%")
                ->limit(10)
                ->get()
                ->map(function ($user) use ($shardName) {
                    $user->shard = $shardName;
                    return $user;
                });

            $results = array_merge($results, $shardResults->toArray());
        }

        return response()->json([
            'query' => $query,
            'results' => $results,
            'total_results' => count($results),
            'searched_shards' => count($shards),
        ]);
    }

    /**
     * Demonstrate manual shard selection.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function createOnSpecificShard(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255',
            'password' => 'required|string|min:8',
            'shard' => 'required|string',
        ]);

        $shardName = $request->shard;
        $availableShards = ShardManager::getAvailableShards();

        if (!in_array($shardName, $availableShards)) {
            return response()->json([
                'message' => 'Invalid shard name',
                'available_shards' => $availableShards
            ], 400);
        }

        // Create user on specific shard
        $user = new User([
            'name' => $request->name,
            'email' => $request->email,
            'password' => bcrypt($request->password),
        ]);

        // Set the connection before saving
        $user->setConnection($shardName);
        $user->save();

        return response()->json([
            'user' => $user,
            'shard' => $shardName,
            'message' => 'User created on specified shard'
        ], 201);
    }

    /**
     * Get performance metrics for user operations.
     *
     * @return JsonResponse
     */
    public function performanceMetrics(): JsonResponse
    {
        $shards = ShardManager::getAvailableShards();
        $metrics = [];

        foreach ($shards as $shardName) {
            $startTime = microtime(true);
            
            // Test query performance
            User::on($shardName)->limit(1)->get();
            
            $queryTime = (microtime(true) - $startTime) * 1000;
            
            $metrics[$shardName] = [
                'query_time_ms' => round($queryTime, 2),
                'user_count' => User::on($shardName)->count(),
                'status' => $queryTime < 100 ? 'fast' : 'slow',
            ];
        }

        return response()->json([
            'shard_metrics' => $metrics,
            'strategy' => ShardManager::strategy()->getName(),
            'timestamp' => now()->toISOString(),
        ]);
    }
}
