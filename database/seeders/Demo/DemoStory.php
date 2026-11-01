<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;

final class DemoStory
{
    public ?float $daysAgo = null;

    public ?float $elapsedFraction = null;

    public ?TicketPriority $priority = null;

    public ?string $creator = null;

    public ?string $category = null;

    public ?DemoFileType $file = null;

    /** @var list<DemoStep> */
    public array $steps = [];

    public ?Ticket $ticket = null;

    /** @var array<string, string> */
    public array $tokens = [];

    public int $variant = 0;

    public int $agentLines = 0;

    public int $customerLines = 0;

    public int $notes = 0;

    private bool $agentSpeaksNext = true;

    public function __construct(
        public readonly string $scenario,
        public readonly string $customer,
    ) {}

    public static function of(string $scenario, string $customer): self
    {
        return new self($scenario, $customer);
    }

    public function daysAgo(float $days): self
    {
        $this->daysAgo = $days;

        return $this;
    }

    public function elapsed(float $fractionOfSla): self
    {
        $this->elapsedFraction = $fractionOfSla;

        return $this;
    }

    public function priority(TicketPriority $priority): self
    {
        $this->priority = $priority;

        return $this;
    }

    public function onBehalfBy(string $staff): self
    {
        $this->creator = $staff;

        return $this;
    }

    public function filedUnder(string $categorySlug): self
    {
        $this->category = $categorySlug;

        return $this;
    }

    public function file(DemoFileType $file): self
    {
        $this->file = $file;

        return $this;
    }

    public function auto(int $after = 2): self
    {
        return $this->step(new DemoStep(DemoStepType::AutoAssign, $after));
    }

    public function claim(string $agent, int $after): self
    {
        return $this->step(new DemoStep(DemoStepType::Claim, $after, who: $agent));
    }

    public function assign(string $agent, int $after, bool $outsideCategory = false): self
    {
        return $this->step(new DemoStep(DemoStepType::Assign, $after, who: $agent, outsideCategory: $outsideCategory));
    }

    public function handoff(string $agent, int $after): self
    {
        return $this->step(new DemoStep(DemoStepType::Handoff, $after, who: $agent));
    }

    public function agent(int $after, ?DemoFileType $file = null, DemoLine $line = DemoLine::Conversation): self
    {
        $this->agentSpeaksNext = false;

        return $this->step(new DemoStep(DemoStepType::AgentReply, $after, file: $file, line: $line));
    }

    public function customer(int $after, ?DemoFileType $file = null, DemoLine $line = DemoLine::Conversation): self
    {
        $this->agentSpeaksNext = true;

        return $this->step(new DemoStep(DemoStepType::CustomerReply, $after, file: $file, line: $line));
    }

    public function note(int $after, ?DemoFileType $file = null, ?string $author = null): self
    {
        return $this->step(new DemoStep(DemoStepType::InternalNote, $after, who: $author, file: $file));
    }

    public function talk(int $messages, int $gapMinutes): self
    {
        for ($i = 0; $i < $messages; $i++) {
            $after = $gapMinutes + ($i * 17) % 45;
            $this->agentSpeaksNext ? $this->agent($after) : $this->customer($after);
        }

        return $this;
    }

    public function status(TicketStatus $status, int $after, ?string $actor = null): self
    {
        return $this->step(new DemoStep(DemoStepType::Status, $after, who: $actor, status: $status));
    }

    public function askForDetails(int $after): self
    {
        return $this->agent($after, line: DemoLine::AskForDetails)->status(TicketStatus::WaitingForCustomer, 2);
    }

    public function resolve(int $after): self
    {
        return $this->agent($after, line: DemoLine::Resolution)->status(TicketStatus::Resolved, 3);
    }

    public function close(int $after, ?string $by = 'customer'): self
    {
        return $this->status(TicketStatus::Closed, $after, $by);
    }

    public function thank(int $after): self
    {
        return $this->customer($after, line: DemoLine::Thanks);
    }

    public function reopen(int $after, ?DemoFileType $file = null): self
    {
        return $this->customer($after, $file, DemoLine::Reopen);
    }

    public function bump(TicketPriority $to, int $after, ?string $actor = null): self
    {
        return $this->step(new DemoStep(DemoStepType::Priority, $after, who: $actor, priority: $to));
    }

    public function move(string $categorySlug, int $after): self
    {
        return $this->step(new DemoStep(DemoStepType::Category, $after, who: 'admin', category: $categorySlug));
    }

    private function step(DemoStep $step): self
    {
        $this->steps[] = $step;

        return $this;
    }
}
