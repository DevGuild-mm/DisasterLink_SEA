<?php

namespace App\Console\Commands;

use App\Mail\TestMail;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Throwable;

#[Signature('mail:test {to? : Recipient address (default: the configured MAIL_FROM_ADDRESS)} {--mailer= : Mailer to use (default: MAIL_MAILER), for example smtp or log} {--host= : SMTP host for this run only} {--port= : SMTP port for this run only}')]
#[Description('Send a test email to check the mail setup, for example Mailpit (local SMTP, web inbox on port 8025)')]
class SendTestMail extends Command
{
    /**
     * Sends the alert email design with sample data (nothing is saved or queued), at once so a failure shows here. `--host` and `--port` apply to this run only;
     * nothing is written to `.env`. Passwords are never printed.
     */
    public function handle(): int
    {
        $to = (string) ($this->argument('to') ?: config('mail.from.address'));

        if (Validator::make(['to' => $to], ['to' => ['required', 'email']])->fails()) {
            $this->components->error("'{$to}' is not a valid email address.");

            return self::FAILURE;
        }

        $mailer = (string) ($this->option('mailer') ?: config('mail.default'));

        if (! is_array(config("mail.mailers.{$mailer}"))) {
            $this->components->error("Mailer '{$mailer}' is not defined in config/mail.php.");

            return self::FAILURE;
        }

        foreach (['host', 'port'] as $option) {
            if ($this->option($option) !== null && $this->option($option) !== '') {
                config(["mail.mailers.{$mailer}.{$option}" => $option === 'port' ? (int) $this->option($option) : (string) $this->option($option)]);
            }
        }

        Mail::purge($mailer);

        $this->describeTarget($mailer, $to);
        $this->line('The message is the real alert email filled with sample data. Send it only to addresses you control.');

        try {
            Mail::mailer($mailer)->to($to)->send(new TestMail);
        } catch (Throwable $e) {
            $this->components->error('The test email could not be sent: '.$e->getMessage());

            if (config("mail.mailers.{$mailer}.transport") === 'smtp') {
                $this->line('Is Mailpit running? Start it, then try: php artisan mail:test '.$to.' --mailer=smtp --host=127.0.0.1 --port=1025');
            }

            return self::FAILURE;
        }

        $this->components->info("Test email handed to the '{$mailer}' mailer for {$to}.");

        if (config("mail.mailers.{$mailer}.transport") === 'log') {
            $this->line('The mailer is "log": the message was written to storage/logs, not delivered. Use --mailer=smtp for Mailpit.');
        } elseif (config("mail.mailers.{$mailer}.transport") === 'smtp') {
            $this->line('Check the inbox: Mailpit shows it at http://localhost:8025 (default).');
        }

        return self::SUCCESS;
    }

    private function describeTarget(string $mailer, string $to): void
    {
        $config = (array) config("mail.mailers.{$mailer}");
        $transport = (string) ($config['transport'] ?? '');

        $this->line("Mailer: {$mailer} ({$transport})");

        if ($transport === 'smtp') {
            $this->line('SMTP: '.($config['host'] ?? '?').':'.($config['port'] ?? '?'));
        }

        $this->line('From: '.config('mail.from.address').'   To: '.$to);
    }
}
