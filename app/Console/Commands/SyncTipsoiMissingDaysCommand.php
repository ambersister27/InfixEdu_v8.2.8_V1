<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\TipsoiAttendanceService;
use App\SmStudentAttendance;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class SyncTipsoiMissingDaysCommand extends Command
{
    protected $signature = 'tipsoi:sync-missing-days';
    protected $description = 'Sync TIPSOI attendance for past days (up to 1 month) if no local records exist for those days.';

    protected $tipsoiService;

    public function __construct(TipsoiAttendanceService $tipsoiService)
    {
        parent::__construct();
        $this->tipsoiService = $tipsoiService;
    }

    public function handle()
    {
        $this->info('Starting TIPSOI missing days attendance sync...');
        Log::info('Command tipsoi:sync-missing-days started.');

        $today = Carbon::today();
        $maxDaysToLookBack = 30;

        for ($i = 1; $i <= $maxDaysToLookBack; $i++) {
            $dateToSync = $today->copy()->subDays($i);
            $this->info("Checking for missing attendance on: " . $dateToSync->toDateString());
            Log::info("TIPSOI Missing Days: Checking for date: " . $dateToSync->toDateString());

            // Check if any attendance exists for this day for any student for the current school/academic year
            // This check might need to be more specific if you only want to sync for a particular school/academic year context from command
            $existingAttendanceCount = SmStudentAttendance::where('attendance_date', $dateToSync->toDateString())
                                        // ->where('school_id', generalSetting()->school_id) // Potentially add if generalSetting has school_id
                                        // ->where('academic_id', generalSetting()->session_id) // Potentially add
                                        ->count();

            if ($existingAttendanceCount === 0) {
                $this->info("No local attendance found for " . $dateToSync->toDateString() . ". Attempting to sync from TIPSOI.");
                Log::info("TIPSOI Missing Days: No local records for " . $dateToSync->toDateString() . ". Fetching from API.");

                $startTime = $dateToSync->copy()->startOfDay();
                $endTime = $dateToSync->copy()->endOfDay();

                try {
                    $result = $this->tipsoiService->syncAttendance($startTime, $endTime);
                    $this->info('Sync for ' . $dateToSync->toDateString() . ' completed. Synced: ' . $result['synced'] . ', NotFoundStudent: ' . $result['notFoundStudent'] . ', NotFoundRecord: ' . $result['notFoundRecord']);
                    Log::info('Command tipsoi:sync-missing-days for date ' . $dateToSync->toDateString() . ' finished. Synced: ' . $result['synced'] . ', NotFoundStudent: ' . $result['notFoundStudent'] . ', NotFoundRecord: ' . $result['notFoundRecord']);
                } catch (\Exception $e) {
                    $this->error('An error occurred during TIPSOI sync for date ' . $dateToSync->toDateString() . ': ' . $e->getMessage());
                    Log::error('Error in command tipsoi:sync-missing-days for date ' . $dateToSync->toDateString() . ': ' . $e->getMessage() . ' Trace: ' . $e->getTraceAsString());
                }
            } else {
                $this->info("Local attendance already exists for " . $dateToSync->toDateString() . " (". $existingAttendanceCount ." records). Skipping API sync for this day.");
                Log::info("TIPSOI Missing Days: Local records found for " . $dateToSync->toDateString() . ". Skipping.");
            }
            $this->info("---"); // Separator for days
        }

        $this->info('TIPSOI missing days attendance sync finished.');
        Log::info('Command tipsoi:sync-missing-days completed.');
        return parent::SUCCESS;
    }
} 