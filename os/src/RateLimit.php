<?php
declare(strict_types=1);

namespace BesharOs;

final class RateLimit
{
    public static function resolveDirectory(): string
    {
        $override = getenv('BESHARE_OS_RATE_DIR');
        if (is_string($override) && $override !== '') {
            if (self::isExposedPath($override) || !self::canUse($override)) {
                throw new AuthException('Had percubaan tidak dapat disediakan.', 500);
            }
            return $override;
        }
        $path = dirname(__DIR__, 3) . '/private/rate';
        $real = realpath($path);
        if ($real === false || !is_dir($real) || !is_writable($real) || self::isExposedPath($real)) {
            throw new AuthException('Had percubaan tidak dapat disediakan.', 500);
        }
        return $real;
    }

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

    private static function canUse(string $path): bool
    {
        if (is_dir($path)) {
            return is_writable($path);
        }
        $parent = realpath(dirname($path));
        return $parent !== false && is_dir($parent) && is_writable($parent) && !self::isExposedPath($parent);
    }

    private static function isExposedPath(string $path): bool
    {
        $real = realpath($path);
        $check = $real === false ? $path : $real;
        $repo = realpath(dirname(__DIR__, 2));
        if ($repo !== false) {
            $prefix = rtrim($repo, '/') . '/';
            if ($check === $repo || str_starts_with($check, $prefix)) {
                return true;
            }
        }
        return preg_match('#(?:^|/)public_html(?:/|$)#', $check) === 1;
    }
}
