import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';

export type ToastVariant = 'success' | 'error' | 'warning' | 'info';

export type FlashMessages = Partial<
    Record<ToastVariant | 'description', string | null>
>;

const variants: readonly ToastVariant[] = [
    'success',
    'error',
    'warning',
    'info',
];

const durations: Record<ToastVariant, number> = {
    success: 4000,
    info: 5000,
    warning: 7000,
    error: 9000,
};

export type ToastAction = {
    label: string;
    onClick: () => void;
};

export function showToast(
    variant: ToastVariant,
    message: string,
    description?: string | null,
    action?: ToastAction,
): string | number {
    return toast[variant](message, {
        description: description ?? undefined,
        duration: durations[variant],
        action,
    });
}

export function showFlashToasts(flash: FlashMessages | null | undefined): void {
    if (!flash) {
        return;
    }

    const shown = variants.filter((variant) => {
        const message = flash[variant];

        return typeof message === 'string' && message.trim() !== '';
    });

    shown.forEach((variant, index) => {
        const description =
            index === shown.length - 1 ? flash.description : null;

        showToast(variant, flash[variant] as string, description);
    });
}

function flashFrom(props: Record<string, unknown>): FlashMessages | undefined {
    const flash = props.flash;

    return typeof flash === 'object' && flash !== null
        ? (flash as FlashMessages)
        : undefined;
}

export function carriesFreshFlash(visit: {
    only: string[];
    except: string[];
}): boolean {
    if (visit.only.length > 0) {
        return visit.only.includes('flash');
    }

    return !visit.except.includes('flash');
}

export function initializeFlashToast(): void {
    const partialVisits = new Set<string>();

    router.on('navigate', (event) => {
        if (event.detail.type === 'initial') {
            showFlashToasts(flashFrom(event.detail.page.props));
        }
    });

    router.on('start', (event) => {
        if (!carriesFreshFlash(event.detail.visit)) {
            partialVisits.add(event.detail.visit.id);
        }
    });

    router.on('finish', (event) => {
        const visitId = (event.detail.visit as { id?: string }).id;

        if (visitId !== undefined) {
            setTimeout(() => partialVisits.delete(visitId), 0);
        }
    });

    router.on('success', (event) => {
        const visitId = event.detail.visitId;

        if (visitId !== undefined && partialVisits.has(visitId)) {
            return;
        }

        showFlashToasts(flashFrom(event.detail.page.props));
    });

    router.on('networkError', () => {
        showToast(
            'error',
            'The server could not be reached.',
            'Check your connection and try again.',
        );
    });
}
