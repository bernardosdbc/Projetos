<?php

namespace App\Jobs\Handlers;

use RuntimeException;

class HandlerRegistry
{
    public function for(string $type): JobHandlerInterface
    {
        return match ($type) {
            'send_email' => app(SendEmailHandler::class),
            'generate_report' => app(GenerateReportHandler::class),
            default => throw new RuntimeException("Unknown job type: {$type}"),
        };
    }
}