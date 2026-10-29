import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import type { SharedProps } from '@/types/shared';

export function initializeFlashToast(): void {
    router.on('success', (event) => {
        const flash = event.detail.page.props.flash as
            | SharedProps['flash']
            | undefined;

        if (flash?.success) {
            toast.success(flash.success);
        }

        if (flash?.error) {
            toast.error(flash.error);
        }
    });

    router.on('networkError', () => {
        toast.error(
            'The server could not be reached. Check your connection and try again.',
        );
    });
}
