<?php

declare(strict_types=1);

use App\Actions\Admin\DeleteCategory;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->withoutVite();
    $this->admin = User::factory()->admin()->create();
});

it('deletes an unused category and deactivates one that tickets reference', function (bool $hasTickets, bool $deleted): void {
    $category = Category::factory()->create(['is_active' => true]);
    if ($hasTickets) {
        Ticket::factory()->create(['category_id' => $category->id]);
    }
    expect(app(DeleteCategory::class)->handle($category))->toBe($deleted)
        ->and(Category::query()->whereKey($category->id)->value('is_active'))->toBe($deleted ? null : false);
})->with([
    'unused' => [false, true],
    'referenced' => [true, false],
]);

it('lists categories with ticket counts and renders the edit props', function (): void {
    $used = Category::factory()->create(['name' => 'Billing', 'slug' => 'billing']);
    Category::factory()->create(['name' => 'Access', 'slug' => 'access']);
    Ticket::factory()->count(2)->create(['category_id' => $used->id]);
    $this->actingAs($this->admin)->get(route('admin.categories.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/categories/Index', false)
            ->where('categories.data.0.name', 'Access')
            ->where('categories.data.0.tickets_count', 0)
            ->where('categories.data.1.tickets_count', 2));
    $this->actingAs($this->admin)->get(route('admin.categories.edit', $used))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/categories/Edit', false)
            ->where('category.slug', 'billing'));
});

it('creates a category with a normalised slug', function (): void {
    $this->actingAs($this->admin)
        ->post(route('admin.categories.store'), ['name' => 'Billing', 'slug' => ' Billing '])
        ->assertRedirect(route('admin.categories.index'))
        ->assertSessionHas('success');
    expect(Category::query()->where('slug', 'billing')->exists())->toBeTrue();
});

it('rejects a duplicate or malformed slug', function (string $slug): void {
    Category::factory()->create(['slug' => 'taken']);
    $this->actingAs($this->admin)
        ->post(route('admin.categories.store'), ['name' => 'X', 'slug' => $slug])
        ->assertSessionHasErrors('slug');
})->with(['duplicate' => 'taken', 'spaces' => 'two words', 'trailing dash' => 'bad-']);

it('updates a category and lets it keep its own slug', function (): void {
    $category = Category::factory()->create(['slug' => 'billing']);
    $this->actingAs($this->admin)
        ->patch(route('admin.categories.update', $category), ['name' => 'Billing & invoices', 'slug' => 'billing', 'is_active' => false])
        ->assertRedirect(route('admin.categories.index'))
        ->assertSessionHasNoErrors();
    expect($category->fresh()?->name)->toBe('Billing & invoices')
        ->and($category->fresh()?->is_active)->toBeFalse();
});

it('tells the admin whether a category was deleted or deactivated', function (): void {
    $unused = Category::factory()->create();
    $used = Category::factory()->create();
    Ticket::factory()->create(['category_id' => $used->id]);
    $this->actingAs($this->admin)->delete(route('admin.categories.destroy', $unused))
        ->assertSessionHas('success', 'Category deleted.');
    $this->actingAs($this->admin)->delete(route('admin.categories.destroy', $used))
        ->assertSessionHas('success', 'Category has tickets, so it was deactivated instead of deleted.');
});

it('manages categories over the API', function (): void {
    Sanctum::actingAs($this->admin, ['*']);
    $id = $this->postJson(route('api.v1.admin.categories.store'), ['name' => 'Billing', 'slug' => 'billing'])
        ->assertCreated()
        ->assertJsonPath('data.is_active', true)
        ->json('data.id');
    $this->patchJson(route('api.v1.admin.categories.update', $id), ['name' => 'Billing 2', 'slug' => 'billing-2'])
        ->assertOk()
        ->assertJsonPath('data.slug', 'billing-2');
    $this->getJson(route('api.v1.admin.categories.index'))->assertOk()->assertJsonPath('meta.total', 1);
    $this->deleteJson(route('api.v1.admin.categories.destroy', $id))->assertNoContent();
    $used = Category::factory()->create();
    Ticket::factory()->create(['category_id' => $used->id]);
    $this->deleteJson(route('api.v1.admin.categories.destroy', $used))->assertOk()->assertJsonPath('data.is_active', false);
});

it('lists only active categories on the public endpoint', function (): void {
    Category::factory()->create(['slug' => 'billing', 'is_active' => true]);
    Category::factory()->create(['slug' => 'legacy', 'is_active' => false]);
    Sanctum::actingAs(User::factory()->customer()->create(), ['*']);
    $this->getJson(route('api.v1.categories.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.slug', 'billing');
});
