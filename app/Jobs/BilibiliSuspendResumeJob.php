<?php

namespace App\Jobs;

use App\Services\BilibiliSuspendService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * 412 风控结束后的恢复 Job，跑在 default 队列（由 supervisor-default 执行，不受限流 supervisor 暂停影响）
 * 通过 HorizonCommandQueue 向限流 supervisor 推送 ContinueWorking 指令，使其恢复拉取 Job
 */
class BilibiliSuspendResumeJob implements ShouldQueue
{
    use Queueable;

    // Local patch: $queue is already declared by the Queueable trait.
    // Redeclaring it here is a property-composition fatal that kills the worker.
    public $tries = 3;
    public $backoff = [60, 120];

    public function __construct()
    {
    }

    public function handle(): void
    {
        app(BilibiliSuspendService::class)->continueRateLimitSupervisor();
        Log::info('Bilibili suspend period ended, sent ContinueWorking to rate-limit supervisor');
    }
}
