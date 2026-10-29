<?php

declare(strict_types=1);

use App\Actions\Conversation\AddReply;
use App\Enums\TicketStatus;
use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('streams an attachment as a download with nosniff', function (): void {
    Storage::fake(config('attachments.disk'));
    $customer = User::factory()->customer()->create();
    $agent = User::factory()->agent()->create();
    $ticket = Ticket::factory()->status(TicketStatus::InProgress)->assignedTo($agent)->create([
        'customer_id' => $customer->id,
        'created_by_id' => $customer->id,
    ]);
    $reply = app(AddReply::class)->handle($agent, $ticket, 'Public', false, [
        UploadedFile::fake()->create('report.pdf', 4, 'application/pdf'),
    ]);
    $attachment = Attachment::query()->where('reply_id', $reply->id)->sole();

    $response = $this->actingAs($customer)->get(route('attachments.download', $attachment));

    $response->assertOk()
        ->assertDownload('report.pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment');
});
