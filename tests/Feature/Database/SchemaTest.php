<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/** @return list<array{columns: list<string>, unique: bool, type: string}> */
function schemaIndexesOn(string $table): array
{
    $rows = DB::select(
        'SELECT INDEX_NAME AS index_name, COLUMN_NAME AS column_name, NON_UNIQUE AS non_unique, INDEX_TYPE AS index_type
           FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
          ORDER BY INDEX_NAME, SEQ_IN_INDEX',
        [$table],
    );

    $indexes = [];

    foreach ($rows as $row) {
        $indexes[$row->index_name]['columns'][] = $row->column_name;
        $indexes[$row->index_name]['unique'] = (int) $row->non_unique === 0;
        $indexes[$row->index_name]['type'] = $row->index_type;
    }

    return array_values(array_map(
        fn (array $index): array => ['columns' => $index['columns'], 'unique' => $index['unique'], 'type' => $index['type']],
        $indexes,
    ));
}

/** @return list<array{column: string, references: string, on_delete: string}> */
function schemaForeignKeysOn(string $table): array
{
    $rows = DB::select(
        'SELECT k.COLUMN_NAME AS column_name, k.REFERENCED_TABLE_NAME AS referenced_table, r.DELETE_RULE AS delete_rule
           FROM information_schema.KEY_COLUMN_USAGE k
           JOIN information_schema.REFERENTIAL_CONSTRAINTS r
             ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
          WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL',
        [$table],
    );

    return array_map(
        fn (object $row): array => ['column' => $row->column_name, 'references' => $row->referenced_table, 'on_delete' => $row->delete_rule],
        $rows,
    );
}

dataset('required indexes', [
    'users(email) unique' => ['users', ['email'], true, 'BTREE'],
    'users(is_available)' => ['users', ['is_available'], false, 'BTREE'],
    'model_has_roles(role_id, model_id, model_type)' => ['model_has_roles', ['role_id', 'model_id', 'model_type'], true, 'BTREE'],
    'categories(slug) unique' => ['categories', ['slug'], true, 'BTREE'],
    'agent_category primary (user_id, category_id)' => ['agent_category', ['user_id', 'category_id'], true, 'BTREE'],
    'agent_category(category_id, user_id)' => ['agent_category', ['category_id', 'user_id'], false, 'BTREE'],
    'tickets(number) unique' => ['tickets', ['number'], true, 'BTREE'],
    'tickets(status, sla_due_at)' => ['tickets', ['status', 'sla_due_at'], false, 'BTREE'],
    'tickets(status, sla_warning_at)' => ['tickets', ['status', 'sla_warning_at'], false, 'BTREE'],
    'tickets(assigned_agent_id, status)' => ['tickets', ['assigned_agent_id', 'status'], false, 'BTREE'],
    'tickets(customer_id, created_at)' => ['tickets', ['customer_id', 'created_at'], false, 'BTREE'],
    'tickets(category_id, status)' => ['tickets', ['category_id', 'status'], false, 'BTREE'],
    'tickets(priority, status)' => ['tickets', ['priority', 'status'], false, 'BTREE'],
    'tickets(created_at)' => ['tickets', ['created_at'], false, 'BTREE'],
    'tickets(status, resolved_at)' => ['tickets', ['status', 'resolved_at'], false, 'BTREE'],
    'tickets(updated_at)' => ['tickets', ['updated_at'], false, 'BTREE'],
    'tickets FULLTEXT(subject, description)' => ['tickets', ['subject', 'description'], false, 'FULLTEXT'],
    'tickets(external_reference) unique' => ['tickets', ['external_reference'], true, 'BTREE'],
    'ticket_sequences primary (year)' => ['ticket_sequences', ['year'], true, 'BTREE'],
    'ticket_replies(ticket_id, is_internal, created_at)' => ['ticket_replies', ['ticket_id', 'is_internal', 'created_at'], false, 'BTREE'],
    'attachments(ticket_id, is_internal)' => ['attachments', ['ticket_id', 'is_internal'], false, 'BTREE'],
    'attachments(reply_id)' => ['attachments', ['reply_id'], false, 'BTREE'],
    'activity_log(subject_type, subject_id, created_at)' => ['activity_log', ['subject_type', 'subject_id', 'created_at'], false, 'BTREE'],
    'activity_log(created_at)' => ['activity_log', ['created_at'], false, 'BTREE'],
    'holidays(date) unique' => ['holidays', ['date'], true, 'BTREE'],
    'ticket_imports(user_id, created_at)' => ['ticket_imports', ['user_id', 'created_at'], false, 'BTREE'],
    'ticket_import_errors(ticket_import_id, row_number)' => ['ticket_import_errors', ['ticket_import_id', 'row_number'], false, 'BTREE'],
    'notifications(notifiable_type, notifiable_id, created_at)' => ['notifications', ['notifiable_type', 'notifiable_id', 'created_at'], false, 'BTREE'],
    'notifications(read_at, created_at)' => ['notifications', ['read_at', 'created_at'], false, 'BTREE'],
]);

it('has the TD-021 index', function (string $table, array $columns, bool $unique, string $type): void {
    expect(schemaIndexesOn($table))->toContain(['columns' => $columns, 'unique' => $unique, 'type' => $type]);
})->with('required indexes');

dataset('foreign keys', [
    'tickets.category_id → categories, restrict' => ['tickets', 'category_id', 'categories', 'RESTRICT'],
    'tickets.customer_id → users, restrict' => ['tickets', 'customer_id', 'users', 'RESTRICT'],
    'tickets.created_by_id → users, restrict' => ['tickets', 'created_by_id', 'users', 'RESTRICT'],
    'tickets.assigned_agent_id → users, set null' => ['tickets', 'assigned_agent_id', 'users', 'SET NULL'],
    'agent_category.user_id → users, cascade' => ['agent_category', 'user_id', 'users', 'CASCADE'],
    'agent_category.category_id → categories, cascade' => ['agent_category', 'category_id', 'categories', 'CASCADE'],
    'ticket_replies.ticket_id → tickets, cascade' => ['ticket_replies', 'ticket_id', 'tickets', 'CASCADE'],
    'ticket_replies.author_id → users, restrict' => ['ticket_replies', 'author_id', 'users', 'RESTRICT'],
    'attachments.ticket_id → tickets, cascade' => ['attachments', 'ticket_id', 'tickets', 'CASCADE'],
    'attachments.reply_id → ticket_replies, cascade' => ['attachments', 'reply_id', 'ticket_replies', 'CASCADE'],
    'attachments.uploaded_by → users, restrict' => ['attachments', 'uploaded_by', 'users', 'RESTRICT'],
    'ticket_imports.user_id → users, restrict' => ['ticket_imports', 'user_id', 'users', 'RESTRICT'],
    'ticket_import_errors.ticket_import_id → ticket_imports, cascade' => ['ticket_import_errors', 'ticket_import_id', 'ticket_imports', 'CASCADE'],
]);

it('declares the TD-025 delete rule', function (string $table, string $column, string $references, string $onDelete): void {
    expect(schemaForeignKeysOn($table))->toContain(['column' => $column, 'references' => $references, 'on_delete' => $onDelete]);
})->with('foreign keys');

it('has no soft-delete columns (TD-025)', function (string $table): void {
    expect(DB::getSchemaBuilder()->hasTable($table))->toBeTrue()
        ->and(DB::getSchemaBuilder()->hasColumn($table, 'deleted_at'))->toBeFalse();
})->with(['tickets', 'ticket_replies', 'attachments', 'categories', 'users']);

it('keeps roles out of the users table (TD-010)', function (): void {
    expect(DB::getSchemaBuilder()->hasColumn('users', 'role'))->toBeFalse();
});
