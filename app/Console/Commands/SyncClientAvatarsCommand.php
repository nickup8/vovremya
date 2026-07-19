<?php

namespace App\Console\Commands;

use App\Models\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SyncClientAvatarsCommand extends Command
{
    protected $signature = 'clients:sync-avatars';

    protected $description = 'Download Telegram avatars for existing clients who have telegram_id but no avatar_url';

    public function handle(): int
    {
        $token = config('services.telegram.bot_token');

        if (empty($token)) {
            $this->error('Telegram bot token not configured.');

            return self::FAILURE;
        }

        $clients = Client::whereNotNull('telegram_id')
            ->whereNull('avatar_url')
            ->get();

        $this->info("Found {$clients->count()} clients without avatar.");

        $synced = 0;
        $skipped = 0;

        foreach ($clients as $client) {
            try {
                $photosResponse = Http::timeout(10)->get("https://api.telegram.org/bot{$token}/getUserProfilePhotos", [
                    'user_id' => $client->telegram_id,
                    'limit' => 1,
                ]);

                if (! $photosResponse->ok() || $photosResponse->json('result.total_count', 0) === 0) {
                    $skipped++;
                    continue;
                }

                $photosArray = $photosResponse->json('result.photos');
                $photos = $photosArray[0] ?? [];

                if (empty($photos)) {
                    $skipped++;
                    continue;
                }

                $fileId = $photos[array_key_last($photos)]['file_id'];

                $fileResponse = Http::timeout(10)->get("https://api.telegram.org/bot{$token}/getFile", [
                    'file_id' => $fileId,
                ]);

                if (! $fileResponse->ok()) {
                    $skipped++;
                    continue;
                }

                $filePath = $fileResponse->json('result.file_path');

                $downloadResponse = Http::timeout(15)
                    ->get("https://api.telegram.org/file/bot{$token}/{$filePath}");

                if ($downloadResponse->failed()) {
                    Log::warning('clients:sync-avatars: file download failed', [
                        'client_id' => $client->id,
                        'status' => $downloadResponse->status(),
                    ]);
                    $skipped++;
                    continue;
                }

                $content = $downloadResponse->body();

                if (empty($content)) {
                    $skipped++;
                    continue;
                }

                $filename = "tg_avatar_client_{$client->telegram_id}_" . time() . '.jpg';
                Storage::disk('public')->put("avatars/clients/{$filename}", $content);

                $client->update(['avatar_url' => "/storage/avatars/clients/{$filename}"]);

                $synced++;

                $this->line("  <info>✓</info> {$client->name} ({$client->telegram_id})");

                // Telegram API rate limit: max 30 requests per second
                usleep(100000); // 100ms delay between requests
            } catch (\Throwable $e) {
                Log::warning('clients:sync-avatars: failed', [
                    'client_id' => $client->id,
                    'error' => $e->getMessage(),
                ]);
                $skipped++;
            }
        }

        $this->info("Done: {$synced} synced, {$skipped} skipped.");

        return self::SUCCESS;
    }
}
