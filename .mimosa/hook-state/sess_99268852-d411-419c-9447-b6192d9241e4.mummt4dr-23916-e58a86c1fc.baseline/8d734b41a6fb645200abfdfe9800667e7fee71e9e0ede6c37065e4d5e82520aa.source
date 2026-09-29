<?php

use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Example Routes for Laravel Redis Sharding
|--------------------------------------------------------------------------
|
| These routes demonstrate how to use the Laravel Redis Sharding package
| in a real application. They show various patterns for working with
| sharded data.
|
*/

// Basic CRUD operations with automatic sharding
Route::prefix('api/users')->group(function () {
    // List users from all shards
    Route::get('/', [UserController::class, 'index']);
    
    // Create a new user (automatically sharded)
    Route::post('/', [UserController::class, 'store']);
    
    // Get a specific user by email
    Route::get('/{email}', [UserController::class, 'show']);
    
    // Update a user
    Route::put('/{email}', [UserController::class, 'update']);
    
    // Delete a user
    Route::delete('/{email}', [UserController::class, 'destroy']);
});

// Advanced sharding operations
Route::prefix('api/sharding')->group(function () {
    // Get shard distribution statistics
    Route::get('/stats', [UserController::class, 'shardStats']);
    
    // Search across all shards
    Route::get('/search', [UserController::class, 'search']);
    
    // Create user on specific shard
    Route::post('/users/create-on-shard', [UserController::class, 'createOnSpecificShard']);
    
    // Get performance metrics
    Route::get('/performance', [UserController::class, 'performanceMetrics']);
});

// Routes with shard-aware middleware
Route::middleware(['shard:users,email'])->group(function () {
    // These routes will automatically determine the correct shard
    // based on the email parameter and set the database connection
    
    Route::get('/sharded-users/{email}/profile', function ($email) {
        // This will run on the correct shard automatically
        $user = App\Models\User::where('email', $email)->first();
        return response()->json($user);
    });
    
    Route::get('/sharded-users/{email}/orders', function ($email) {
        // Example of accessing related data on the same shard
        $user = App\Models\User::where('email', $email)->with('orders')->first();
        return response()->json($user);
    });
});

// Shard management routes (typically admin-only)
Route::prefix('admin/shards')->middleware(['auth', 'admin'])->group(function () {
    // Get shard status
    Route::get('/status', function () {
        return response()->json([
            'shards' => \Laravel\RedisShard\Facades\ShardManager::getAvailableShards(),
            'strategies' => \Laravel\RedisShard\Facades\ShardManager::strategies()->keys(),
            'default_strategy' => \Laravel\RedisShard\Facades\ShardManager::strategy()->getName(),
        ]);
    });
    
    // Create a new shard (this would typically be done via Artisan command)
    Route::post('/create', function (Illuminate\Http\Request $request) {
        $request->validate([
            'name' => 'required|string',
            'config' => 'required|array',
        ]);
        
        $success = \Laravel\RedisShard\Facades\ShardManager::createShard(
            $request->name,
            $request->config
        );
        
        return response()->json(['success' => $success]);
    });
    
    // Get shard health information
    Route::get('/health', function () {
        $shards = \Laravel\RedisShard\Facades\ShardManager::getAvailableShards();
        $health = [];
        
        foreach ($shards as $shard) {
            try {
                DB::connection($shard)->getPdo();
                $health[$shard] = 'healthy';
            } catch (Exception $e) {
                $health[$shard] = 'unhealthy';
            }
        }
        
        return response()->json($health);
    });
});

// Example of cross-shard aggregation
Route::get('/api/analytics/user-count-by-shard', function () {
    $shards = \Laravel\RedisShard\Facades\ShardManager::getAvailableShards();
    $counts = [];
    $total = 0;
    
    foreach ($shards as $shard) {
        $count = App\Models\User::on($shard)->count();
        $counts[$shard] = $count;
        $total += $count;
    }
    
    return response()->json([
        'total_users' => $total,
        'by_shard' => $counts,
        'shards' => count($shards),
    ]);
});

// Example of shard-aware caching
Route::get('/api/users/{email}/cached', function ($email) {
    $cacheKey = "user:{$email}";
    
    return Cache::remember($cacheKey, 3600, function () use ($email) {
        // Determine shard and get user
        $shard = \Laravel\RedisShard\Facades\ShardManager::getShardConnection('users', $email);
        $user = App\Models\User::on($shard)->where('email', $email)->first();
        
        return response()->json([
            'user' => $user,
            'shard' => $shard,
            'cached' => false,
        ]);
    });
});

// Example of batch operations across shards
Route::post('/api/users/batch-update', function (Illuminate\Http\Request $request) {
    $request->validate([
        'updates' => 'required|array',
        'updates.*.email' => 'required|email',
        'updates.*.data' => 'required|array',
    ]);
    
    $results = [];
    
    foreach ($request->updates as $update) {
        $email = $update['email'];
        $data = $update['data'];
        
        try {
            // Find the correct shard for this user
            $shard = \Laravel\RedisShard\Facades\ShardManager::getShardConnection('users', $email);
            $user = App\Models\User::on($shard)->where('email', $email)->first();
            
            if ($user) {
                $user->update($data);
                $results[] = [
                    'email' => $email,
                    'success' => true,
                    'shard' => $shard,
                ];
            } else {
                $results[] = [
                    'email' => $email,
                    'success' => false,
                    'error' => 'User not found',
                ];
            }
        } catch (Exception $e) {
            $results[] = [
                'email' => $email,
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
    
    return response()->json(['results' => $results]);
});

// Health check endpoint
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toISOString(),
        'sharding' => [
            'enabled' => true,
            'shards' => count(\Laravel\RedisShard\Facades\ShardManager::getAvailableShards()),
            'strategy' => \Laravel\RedisShard\Facades\ShardManager::strategy()->getName(),
        ],
    ]);
});
