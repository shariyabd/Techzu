<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Actions\Assignment\AssignTicket;
use App\Actions\Assignment\AssignTicketAutomatically;
use App\Actions\Assignment\ClaimTicket;
use App\Actions\Conversation\AddReply;
use App\Actions\Tickets\ChangeTicketPriority;
use App\Actions\Tickets\ChangeTicketStatus;
use App\Actions\Tickets\CreateTicket;
use App\Actions\Tickets\UpdateTicket;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use LogicException;

final class DemoDirector
{
    private const string PHONE_INTRO = "Logged by {agent} after a phone call with {first_name}.\n\n";

    private const string SIGNATURE = "\n\nBest regards,\n{agent}";

    public function __construct(
        private readonly DemoCast $cast,
        private readonly DemoFiles $files,
        private readonly CreateTicket $createTicket,
        private readonly AddReply $addReply,
        private readonly ChangeTicketStatus $changeStatus,
        private readonly ChangeTicketPriority $changePriority,
        private readonly UpdateTicket $updateTicket,
        private readonly AssignTicket $assignTicket,
        private readonly ClaimTicket $claimTicket,
        private readonly AssignTicketAutomatically $assignAutomatically,
    ) {}

    public function create(DemoStory $story): void
    {
        $scenario = DemoScenarios::get($story->scenario);
        $customer = $this->cast->user($story->customer);
        $actor = $story->creator === null ? $customer : $this->cast->user($story->creator);
        $subject = $this->render($story, $scenario->subjects[$story->variant % count($scenario->subjects)], $actor);
        $description = $this->render($story, ($story->creator === null ? '' : self::PHONE_INTRO).$scenario->description, $actor);

        $story->tokens['{subject}'] = $subject;

        $story->ticket = $this->createTicket->handle($actor, [
            'subject' => $subject,
            'description' => $description,
            'priority' => ($story->priority ?? $scenario->priority)->value,
            'category_id' => $this->cast->category($story->category ?? $scenario->category)->id,
            'customer_id' => $customer->id,
        ], $this->attachments($story, $story->file, $actor));
    }

    public function perform(DemoStory $story, DemoStep $step): void
    {
        $ticket = $this->ticket($story);

        match ($step->type) {
            DemoStepType::AutoAssign => $this->assignAutomatically->handle($ticket),
            DemoStepType::Claim => $this->claimTicket->handle($this->cast->user((string) $step->who), $ticket),
            DemoStepType::Assign => $this->assignTicket->handle($this->cast->admin(), $ticket, $this->cast->user((string) $step->who), $step->outsideCategory),
            DemoStepType::Handoff => $this->assignTicket->handle($this->handler($ticket), $ticket, $this->cast->user((string) $step->who)),
            DemoStepType::AgentReply => $this->reply($story, $ticket, $this->handler($ticket), $step, false),
            DemoStepType::CustomerReply => $this->reply($story, $ticket, $this->cast->userById($ticket->customer_id), $step, false),
            DemoStepType::InternalNote => $this->reply($story, $ticket, $this->actor($ticket, $step->who), $step, true),
            DemoStepType::Status => $this->changeStatus->handle($this->actor($ticket, $step->who), $ticket, $step->status ?? throw new LogicException('A status step needs a status.')),
            DemoStepType::Priority => $this->changePriority->handle($this->actor($ticket, $step->who), $ticket, $step->priority ?? throw new LogicException('A priority step needs a priority.')),
            DemoStepType::Category => $this->updateTicket->handle($this->cast->admin(), $ticket, ['category_id' => $this->cast->category((string) $step->category)->id]),
        };
    }

    private function reply(DemoStory $story, Ticket $ticket, User $author, DemoStep $step, bool $internal): void
    {
        $body = $this->render($story, $this->line($story, $step, $internal, $author), $author);

        $this->addReply->handle($author, $ticket, $body, $internal, $this->attachments($story, $step->file, $author));
    }

    private function line(DemoStory $story, DemoStep $step, bool $internal, User $author): string
    {
        $scenario = DemoScenarios::get($story->scenario);
        $isStaff = $author->id !== $story->ticket?->customer_id;

        if ($internal) {
            return self::pick($scenario->notes, DemoScenarios::GENERIC_NOTES, $story->notes++);
        }

        return match ($step->line) {
            DemoLine::AskForDetails => $scenario->question.self::SIGNATURE,
            DemoLine::Resolution => $scenario->resolution.self::SIGNATURE,
            DemoLine::Thanks => DemoScenarios::THANKS_LINES[($story->variant + $story->customerLines++) % count(DemoScenarios::THANKS_LINES)],
            DemoLine::Reopen => DemoScenarios::REOPEN_LINES[($story->variant + $story->customerLines++) % count(DemoScenarios::REOPEN_LINES)],
            DemoLine::Conversation => $isStaff
                ? self::pick($scenario->agentLines, DemoScenarios::GENERIC_AGENT_LINES, $story->agentLines++).self::SIGNATURE
                : self::pick($scenario->customerLines, DemoScenarios::GENERIC_CUSTOMER_LINES, $story->customerLines++),
        };
    }

    /** @return list<UploadedFile> */
    private function attachments(DemoStory $story, ?DemoFileType $type, User $author): array
    {
        if ($type === null) {
            return [];
        }

        $scenario = DemoScenarios::get($story->scenario);
        $caption = $story->tokens['{subject}'] ?? $this->render($story, $scenario->subjects[0], $author);

        return [$this->files->make($type, $this->render($story, $scenario->fileName($type), $author), $caption)];
    }

    private function ticket(DemoStory $story): Ticket
    {
        $id = $story->ticket->id ?? throw new LogicException('The demo ticket has not been created yet.');

        return $story->ticket = Ticket::query()->findOrFail($id);
    }

    private function handler(Ticket $ticket): User
    {
        return $ticket->assigned_agent_id === null ? $this->cast->admin() : $this->cast->userById($ticket->assigned_agent_id);
    }

    private function actor(Ticket $ticket, ?string $who): User
    {
        return match ($who) {
            null => $this->handler($ticket),
            'customer' => $this->cast->userById($ticket->customer_id),
            default => $this->cast->user($who),
        };
    }

    private function render(DemoStory $story, string $text, User $author): string
    {
        $customer = $this->cast->user($story->customer);

        return strtr($text, [
            ...$story->tokens,
            '{first_name}' => DemoCast::firstName($customer),
            '{company}' => $this->cast->company($story->customer),
            '{agent}' => DemoCast::firstName($author),
        ]);
    }

    /**
     * @param  list<string>  $specific
     * @param  list<string>  $generic
     */
    private static function pick(array $specific, array $generic, int $index): string
    {
        return $specific[$index] ?? $generic[($index - count($specific)) % count($generic)];
    }
}
