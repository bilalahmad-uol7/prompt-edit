import { usePoll } from '@inertiajs/vue3';
import { watch } from 'vue';

/**
 * Re-fetches the given props every few seconds, but only while `active()` is true
 * (i.e. while some generation is still in flight). Polling stops by itself once
 * everything has settled, and Inertia throttles it when the tab is in the background.
 */
export function useLiveUpdates(
    active: () => boolean,
    only: string[],
    intervalMs = 2500,
): void {
    const { start, stop } = usePoll(
        intervalMs,
        { only: [...only, 'credits'] },
        { autoStart: false },
    );

    watch(active, (isActive) => (isActive ? start() : stop()), {
        immediate: true,
    });
}
