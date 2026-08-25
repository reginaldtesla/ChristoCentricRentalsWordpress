<?php
class Rental_Timer {
    protected static ?float $start_time = null;
    protected static ?float $end_time   = null;

    public static function start(): void {
        self::$start_time = microtime(true);
        self::$end_time   = null; // reset previous stop
    }

    public static function stop(): void {
        self::$end_time = microtime(true);
    }

    public static function show_duration(): string {
        try {
            if (self::$start_time === null || self::$end_time === null) {
                // not started or not stopped yet
                return '00h:00m:00s';
            }

            $duration = self::$end_time - self::$start_time;

            $seconds = (int) floor(fmod($duration, 60));
            $minutes = (int) floor(fmod($duration / 60, 60));
            $hours   = (int) floor($duration / 3600);

            return sprintf('%02dh:%02dm:%02ds', $hours, $minutes, $seconds);
        } catch (RentalException $e) {
            ErrorHandler::registerErrorInLog($e->getMessage(), $e->getFile(), $e->getLine(), $e->getType(), time(), $e->getStatusCode());

            return '00h:00m:00s';
        }
    }

    public static function reset(): void {
        self::$start_time = null;
        self::$end_time   = null;
    }

    public static function start_persistent(string $key = 'rental_sync_start_time'): void {
        $time = microtime(true);
        update_option($key, $time);
        self::$start_time = $time;
        self::$end_time   = null;
    }

    public static function stop_persistent(string $key = 'rental_sync_start_time'): string {
        try {
            // cast back to float to avoid string/float mix
            $stored = get_option($key);
            if ($stored === false) {
                return '00h:00m:00s';
            }
            self::$start_time = (float) $stored;
            self::$end_time   = microtime(true);

            return self::show_duration();
        } catch (RentalException $e) {
            ErrorHandler::registerErrorInLog($e->getMessage(), $e->getFile(), $e->getLine(), $e->getType(), time(), $e->getStatusCode());

            return '00h:00m:00s';
        }
    }
}
