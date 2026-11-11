<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Button } from '@/components/ui/button';

interface Run {
    id: number;
    source_key: string;
    state: string;
    status: string;
    trigger: string;
    requested_by: string | null;
    queued_at: string | null;
    started_at: string | null;
    finished_at: string | null;
    duration_ms: number | null;
    http_status: number | null;
    fetched_count: number;
    created_count: number;
    updated_count: number;
    error_message: string | null;
    warning_message: string | null;
    worker_warning: boolean;
}

interface Card {
    key: string;
    name: string;
    status: string;
    interval_minutes: number | null;
    last_attempt_at: string | null;
    last_success_at: string | null;
    next_run_estimate_at: string | null;
    last_fetched_count: number | null;
    last_processed_count: number | null;
    last_run_returned_zero: boolean;
    no_events_24h: boolean;
    last_error: string | null;
    consecutive_failures: number;
    last_duration_ms: number | null;
    latest_run: Run | null;
    latest_observation_at?: string | null;
    capitals_with_data?: number;
    capitals_total?: number;
    oldest_observation_at?: string | null;
    capitals_outdated?: number;
}

const cards = ref<Card[]>([]);
const runs = ref<Run[]>([]);
const loadError = ref<string | null>(null);
const requesting = ref(false);
const messages = ref<Record<string, string>>({});

const sourceFilter = ref<string>('all');

const SOURCE_LABELS: Record<string, string> = {
    gdacs: 'GDACS',
    usgs: 'USGS',
    open_meteo: 'Open-Meteo',
};

const visibleRuns = computed(() =>
    sourceFilter.value === 'all'
        ? runs.value
        : runs.value.filter((run) => run.source_key === sourceFilter.value),
);

function sourceLabel(key: string): string {
    return SOURCE_LABELS[key] ?? key;
}

function triggerLabel(run: Run): string {
    switch (run.trigger) {
        case 'manual':
            return run.requested_by
                ? `Manual (${run.requested_by})`
                : 'Manual (admin)';
        case 'catch_up':
            return 'Catch-up (after a missed schedule)';
        case 'scheduler':
            return 'Scheduled';
        default:
            return run.trigger;
    }
}

const busy = computed(() =>
    cards.value.some((card) =>
        ['queued', 'running'].includes(card.latest_run?.state ?? ''),
    ),
);

let timer: ReturnType<typeof setTimeout> | null = null;
let stopped = false;

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

async function load() {
    try {
        const response = await fetch('/admin/data-sources', {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const data = await response.json();
        cards.value = data.sources;
        runs.value = data.runs;
        loadError.value = null;
    } catch {
        loadError.value = 'Could not load source status.';
    }

    if (!stopped) {
        timer = setTimeout(load, busy.value ? 3000 : 15000);
    }
}

async function sync(source: 'gdacs' | 'usgs' | 'open_meteo' | 'all') {
    requesting.value = true;
    messages.value = {};

    try {
        const response = await fetch('/admin/data-sources/refresh', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify({ source }),
        });

        if (response.status === 429) {
            messages.value = { all: 'Too many requests. Wait a minute.' };

            return;
        }

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const data = await response.json();

        for (const [key, result] of Object.entries<{
            state: string;
            seconds_left?: number;
        }>(data.results)) {
            messages.value[key] = describeResult(result);
        }

        if (timer) {
            clearTimeout(timer);
        }

        await load();
    } catch {
        messages.value = { all: 'The request failed. Try again.' };
    } finally {
        requesting.value = false;
    }
}

function describeResult(result: {
    state: string;
    seconds_left?: number;
}): string {
    switch (result.state) {
        case 'queued':
            return 'Queued. It has not started yet; a queue worker must pick it up.';
        case 'already_running':
            return 'A sync was already running. Its result is shown below.';
        case 'already_queued':
            return 'A sync is already queued.';
        case 'cooldown':
            return `A sync finished moments ago. Try again in ${result.seconds_left ?? 60} s.`;
        default:
            return result.state;
    }
}

function time(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString() : '—';
}

function stateLabel(run: Run): string {
    switch (run.state) {
        case 'queued':
            return 'Queued, waiting for a worker. Not started yet.';
        case 'running':
            return `Running since ${time(run.started_at)}`;
        case 'success':
            return 'Success';
        case 'success_with_warnings':
            return 'Success with warnings';
        case 'failed':
            return 'Failed';
        case 'abandoned':
            return 'Abandoned (the process stopped before finishing)';
        default:
            return run.state;
    }
}

function stateClass(state: string): string {
    return (
        {
            success: 'text-emerald-600',
            success_with_warnings: 'text-amber-600',
            failed: 'text-destructive',
            abandoned: 'text-destructive',
            queued: 'text-sky-600',
            running: 'text-sky-600',
        }[state] ?? ''
    );
}

function statusClass(status: string): string {
    if (status === 'Healthy') return 'bg-emerald-500/15 text-emerald-700';
    if (status === 'Delayed') return 'bg-amber-500/15 text-amber-700';
    if (status === 'Never Synced') return 'bg-muted text-muted-foreground';

    return 'bg-destructive/15 text-destructive';
}

onMounted(load);
onUnmounted(() => {
    stopped = true;

    if (timer) {
        clearTimeout(timer);
    }
});
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center gap-2">
            <Button size="sm" :disabled="requesting" @click="sync('all')"
                >Sync from sources</Button
            >
            <Button
                size="sm"
                variant="outline"
                :disabled="requesting"
                @click="sync('gdacs')"
                >GDACS only</Button
            >
            <Button
                size="sm"
                variant="outline"
                :disabled="requesting"
                @click="sync('usgs')"
                >USGS only</Button
            >
            <Button
                size="sm"
                variant="outline"
                :disabled="requesting"
                @click="sync('open_meteo')"
                data-test="sync-open-meteo"
                >Open-Meteo only</Button
            >
            <span class="text-xs text-muted-foreground"
                >Fetches from GDACS, USGS and Open-Meteo through a queue
                worker. Each source also runs on its own schedule.</span
            >
        </div>

        <ul v-if="Object.keys(messages).length" class="space-y-1 text-sm">
            <li v-for="(text, key) in messages" :key="key">
                <strong v-if="key !== 'all'">{{ sourceLabel(String(key)) }}:</strong>
                {{ text }}
            </li>
        </ul>

        <p v-if="loadError" class="text-sm text-destructive">{{ loadError }}</p>

        <div class="grid gap-4 md:grid-cols-3">
            <article
                v-for="card in cards"
                :key="card.key"
                class="space-y-2 rounded-xl border border-border bg-card p-4 text-sm"
            >
                <div class="flex items-center justify-between gap-2">
                    <h3 class="font-semibold">{{ card.name }}</h3>
                    <span
                        :class="[
                            'rounded-full px-2 py-0.5 text-xs font-medium',
                            statusClass(card.status),
                        ]"
                        >{{ card.status }}</span
                    >
                </div>

                <dl class="grid grid-cols-2 gap-x-2 gap-y-1 text-xs">
                    <dt class="text-muted-foreground">Interval</dt>
                    <dd>{{ card.interval_minutes }} min</dd>
                    <dt class="text-muted-foreground">Last attempt</dt>
                    <dd>{{ time(card.last_attempt_at) }}</dd>
                    <dt class="text-muted-foreground">Last success</dt>
                    <dd>{{ time(card.last_success_at) }}</dd>
                    <dt class="text-muted-foreground">Next run (≈ estimated)</dt>
                    <dd>{{ time(card.next_run_estimate_at) }}</dd>
                    <dt class="text-muted-foreground">Received / processed</dt>
                    <dd>
                        {{ card.last_fetched_count ?? '—' }} /
                        {{ card.last_processed_count ?? '—' }}
                    </dd>
                    <dt class="text-muted-foreground">Last duration</dt>
                    <dd>
                        {{
                            card.last_duration_ms === null
                                ? '—'
                                : `${card.last_duration_ms} ms`
                        }}
                    </dd>
                    <dt class="text-muted-foreground">Failures in a row</dt>
                    <dd>{{ card.consecutive_failures }}</dd>
                    <template v-if="card.key === 'open_meteo'">
                        <dt class="text-muted-foreground">Newest observation</dt>
                        <dd>{{ time(card.latest_observation_at ?? null) }}</dd>
                        <dt class="text-muted-foreground">Oldest observation</dt>
                        <dd>{{ time(card.oldest_observation_at ?? null) }}</dd>
                        <dt class="text-muted-foreground">Capitals not updated</dt>
                        <dd
                            :class="
                                (card.capitals_outdated ?? 0) > 0
                                    ? 'text-amber-600'
                                    : ''
                            "
                            data-test="capitals-outdated"
                        >
                            {{ card.capitals_outdated ?? 0 }}
                        </dd>
                        <dt class="text-muted-foreground">Capitals with data</dt>
                        <dd>
                            {{ card.capitals_with_data }} of
                            {{ card.capitals_total }}
                        </dd>
                    </template>
                </dl>

                <p
                    v-if="card.last_run_returned_zero"
                    class="text-xs text-muted-foreground"
                >
                    Last run returned 0 events.
                </p>
                <p v-if="card.no_events_24h" class="text-xs text-amber-600">
                    No events for 24 hours: check the source.
                </p>
                <p v-if="card.last_error" class="text-xs text-destructive">
                    {{ card.last_error }}
                </p>

                <div
                    v-if="card.latest_run"
                    class="border-t border-border pt-2 text-xs"
                >
                    <p :class="stateClass(card.latest_run.state)">
                        {{ stateLabel(card.latest_run) }}
                    </p>
                    <p class="text-muted-foreground">
                        {{ triggerLabel(card.latest_run) }}
                        <span v-if="card.latest_run.http_status">
                            · HTTP {{ card.latest_run.http_status }}</span
                        >
                    </p>
                    <p
                        v-if="card.latest_run.error_message"
                        class="text-destructive"
                    >
                        {{ card.latest_run.error_message }}
                    </p>
                    <p
                        v-if="card.latest_run.warning_message"
                        class="text-amber-600"
                    >
                        {{ card.latest_run.warning_message }}
                    </p>
                    <p
                        v-if="card.latest_run.worker_warning"
                        class="text-amber-600"
                    >
                        Queue worker may not be running. Start it with:
                        <code>php artisan queue:work --timeout=240 --tries=1</code>
                    </p>
                </div>
            </article>
        </div>

        <div class="overflow-x-auto rounded-xl border border-border">
            <table class="w-full text-left text-xs">
                <caption class="p-3 text-left text-sm font-semibold">
                    <span class="mr-3">Recent runs (last 10 per source)</span>
                    <span class="inline-flex flex-wrap gap-1 align-middle">
                        <button
                            v-for="option in [
                                'all',
                                'gdacs',
                                'usgs',
                                'open_meteo',
                            ]"
                            :key="option"
                            type="button"
                            :data-test="`history-filter-${option}`"
                            :aria-pressed="sourceFilter === option"
                            :class="[
                                'rounded-full border px-2.5 py-0.5 text-xs font-medium',
                                sourceFilter === option
                                    ? 'border-transparent bg-primary text-primary-foreground'
                                    : 'border-border bg-card text-muted-foreground hover:text-foreground',
                            ]"
                            @click="sourceFilter = option"
                        >
                            {{
                                option === 'all'
                                    ? 'All'
                                    : sourceLabel(option)
                            }}
                        </button>
                    </span>
                </caption>
                <thead class="bg-muted/50 uppercase">
                    <tr>
                        <th class="p-2">Source</th>
                        <th class="p-2">Result</th>
                        <th class="p-2">Trigger</th>
                        <th class="p-2">Started</th>
                        <th class="p-2">Duration</th>
                        <th class="p-2">HTTP</th>
                        <th class="p-2">Fetched</th>
                        <th class="p-2">New / changed</th>
                        <th class="p-2">Note</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="run in visibleRuns"
                        :key="run.id"
                        class="border-t"
                        :data-test="`run-row-${run.source_key}`"
                    >
                        <td class="p-2">{{ sourceLabel(run.source_key) }}</td>
                        <td class="p-2" :class="stateClass(run.state)">
                            {{ stateLabel(run) }}
                        </td>
                        <td class="p-2" data-test="run-trigger">
                            {{ triggerLabel(run) }}
                        </td>
                        <td class="p-2">{{ time(run.started_at) }}</td>
                        <td class="p-2">
                            {{
                                run.duration_ms === null
                                    ? '—'
                                    : `${run.duration_ms} ms`
                            }}
                        </td>
                        <td class="p-2">{{ run.http_status ?? '—' }}</td>
                        <td class="p-2">{{ run.fetched_count }}</td>
                        <td class="p-2">
                            {{ run.created_count }} / {{ run.updated_count }}
                        </td>
                        <td class="p-2">
                            {{ run.error_message ?? run.warning_message ?? '' }}
                        </td>
                    </tr>
                    <tr v-if="visibleRuns.length === 0">
                        <td colspan="9" class="p-4 text-center text-muted-foreground">
                            No runs yet.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
