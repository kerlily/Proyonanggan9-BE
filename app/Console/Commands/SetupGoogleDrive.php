<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Google\Client;
use Google\Service\Drive;

class SetupGoogleDrive extends Command
{
    protected $signature = 'backup:setup-google';
    protected $description = 'Setup Google Drive authentication for backups';

    public function handle()
    {
        $this->info('Google Drive Setup for Backups');
        $this->line('');

        $clientId = $this->ask('Enter Google Drive Client ID');
        $clientSecret = $this->ask('Enter Google Drive Client Secret');

        $client = new Client();
        $client->setClientId($clientId);
        $client->setClientSecret($clientSecret);
        $client->setRedirectUri('urn:ietf:wg:oauth:2.0:oob');
        $client->setScopes([Drive::DRIVE_FILE]);
        $client->setAccessType('offline');
        $client->setPrompt('consent');

        $authUrl = $client->createAuthUrl();

        $this->info('Please visit this URL and authorize the application:');
        $this->line($authUrl);
        $this->line('');

        $authCode = $this->ask('Enter the authorization code');

        try {
            $accessToken = $client->fetchAccessTokenWithAuthCode($authCode);

            if (isset($accessToken['error'])) {
                $this->error('Error: ' . $accessToken['error_description']);
                return 1;
            }

            $refreshToken = $accessToken['refresh_token'] ?? null;

            if (!$refreshToken) {
                $this->error('No refresh token received. Please revoke access and try again.');
                return 1;
            }

            $this->info('');
            $this->info('Add these to your .env file:');
            $this->line('');
            $this->line("GOOGLE_DRIVE_CLIENT_ID={$clientId}");
            $this->line("GOOGLE_DRIVE_CLIENT_SECRET={$clientSecret}");
            $this->line("GOOGLE_DRIVE_REFRESH_TOKEN={$refreshToken}");
            $this->line("GOOGLE_DRIVE_FOLDER=LMS-Backups");
            $this->line('');
            $this->info('Setup completed successfully!');

            return 0;
        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            return 1;
        }
    }
}
