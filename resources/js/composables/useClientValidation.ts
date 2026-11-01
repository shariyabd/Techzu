import type { FormComponentRef, PendingVisit } from '@inertiajs/core';
import { toValue, useTemplateRef } from 'vue';
import type { MaybeRefOrGetter } from 'vue';
import { validateField, validateForm } from '@/lib/validation';
import type { FormValues, ValidationSchema } from '@/lib/validation';

export function useClientValidation(
    schema: MaybeRefOrGetter<ValidationSchema>,
    formRef = 'form',
) {
    const form = useTemplateRef<FormComponentRef>(formRef);

    function fieldName(event: Event): string | null {
        const target = event.target;

        if (
            !(target instanceof HTMLInputElement) &&
            !(target instanceof HTMLTextAreaElement)
        ) {
            return null;
        }

        return target.name in toValue(schema) ? target.name : null;
    }

    function onBefore(visit: PendingVisit): boolean {
        const errors = validateForm(toValue(schema), visit.data as FormValues);

        if (Object.keys(errors).length === 0) {
            return true;
        }

        form.value?.clearErrors();
        form.value?.setError(errors);

        return false;
    }

    function onInput(event: Event): void {
        const name = fieldName(event);

        if (name !== null && form.value?.errors[name]) {
            form.value.clearErrors(name);
        }
    }

    function onFocusOut(event: FocusEvent): void {
        const name = fieldName(event);

        if (name === null || form.value === null) {
            return;
        }

        const values = form.value.getData() as FormValues;

        if (values[name] === '' || values[name] === undefined) {
            return;
        }

        const message = validateField(toValue(schema), name, values);

        if (message !== null) {
            form.value.setError(name, message);
        }
    }

    return { onBefore, onInput, onFocusOut };
}
