export type FormValues = Record<string, unknown>;

export type FieldRule = (value: string, values: FormValues) => string | null;

export type ValidationSchema = Record<string, FieldRule[]>;

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

export const MAX_EMAIL_LENGTH = 255;

export const MAX_NAME_LENGTH = 255;

export function required(label: string): FieldRule {
    return (value) => (value.trim() === '' ? `${label} is required.` : null);
}

export function email(): FieldRule {
    return (value) =>
        value !== '' && !EMAIL_PATTERN.test(value)
            ? 'Enter a valid email address.'
            : null;
}

export function lowercase(label: string): FieldRule {
    return (value) =>
        value !== value.toLowerCase()
            ? `${label} must be in lower case.`
            : null;
}

export function minLength(label: string, min: number): FieldRule {
    return (value) =>
        value !== '' && value.length < min
            ? `${label} must be at least ${min} characters.`
            : null;
}

export function maxLength(label: string, max: number): FieldRule {
    return (value) =>
        value.length > max
            ? `${label} must not be longer than ${max} characters.`
            : null;
}

export function sameAs(field: string, message: string): FieldRule {
    return (value, values) =>
        value !== '' && value !== stringValue(values[field]) ? message : null;
}

export function validateField(
    schema: ValidationSchema,
    field: string,
    values: FormValues,
): string | null {
    for (const rule of schema[field] ?? []) {
        const message = rule(stringValue(values[field]), values);

        if (message !== null) {
            return message;
        }
    }

    return null;
}

export function validateForm(
    schema: ValidationSchema,
    values: FormValues,
): Record<string, string> {
    const errors: Record<string, string> = {};

    for (const field of Object.keys(schema)) {
        const message = validateField(schema, field, values);

        if (message !== null) {
            errors[field] = message;
        }
    }

    return errors;
}

function stringValue(value: unknown): string {
    return typeof value === 'string' ? value : '';
}
