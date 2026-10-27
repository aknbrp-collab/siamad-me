<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

#[Signature('siakad:vapid {--force : Print a new pair even though keys are already configured}')]
#[Description('Generate the VAPID key pair for Web Push; put the printed lines in .env once')]
class VapidCommand extends Command
{
    public function handle(): int
    {
        if (filled(config('siakad.push.public_key')) && ! $this->option('force')) {
            $this->components->warn('VAPID keys are already set. A new pair stops pop-ups on every device that turned them on; use --force if that is really wanted.');

            return self::FAILURE;
        }

        $keys = VAPID::createVapidKeys();

        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->line('VAPID_SUBJECT=mailto:admin@example.com');
        $this->newLine();
        $this->components->info('Add these lines to .env (set VAPID_SUBJECT to a real address), then run php artisan config:cache.');

        return self::SUCCESS;
    }
}
