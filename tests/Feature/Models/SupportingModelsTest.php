<?php

declare(strict_types=1);

use App\Models\Attachment;
use App\Models\Category;
use App\Models\TicketImport;
use Illuminate\Database\Eloquent\Model;

it('offers only active categories', function (): void {
    $active = Category::factory()->create();
    Category::factory()->create(['is_active' => false]);

    expect(Category::query()->active()->pluck('id')->all())->toBe([$active->id]);
});

it('never mass-assigns system-controlled columns', function (Model $model, array $fillable): void {
    expect($model->getFillable())->toBe($fillable);
})->with([
    'attachment' => [fn (): Model => new Attachment, []],
    'ticket import' => [fn (): Model => new TicketImport, ['original_name', 'path']],
]);
