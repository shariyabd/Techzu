<?php

declare(strict_types=1);

use App\Services\Tickets\FulltextSearchTerm;

it('turns user input into a safe boolean-mode expression', function (string $input, string $expected): void {
    expect(FulltextSearchTerm::toBooleanMode($input))->toBe($expected);
})->with([
    'plain words get a prefix wildcard' => ['printer jam', 'printer* jam*'],
    'words under 3 characters get no wildcard' => ['pc on fire', 'pc on fire*'],
    'required and excluded operators are stripped' => ['+printer -jam', 'printer* jam*'],
    'grouping and phrase quotes are stripped' => ['("printer jam")', 'printer* jam*'],
    'relevance and negation operators are stripped' => ['>urgent <later ~maybe', 'urgent* later* maybe*'],
    'user wildcards and proximity are stripped' => ['print* "a b" @3', 'print* a b 3'],
    'operators glued inside a word split it' => ['e-mail', 'e mail*'],
    'only operators leaves nothing' => ['+-<>()~*"@', ''],
    'whitespace is collapsed' => ["  printer \t\n jam  ", 'printer* jam*'],
    'multibyte length counts characters' => ['café crème', 'café* crème*'],
]);

it('keeps at most ten terms', function (): void {
    $input = implode(' ', array_map(fn (int $i): string => "word{$i}", range(1, 12)));

    expect(explode(' ', FulltextSearchTerm::toBooleanMode($input)))->toHaveCount(10);
});
