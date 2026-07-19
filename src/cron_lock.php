<?php

function cron_acquire_lock(string $jobName)
{
    static $locks = [];
    $safeName = trim((string)preg_replace('/[^A-Za-z0-9_.-]+/', '-', $jobName), '-.');
    if ($safeName === '') {
        throw new InvalidArgumentException('Cron lock name cannot be empty.');
    }
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kitgrab-' . $safeName . '.lock';
    $handle = @fopen($path, 'c');
    if ($handle === false) {
        fwrite(STDERR, "Could not open cron lock file: {$path}\n");
        exit(1);
    }
    if (!@flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        fwrite(STDOUT, "Another {$jobName} run is already in progress; this run was skipped.\n");
        exit(0);
    }
    ftruncate($handle, 0);
    fwrite($handle, getmypid() . ' ' . date(DATE_ATOM) . PHP_EOL);
    $locks[$jobName] = $handle;
    register_shutdown_function(static function () use (&$locks): void {
        foreach ($locks as $lock) {
            if (is_resource($lock)) { @flock($lock, LOCK_UN); @fclose($lock); }
        }
    });
    return $handle;
}
