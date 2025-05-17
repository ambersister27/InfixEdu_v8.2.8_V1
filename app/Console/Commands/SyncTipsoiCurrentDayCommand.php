<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\TipsoiAttendanceService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class SyncTipsoiCurrentDayCommand extends Command
{
    protected $signature = 'tipsoi:sync-current-day';
    protected $description = 'Sync attendance data from TIPSOI devices for the entire current day.';

    protected $tipsoiService;

    public function __construct(TipsoiAttendanceService $tipsoiService)
    {
        parent::__construct();
        $this->tipsoiService = $tipsoiService;
    }

    public function handle()
    {
        $this->info('Starting TIPSOI current day attendance sync...');
        Log::info('Command tipsoi:sync-current-day started.');

        $startTime = Carbon::now()->startOfDay(); // Today at 00:00:00
        $endTime = Carbon::now()->endOfDay();     // Today at 23:59:59

        try {
            $result = $this->tipsoiService->syncAttendance($startTime, $endTime);
            $this->info('TIPSOI current day attendance sync completed.');
            $this->info('Synced records: ' . $result['synced'] . ', Students not found: ' . $result['notFoundStudent'] . ', StudentRecords not found: ' . $result['notFoundRecord']);
            Log::info('Command tipsoi:sync-current-day finished. Synced: ' . $result['synced'] . ', NotFoundStudent: ' . $result['notFoundStudent'] . ', NotFoundRecord: ' . $result['notFoundRecord']);

        } catch (\Exception $e) {
            $this->error('An error occurred during TIPSOI current day sync: ' . $e->getMessage());
            Log::error('Error in command tipsoi:sync-current-day: ' . $e->getMessage() . ' Trace: ' . $e->getTraceAsString());
        }
        
        return parent::SUCCESS;
    }
} 