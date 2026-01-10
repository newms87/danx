<?php

namespace Newms87\Danx\Services\Debug\Concerns;

use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Provides common output formatting utilities for debug services.
 *
 * Extracts reusable patterns for truncating content, indenting multiline
 * output, formatting durations and timestamps, and displaying headers.
 */
trait DebugOutputHelper
{
    /**
     * Truncate content with a suffix indicator.
     */
    protected function truncate(string $content, int $maxLength = 1000, string $suffix = '... [truncated]'): string
    {
        if (strlen($content) <= $maxLength) {
            return $content;
        }

        return substr($content, 0, $maxLength) . $suffix;
    }

    /**
     * Indent multiline content by prepending spaces to each line.
     */
    protected function indentContent(string $content, int $spaces = 4): string
    {
        $indent = str_repeat(' ', $spaces);

        return $indent . str_replace("\n", "\n" . $indent, $content);
    }

    /**
     * Format and display JSON content with optional truncation and indentation.
     */
    protected function showJsonContent(
        mixed $data,
        Command $command,
        int $maxLength = 1000,
        int $indent = 4
    ): void {
        $jsonContent = json_encode($data, JSON_PRETTY_PRINT);
        $truncated   = $this->truncate($jsonContent, $maxLength);
        $indented    = $this->indentContent($truncated, $indent);
        $command->line($indented);
    }

    /**
     * Format milliseconds to human-readable duration.
     *
     * Examples: "450ms", "1.2s", "2m 30s", "1h 5m"
     */
    protected function formatDuration(int $ms): string
    {
        if ($ms < 1000) {
            return $ms . 'ms';
        }

        $seconds = $ms / 1000;

        if ($seconds < 60) {
            return round($seconds, 1) . 's';
        }

        $minutes          = floor($seconds / 60);
        $remainingSeconds = (int)($seconds % 60);

        if ($minutes < 60) {
            return $remainingSeconds > 0
                ? "{$minutes}m {$remainingSeconds}s"
                : "{$minutes}m";
        }

        $hours            = floor($minutes / 60);
        $remainingMinutes = (int)($minutes % 60);

        return $remainingMinutes > 0
            ? "{$hours}h {$remainingMinutes}m"
            : "{$hours}h";
    }

    /**
     * Format timestamp for display. Accepts Carbon, string, or null.
     */
    protected function formatTimestamp(Carbon|string|null $timestamp): string
    {
        if ($timestamp === null) {
            return '-';
        }

        if (is_string($timestamp)) {
            return $timestamp;
        }

        return $timestamp->format('Y-m-d H:i:s');
    }

    /**
     * Display a major section header.
     */
    protected function showHeader(string $title, Command $command): void
    {
        $command->info("=== {$title} ===");
    }

    /**
     * Display a subsection header.
     */
    protected function showSubHeader(string $title, Command $command): void
    {
        $command->line("--- {$title} ---");
    }

    /**
     * Colorize HTTP status code for terminal output.
     *
     * 2xx = green, 3xx = yellow, 4xx/5xx = red, 0 = gray
     */
    protected function colorizeStatus(int $status): string
    {
        return match (true) {
            $status >= 200 && $status < 300 => "<fg=green>{$status}</>",
            $status >= 300 && $status < 400 => "<fg=yellow>{$status}</>",
            $status >= 400                  => "<fg=red>{$status}</>",
            default                         => "<fg=gray>{$status}</>",
        };
    }

    /**
     * Colorize job status for terminal output.
     *
     * Complete=green, Running=blue, Pending=yellow, Failed/Exception/Timeout=red, Aborted=gray
     */
    protected function colorizeJobStatus(string $status): string
    {
        $statusLower = strtolower($status);

        return match ($statusLower) {
            'complete', 'completed' => "<fg=green>{$status}</>",
            'running'               => "<fg=blue>{$status}</>",
            'pending', 'queued'     => "<fg=yellow>{$status}</>",
            'failed', 'exception', 'timeout' => "<fg=red>{$status}</>",
            'aborted', 'cancelled'  => "<fg=gray>{$status}</>",
            default                 => $status,
        };
    }
}
