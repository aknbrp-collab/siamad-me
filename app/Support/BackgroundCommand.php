<?php

namespace App\Support;

use LogicException;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * Starts an artisan command in its own process and returns at once (data-manage D50, D51): a backup or a
 * restore can take minutes, longer than a web request may run, and must not end with the request. Works
 * the same under php-fpm, Herd and `artisan serve`. Tests swap it for a fake in the container.
 */
class BackgroundCommand
{
    /**
     * @param  list<string>  $arguments  e.g. ['--by=5']
     */
    public function start(string $command, array $arguments = []): void
    {
        // A real process started by a test would inherit the test database settings and write into the real
        // backups folder: tests must swap this class for a fake.
        if (app()->runningUnitTests()) {
            throw new LogicException("Tests must fake BackgroundCommand (tried to start {$command}).");
        }

        $line = implode(' ', array_map('escapeshellarg', [$this->php(), base_path('artisan'), $command, ...$arguments]));

        if (PHP_OS_FAMILY === 'Windows') {
            // "start /B" runs it without a window and without waiting for it.
            $handle = popen('start "" /B '.$line.' > NUL 2>&1', 'r');
            if ($handle !== false) {
                pclose($handle);
            }

            return;
        }

        exec('nohup '.$line.' > /dev/null 2>&1 &');
    }

    /**
     * The PHP command line program (not php-fpm / php-cgi, which serve the web request).
     */
    private function php(): string
    {
        return (new PhpExecutableFinder)->find(false) ?: 'php';
    }
}
