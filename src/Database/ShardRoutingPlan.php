<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Database;

use Laravel\RedisShard\Exceptions\ShardingException;

/**
 * Pure routing-plan inference for shard-aware queries.
 *
 * Given the query's where-constraints, decides whether an operation is
 * routable to a single shard ('single'), to a fixed set of per-shard key
 * groups ('many'), matches nothing ('none'), or cannot be routed safely
 * ('unsupported'). No container, connection, or I/O access: shard key
 * resolution is injected as a callable, so plans are unit-testable without
 * a database.
 */
final class ShardRoutingPlan
{
    /**
     * @param string $shardKeyName the model's shard key column
     * @param string $table the model table
     * @param array<int, array<string, mixed>> $wheres the query's where constraints
     * @param string|null $explicitConnection the model's pinned connection, if any
     * @param string $defaultConnection the application default connection name
     * @param callable(string $table, mixed $key): ?string $resolveConnectionForKey
     * @return array{mode: string, connection?: string, groups?: array<string, array<int, string>>, reason?: string}
     */
    public static function from(
        string $shardKeyName,
        string $table,
        array $wheres,
        ?string $explicitConnection,
        string $defaultConnection,
        callable $resolveConnectionForKey
    ): array {
        if (is_string($explicitConnection) && $explicitConnection !== '' && $explicitConnection !== $defaultConnection) {
            return ['mode' => 'single', 'connection' => $explicitConnection];
        }

        foreach ($wheres as $where) {
            $column = isset($where['column']) && is_string($where['column']) && str_contains($where['column'], '.')
                ? (string) substr(strrchr($where['column'], '.'), 1)
                : (string) ($where['column'] ?? '');

            if ($column !== $shardKeyName) {
                continue;
            }

            if (($where['boolean'] ?? 'and') !== 'and') {
                return [
                    'mode' => 'unsupported',
                    'reason' => 'Shard key constraints combined with OR predicates are not routable safely.',
                ];
            }

            $type = $where['type'] ?? null;

            if ($type === 'Basic' && ($where['operator'] ?? null) === '=') {
                $connection = $resolveConnectionForKey($table, $where['value'] ?? null);

                return $connection !== null
                    ? ['mode' => 'single', 'connection' => $connection]
                    : ['mode' => 'unsupported', 'reason' => 'Unable to resolve shard for the shard key constraint.'];
            }

            if (in_array($type, ['In', 'InRaw', 'IntegerInRaw'], true)) {
                $values = array_values(array_unique(array_map(static fn (mixed $value): string => (string) $value, $where['values'] ?? [])));

                if ($values === []) {
                    return ['mode' => 'none'];
                }

                $groups = [];

                foreach ($values as $value) {
                    $connection = $resolveConnectionForKey($table, $value);

                    if ($connection === null) {
                        return [
                            'mode' => 'unsupported',
                            'reason' => 'Unable to resolve every shard key in the whereIn constraint.',
                        ];
                    }

                    $groups[$connection][] = $value;
                }

                if (count($groups) === 1) {
                    return ['mode' => 'single', 'connection' => array_key_first($groups)];
                }

                return ['mode' => 'many', 'groups' => $groups];
            }

            return [
                'mode' => 'unsupported',
                'reason' => sprintf('Shard key predicate type "%s" is not supported for implicit routing.', (string) $type),
            ];
        }

        return ['mode' => 'none'];
    }

    /**
     * @param array{mode: string, connection?: string, groups?: array<string, array<int, string>>, reason?: string} $plan
     * @return array{mode: string, connection?: string, groups?: array<string, array<int, string>>, reason?: string}
     * @throws ShardingException when the plan is not safely routable
     */
    public static function require(array $plan, string $operation, string $table): array
    {
        if (in_array($plan['mode'], ['single', 'many'], true)) {
            return $plan;
        }

        $reason = $plan['reason'] ?? 'Provide an explicit shard-bound connection or shard key predicate.';

        throw new ShardingException(sprintf(
            'Cannot safely %s on sharded table "%s" without deterministic shard routing. %s',
            $operation,
            $table,
            $reason
        ));
    }
}
