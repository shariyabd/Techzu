<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\TicketPriority;

final readonly class DemoScenario
{
    /**
     * @param  list<string>  $subjects
     * @param  list<string>  $agentLines
     * @param  list<string>  $customerLines
     * @param  list<string>  $notes
     * @param  array<string, string>  $fileNames
     */
    public function __construct(
        public string $category,
        public TicketPriority $priority,
        public array $subjects,
        public string $description,
        public array $agentLines,
        public array $customerLines,
        public array $notes,
        public string $question,
        public string $resolution,
        public array $fileNames = [],
    ) {}

    public function fileName(DemoFileType $type): string
    {
        return $this->fileNames[$type->value] ?? match ($type) {
            DemoFileType::Screenshot => 'screenshot.png',
            DemoFileType::Document => 'details.pdf',
            DemoFileType::Log => 'error-log.txt',
            DemoFileType::Spreadsheet => 'sample.csv',
        };
    }
}
