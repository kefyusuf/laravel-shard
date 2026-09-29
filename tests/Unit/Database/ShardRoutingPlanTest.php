<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Unit\Database;

use Laravel\RedisShard\Database\ShardRoutingPlan;
use Laravel\RedisShard\Exceptions\ShardingException;
use Laravel\RedisShard\Tests\TestCase;

class ShardRoutingPlanTest extends TestCase
{
    /** @var array<string, int> */
    private int $resolveCalls = 0;

    private function resolver(?string $returns = 'shard1'): callable
    {
        return function (string $table, mixed $key) use ($returns): ?string {
            $this->resolveCalls++;

            return $returns;
        };
    }

    public function test_explicit_non_default_connection_wins(): void
    {
        $plan = ShardRoutingPlan::from(
            'email',
            'users',
            [],
            'shard1',
            'testing',
            $this->resolver(),
        );

        $this->assertSame(['mode' => 'single', 'connection' => 'shard1'], $plan);
        $this->assertSame(0, $this->resolveCalls);
    }

    public function test_default_connection_is_not_treated_as_explicit(): void
    {
        $plan = ShardRoutingPlan::from(
            'email',
            'users',
            [],
            'testing',
            'testing',
            $this->resolver('shard2'),
        );

        $this->assertSame(['mode' => 'none'], $plan);
    }

    public function test_basic_equality_on_shard_key_resolves_a_single_shard(): void
    {
        $plan = ShardRoutingPlan::from(
            'email',
            'users',
            [['type' => 'Basic', 'column' => 'users.email', 'operator' => '=', 'value' => 'a@x.com', 'boolean' => 'and']],
            null,
            'testing',
            $this->resolver('shard2'),
        );

        $this->assertSame(['mode' => 'single', 'connection' => 'shard2'], $plan);
    }

    public function test_in_constraint_groups_values_per_shard(): void
    {
        $valuesByShard = ['a@x.com' => 'shard1', 'b@x.com' => 'shard2', 'c@x.com' => 'shard1'];
        $plan = ShardRoutingPlan::from(
            'email',
            'users',
            [['type' => 'In', 'column' => 'users.email', 'values' => ['a@x.com', 'b@x.com', 'c@x.com'], 'boolean' => 'and']],
            null,
            'testing',
            fn (string $t, mixed $k) => $valuesByShard[$k],
        );

        $this->assertSame('many', $plan['mode']);
        $this->assertSame(['shard1' => ['a@x.com', 'c@x.com'], 'shard2' => ['b@x.com']], $plan['groups']);
    }

    public function test_in_constraint_with_a_single_shard_collapses_to_single(): void
    {
        $plan = ShardRoutingPlan::from(
            'email',
            'users',
            [['type' => 'In', 'column' => 'users.email', 'values' => ['a@x.com'], 'boolean' => 'and']],
            null,
            'testing',
            fn (string $t, mixed $k) => 'shard1',
        );

        $this->assertSame(['mode' => 'single', 'connection' => 'shard1'], $plan);
    }

    public function test_empty_in_constraint_yields_none(): void
    {
        $plan = ShardRoutingPlan::from(
            'email',
            'users',
            [['type' => 'In', 'column' => 'users.email', 'values' => [], 'boolean' => 'and']],
            null,
            'testing',
            $this->resolver(),
        );

        $this->assertSame(['mode' => 'none'], $plan);
    }

    public function test_or_predicates_are_unroutable(): void
    {
        $plan = ShardRoutingPlan::from(
            'email',
            'users',
            [['type' => 'Basic', 'column' => 'users.email', 'operator' => '=', 'value' => 'a@x.com', 'boolean' => 'or']],
            null,
            'testing',
            $this->resolver(),
        );

        $this->assertSame('unsupported', $plan['mode']);
        $this->assertStringContainsString('OR', $plan['reason']);
    }

    public function test_unresolvable_key_value_is_unsupported(): void
    {
        $plan = ShardRoutingPlan::from(
            'email',
            'users',
            [['type' => 'Basic', 'column' => 'users.email', 'operator' => '=', 'value' => 'a@x.com', 'boolean' => 'and']],
            null,
            'testing',
            fn (): ?string => null,
        );

        $this->assertSame('unsupported', $plan['mode']);
    }

    public function test_unsupported_predicate_type_names_the_type(): void
    {
        $plan = ShardRoutingPlan::from(
            'email',
            'users',
            [['type' => 'Like', 'column' => 'users.email', 'boolean' => 'and']],
            null,
            'testing',
            $this->resolver(),
        );

        $this->assertSame('unsupported', $plan['mode']);
        $this->assertStringContainsString('Like', $plan['reason']);
    }

    public function test_require_passes_routable_plans_through(): void
    {
        $plan = ShardRoutingPlan::require(['mode' => 'many', 'groups' => ['shard1' => ['a']]], 'read records', 'users');

        $this->assertSame('many', $plan['mode']);
    }

    public function test_require_throws_for_non_routable_plans(): void
    {
        $this->expectException(ShardingException::class);
        $this->expectExceptionMessage('Cannot safely read records on sharded table "users"');

        ShardRoutingPlan::require(['mode' => 'unsupported', 'reason' => 'nope'], 'read records', 'users');
    }
}
