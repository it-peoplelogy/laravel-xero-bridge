<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Commands;

use Illuminate\Console\Command;
use Peoplelogy\XeroBridge\Support\XeroConfig;

class InstallCommand extends Command
{
    protected $signature = 'xero-bridge:install {--force : Overwrite files that already exist}';

    protected $description = 'Publish the Xero Bridge config and migration, and print the required .env keys';

    public function handle(XeroConfig $config): int
    {
        $this->components->info('Installing Xero Bridge.');

        $this->callSilently('vendor:publish', array_filter([
            '--tag' => 'xero-bridge-config',
            '--force' => $this->option('force') ?: null,
        ]));
        $this->components->task('Published config/xero-bridge.php');

        $this->callSilently('vendor:publish', array_filter([
            '--tag' => 'xero-bridge-migrations',
            '--force' => $this->option('force') ?: null,
        ]));
        $this->components->task('Published the xero_connections migration');

        $this->newLine();
        $this->line('Add these to your .env file:');
        $this->newLine();

        foreach ($this->envKeys() as $key => $note) {
            $this->line(sprintf('  <fg=yellow>%-28s</> %s', $key.'=', $note));
        }

        $this->newLine();
        $this->line('Then:');
        $this->line('  1. php artisan migrate');
        $this->line('  2. Create an app at <fg=cyan>https://developer.xero.com/myapps</>');
        $this->line('  3. Register this redirect URI on it, exactly:');
        $this->line('     <fg=cyan>'.$this->redirectUri().'</>');
        $this->line('  4. Visit <fg=cyan>'.$this->connectUrl($config).'</> to connect an organisation');

        $webhookUrl = url((string) $config->get('routes.prefix', 'xero')
            .'/'.(string) $config->get('webhooks.path', 'webhook'));

        $this->newLine();
        $this->line('Webhook URL (paste into the Xero app\'s Webhooks tab):');
        $this->line('  <fg=cyan>'.$webhookUrl.'</>');

        if (! str_starts_with($webhookUrl, 'https://')) {
            $this->newLine();
            $this->components->warn(
                'Xero only delivers webhooks to https on port 443, so this URL will not work as-is. '
                .'Set APP_URL to your public https address.'
            );
        }

        $this->newLine();
        $this->components->warn(
            'Schedule xero-bridge:refresh-tokens with ->withoutOverlapping()->onOneServer(). '
            .'Xero rotates refresh tokens, so two concurrent refreshes invalidate each other.'
        );

        return self::SUCCESS;
    }

    /** @return array<string, string> */
    private function envKeys(): array
    {
        return [
            'XERO_CLIENT_ID' => 'required - from your Xero app',
            'XERO_CLIENT_SECRET' => 'required - shown once, at creation',
            'XERO_REDIRECT_URI' => 'required - must match the app exactly',
            'XERO_WEBHOOK_KEY' => 'optional - only if you use webhooks',
            'XERO_SCOPES' => 'optional - must include offline_access',
            'XERO_ACCOUNT_CODE' => 'required to invoice - differs per organisation',
            'XERO_TAX_TYPE' => 'optional - leave unset for per-line tax',
            'XERO_CURRENCY' => 'optional - defaults to MYR',
            'XERO_LOCK_STORE' => 'recommended - redis/memcached/database',
        ];
    }

    private function redirectUri(): string
    {
        try {
            return app(XeroConfig::class)->redirectUri();
        } catch (\Throwable) {
            return url('xero/callback');
        }
    }

    private function connectUrl(XeroConfig $config): string
    {
        return url($config->connectUrl($config->defaultConnection()));
    }
}
