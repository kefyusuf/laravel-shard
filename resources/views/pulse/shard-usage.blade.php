<x-pulse::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header
        name="Shard Usage"
        x-bind:title="`Time: {{ number_format($time) }}ms; Run at: ${formatDate('{{ $runAt }}')};`"
        details="past {{ $this->periodForHumans() }}"
    >
        <x-slot:icon>
            <x-pulse::icons.database />
        </x-slot:icon>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand" wire:poll.5s="">
        @if ($usage->isEmpty())
            <x-pulse::no-results />
        @else
            <table class="w-full table-fixed">
                <thead>
                    <tr class="text-left text-xs font-semibold text-gray-500 dark:text-gray-400">
                        <th class="pb-2">Shard</th>
                        <th class="pb-2 text-right">Requests</th>
                        <th class="pb-2 text-right">Resolutions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($usage as $row)
                        <tr
                            wire:key="{{ $row->key }}"
                            class="text-sm text-gray-700 dark:text-gray-300"
                        >
                            <td class="py-1.5 truncate">{{ $row->key }}</td>
                            <td class="py-1.5 text-right tabular-nums">{{ number_format((int) $row->count) }}</td>
                            <td class="py-1.5 text-right tabular-nums">{{ number_format((int) $row->sum) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-pulse::scroll>
</x-pulse::card>
