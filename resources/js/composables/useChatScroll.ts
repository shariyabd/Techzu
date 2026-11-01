import { usePreferredReducedMotion, useResizeObserver } from '@vueuse/core';
import type { Ref } from 'vue';
import { nextTick, onMounted, ref } from 'vue';

const NEAR_BOTTOM_PX = 120;
const ANCHOR_SELECTOR = '[data-chat-anchor]';

type Anchor = { id: string; offset: number };

export function useChatScroll(
    scroller: Ref<HTMLElement | null>,
    content: Ref<HTMLElement | null>,
) {
    const reducedMotion = usePreferredReducedMotion();
    const nearBottom = ref(true);
    let stuckToBottom = true;
    let anchor: Anchor | null = null;
    let wasStuck = true;

    function distanceFromBottom(element: HTMLElement): number {
        return element.scrollHeight - element.scrollTop - element.clientHeight;
    }

    function onScroll(): void {
        const element = scroller.value;

        if (!element) {
            return;
        }

        nearBottom.value = distanceFromBottom(element) <= NEAR_BOTTOM_PX;
        stuckToBottom = distanceFromBottom(element) <= 4;
    }

    function scrollToBottom(smooth = false): void {
        const element = scroller.value;

        if (!element) {
            return;
        }

        stuckToBottom = true;
        nearBottom.value = true;
        element.scrollTo({
            top: element.scrollHeight,
            behavior:
                smooth && reducedMotion.value !== 'reduce' ? 'smooth' : 'auto',
        });
    }

    function findAnchor(element: HTMLElement): Anchor | null {
        const top = element.getBoundingClientRect().top;

        for (const item of element.querySelectorAll<HTMLElement>(
            ANCHOR_SELECTOR,
        )) {
            const rect = item.getBoundingClientRect();

            if (rect.bottom > top) {
                return {
                    id: item.dataset.chatAnchor ?? '',
                    offset: rect.top - top,
                };
            }
        }

        return null;
    }

    function captureBeforeUpdate(preserveReadingPosition = false): void {
        const element = scroller.value;
        wasStuck = !preserveReadingPosition && nearBottom.value;
        anchor = element && !wasStuck ? findAnchor(element) : null;
    }

    function restoreAfterUpdate(): boolean {
        const element = scroller.value;

        if (!element) {
            return wasStuck;
        }

        if (wasStuck) {
            return true;
        }

        if (anchor) {
            const target = element.querySelector<HTMLElement>(
                `[data-chat-anchor="${CSS.escape(anchor.id)}"]`,
            );

            if (target) {
                const top = element.getBoundingClientRect().top;
                element.scrollTop +=
                    target.getBoundingClientRect().top - top - anchor.offset;
            }
        }

        anchor = null;
        onScroll();

        return false;
    }

    useResizeObserver(content, () => {
        if (stuckToBottom) {
            scrollToBottom();
        }
    });

    onMounted(() => nextTick(() => scrollToBottom()));

    return {
        nearBottom,
        onScroll,
        scrollToBottom,
        captureBeforeUpdate,
        restoreAfterUpdate,
    };
}
