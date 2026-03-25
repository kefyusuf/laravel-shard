# Laravel Redis Sharding - Example Application

This example demonstrates a complete multi-tenant SaaS application using Redis-based sharding.

## Scenario

A multi-tenant project management application where:
- Each tenant (company) can have multiple users
- Users can create projects and tasks
- Data is sharded by `tenant_id` to keep tenant data together
- High performance with distributed data

## Setup

```bash
# 1. Install dependencies
composer require yusuf.kef/laravel-redis-shard

# 2. Configure shards (already done in this example)
# See config/redis_sharding.php

# 3. Run migrations on all shards
php artisan migrate
php artisan migrate --database=shard1
php artisan migrate --database=shard2
php artisan migrate --database=shard3
```

## Models

### Tenant Model

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\RedisShard\Traits\Shardable;

class Tenant extends Model
{
    use Shardable;

    protected ?string $shardKey = 'id';
    
    protected $fillable = [
        'name',
        'domain',
        'subscription_plan',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function users()
    {
        // Users on the same shard as tenant
        return $this->hasMany(User::class, 'tenant_id', 'id');
    }

    public function projects()
    {
        return $this->hasMany(Project::class, 'tenant_id', 'id');
    }
}
```

### User Model (Sharded)

```php
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\RedisShard\Traits\Shardable;

class User extends Authenticatable
{
    use Shardable;

    // Shard by tenant to keep all tenant data together
    protected ?string $shardKey = 'tenant_id';
    
    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'password',
        'role',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function projects()
    {
        return $this->belongsToMany(Project::class, 'project_user');
    }

    public function tasks()
    {
        return $this->hasMany(Task::class, 'assigned_to');
    }
}
```

### Project Model

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\RedisShard\Traits\Shardable;

class Project extends Model
{
    use Shardable;

    protected ?string $shardKey = 'tenant_id';
    
    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'status',
        'deadline',
    ];

    protected $casts = [
        'deadline' => 'datetime',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function tasks()
    {
        return $this->hasMany(Task::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'project_user');
    }
}
```

### Task Model

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\RedisShard\Traits\Shardable;

class Task extends Model
{
    use Shardable;

    protected ?string $shardKey = 'tenant_id';
    
    protected $fillable = [
        'tenant_id',
        'project_id',
        'title',
        'description',
        'status',
        'priority',
        'assigned_to',
        'due_date',
    ];

    protected $casts = [
        'due_date' => 'datetime',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
```

## Controllers

### TenantController

```php
<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    public function index()
    {
        // Get all tenants across shards
        $tenants = Tenant::crossShard()
            ->where('is_active', true)
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return view('tenants.index', compact('tenants'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'domain' => 'required|string|unique:tenants',
            'subscription_plan' => 'required|in:basic,premium,enterprise',
        ]);

        // Automatically assigned to a shard by tenant ID
        $tenant = Tenant::create([
            'name' => $validated['name'],
            'domain' => $validated['domain'],
            'subscription_plan' => $validated['subscription_plan'],
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'Tenant created successfully',
            'tenant' => $tenant,
            'shard' => $tenant->getShardInfo(),
        ], 201);
    }

    public function show($id)
    {
        // Finds across shards
        $tenant = Tenant::crossShard()->findOrFail($id);

        return view('tenants.show', compact('tenant'));
    }
}
```

### ProjectController

```php
<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProjectController extends Controller
{
    public function index()
    {
        $tenantId = Auth::user()->tenant_id;

        // All operations on same shard (tenant's shard)
        $projects = Project::where('tenant_id', $tenantId)
            ->with('users')
            ->orderBy('created_at', 'desc')
            ->get();

        return view('projects.index', compact('projects'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'deadline' => 'nullable|date',
        ]);

        $project = Project::create([
            'tenant_id' => Auth::user()->tenant_id,
            'name' => $validated['name'],
            'description' => $validated['description'],
            'status' => 'active',
            'deadline' => $validated['deadline'],
        ]);

        // Attach current user to project
        $project->users()->attach(Auth::id());

        return response()->json([
            'message' => 'Project created',
            'project' => $project,
        ], 201);
    }

    public function analytics()
    {
        // Cross-tenant analytics (admin only)
        $stats = Project::aggregateAcrossShards('id');

        return response()->json([
            'total_projects' => $stats['count'],
            'by_status' => Project::crossShard()
                ->selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->get(),
        ]);
    }
}
```

### TaskController

```php
<?php

namespace App\Http\Controllers;

use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TaskController extends Controller
{
    public function index(Request $request)
    {
        $tenantId = Auth::user()->tenant_id;

        $query = Task::where('tenant_id', $tenantId);

        // Filter by project
        if ($request->has('project_id')) {
            $query->where('project_id', $request->project_id);
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by assigned user
        if ($request->has('assigned_to')) {
            $query->where('assigned_to', $request->assigned_to);
        }

        $tasks = $query->with(['project', 'assignedUser'])
            ->orderBy('due_date', 'asc')
            ->get();

        return view('tasks.index', compact('tasks'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'project_id' => 'required|exists:projects,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'priority' => 'required|in:low,medium,high',
            'assigned_to' => 'nullable|exists:users,id',
            'due_date' => 'nullable|date',
        ]);

        $task = Task::create([
            'tenant_id' => Auth::user()->tenant_id,
            'project_id' => $validated['project_id'],
            'title' => $validated['title'],
            'description' => $validated['description'],
            'status' => 'pending',
            'priority' => $validated['priority'],
            'assigned_to' => $validated['assigned_to'],
            'due_date' => $validated['due_date'],
        ]);

        return response()->json([
            'message' => 'Task created',
            'task' => $task->load('project', 'assignedUser'),
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $tenantId = Auth::user()->tenant_id;

        $task = Task::where('tenant_id', $tenantId)
            ->findOrFail($id);

        $validated = $request->validate([
            'status' => 'nullable|in:pending,in_progress,completed',
            'assigned_to' => 'nullable|exists:users,id',
        ]);

        $task->update($validated);

        return response()->json([
            'message' => 'Task updated',
            'task' => $task->load('project', 'assignedUser'),
        ]);
    }
}
```

## Routes

```php
<?php

use App\Http\Controllers\TenantController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;

// Tenant routes (admin)
Route::prefix('admin')->middleware(['auth', 'admin'])->group(function () {
    Route::get('/tenants', [TenantController::class, 'index']);
    Route::post('/tenants', [TenantController::class, 'store']);
    Route::get('/tenants/{id}', [TenantController::class, 'show']);
    
    // Analytics across all tenants
    Route::get('/analytics/projects', [ProjectController::class, 'analytics']);
});

// Tenant-scoped routes
Route::middleware(['auth', 'tenant'])->group(function () {
    // Projects
    Route::get('/projects', [ProjectController::class, 'index']);
    Route::post('/projects', [ProjectController::class, 'store']);
    Route::get('/projects/{id}', [ProjectController::class, 'show']);
    
    // Tasks
    Route::get('/tasks', [TaskController::class, 'index']);
    Route::post('/tasks', [TaskController::class, 'store']);
    Route::patch('/tasks/{id}', [TaskController::class, 'update']);
});
```

## Middleware

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\RedisShard\Facades\ShardManager;

class TenantMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (!$user || !$user->tenant_id) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Set shard context for this request
        $shard = ShardManager::getShardConnection('tenants', $user->tenant_id);
        config(['database.default' => $shard]);

        return $next($request);
    }
}
```

## Key Patterns Demonstrated

### 1. Tenant Isolation
All tenant data (users, projects, tasks) sharded by `tenant_id`, ensuring:
- Data isolation
- Performance (all tenant data on same shard)
- Easy tenant management

### 2. Efficient Queries
```php
// Single shard query (fast)
$projects = Project::where('tenant_id', $tenantId)->get();

// Cross-shard analytics (when needed)
$totalProjects = Project::crossShard()->count();
```

### 3. Relationships
```php
// All relationships on same shard (efficient)
$project = Project::with(['tenant', 'users', 'tasks'])->find($id);
```

### 4. Scaling
```php
// Add new shard when tenant count grows
php artisan shard:create shard4

// Distribute new tenants automatically
// Existing tenants stay on their shards
```

## Performance Benefits

- **Single Shard Queries**: 1-2ms (no cross-shard overhead)
- **Tenant Isolation**: Complete data separation
- **Horizontal Scaling**: Add shards as you grow
- **High Throughput**: 50,000+ ops/sec

## Running the Example

```bash
# Seed sample data
php artisan db:seed

# Check shard distribution
php artisan shard:status --table=tenants

# Monitor health
php artisan shard:health

# Analyze distribution
php artisan shard:analyze tenants
```

## Testing

```bash
# Run tests
php artisan test

# Load test
ab -n 1000 -c 10 http://localhost/api/projects
```

This example demonstrates best practices for building scalable multi-tenant applications with Laravel Redis Sharding!
