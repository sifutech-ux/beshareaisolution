<?php
declare(strict_types=1);

namespace BesharOs;

final class RateLimit
{
    public function __construct(
        private string $directory,
        private int $maxAttempts = 5,
        private int $windowSeconds = 900
    ) {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new AuthException('Had percubaan tidak dapat disediakan.', 500);
        }
        if (!chmod($this->directory, 0700)) {
            throw new AuthException('Had percubaan tidak dapat disediakan.', 500);
        }
    }

    public function tooMany(string $key): bool
    {
        return count($this->fresh($key)) >= $this->maxAttempts;
    }

    public function hit(string $key): void
    {
        $events = $this->fresh($key);
        $events[] = time();
        $this->write($key, $events);
    }

    public function clear(string $key): void
    {
        $path = $this->path($key);
        if (is_file($path)) {
            unlink($path);
        }
    }

    /** @return list<int> */
    private function fresh(string $key): array
    {
        $path = $this->path($key);
        if (!is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            return [];
        }
        $cutoff = time() - $this->windowSeconds;
        $events = [];
        foreach ($decoded as $event) {
            if (is_int($event) && $event >= $cutoff) {
                $events[] = $event;
            }
        }
        return $events;
    }

    /** @param list<int> $events */
    private function write(string $key, array $events): void
    {
        $path = $this->path($key);
        file_put_contents($path, json_encode(array_values($events)), LOCK_EX);
        chmod($path, 0600);
    }

    private function path(string $key): string
    {
        return $this->directory . '/' . hash('sha256', $key) . '.json';
    }
}
