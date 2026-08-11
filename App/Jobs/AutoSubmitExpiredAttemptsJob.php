<?php

namespace App\Jobs;

use App\Services\AttemptService;
use App\Contracts\AttemptRepositoryInterface;
use App\Core\Logger;

class AutoSubmitExpiredAttemptsJob
{
    private AttemptService $attemptService;
    private AttemptRepositoryInterface $attemptRepo;
    private Logger $logger;

    public function __construct(
        AttemptService $attemptService,
        AttemptRepositoryInterface $attemptRepo,
        Logger $logger
    ) {
        $this->attemptService = $attemptService;
        $this->attemptRepo = $attemptRepo;
        $this->logger = $logger;
    }

    public function run(): void
    {
        $expired = $this->attemptRepo->findExpiredInProgress(200);

        foreach ($expired as $row) {
            try {
                $this->attemptService->autoSubmitAttempt((int) $row['id']);
                $this->logger->info('Swept expired attempt', ['attempt_id' => $row['id']]);
            } catch (\Throwable $e) {
                $this->logger->error('Sweep failed for attempt', [
                    'attempt_id' => $row['id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}