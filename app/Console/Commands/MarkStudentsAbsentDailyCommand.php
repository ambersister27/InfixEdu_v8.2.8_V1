<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\StudentRecord;
use App\SmStudentAttendance;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth; // If school_id or academic_id context needed from a default user

class MarkStudentsAbsentDailyCommand extends Command
{
    protected $signature = 'attendance:mark-absent-daily';
    protected $description = 'Mark all active students as Absent by default for the current day.';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $this->info('Starting daily process to mark students as Absent by default...');
        Log::info('Command attendance:mark-absent-daily started.');

        $today = Carbon::today();
        $markedAbsentCount = 0;
        $errors = 0;

        // Assuming we need a context for school_id and academic_id.
        // If this command is run by a system cron without an authenticated user,
        // we might need to fetch a default/system school_id and academic_id
        // or make the command accept parameters if it needs to run for specific schools/sessions.
        // For now, using generalSetting() which is typical in this app.
        $currentAcademicId = generalSetting()->session_id; 
        $currentSchoolId = generalSetting()->school_id; 

        if (!$currentAcademicId || !$currentSchoolId) {
            $this->error('Could not determine current school_id or academic_id from general settings.');
            Log::error('Command attendance:mark-absent-daily: Missing school_id or academic_id from general settings.');
            return parent::FAILURE;
        }

        $activeStudentRecords = StudentRecord::where('academic_id', $currentAcademicId)
                                            ->where('school_id', $currentSchoolId)
                                            ->where('active_status', 1) // Ensure we only get active enrollments
                                            ->get();

        if ($activeStudentRecords->isEmpty()) {
            $this->info('No active student records found for the current academic session and school. Nothing to mark absent.');
            Log::info('Command attendance:mark-absent-daily: No active student records found.');
            return parent::SUCCESS;
        }

        $this->info('Found ' . $activeStudentRecords->count() . ' active student records to process for default ABE status.');

        foreach ($activeStudentRecords as $studentRecord) {
            try {
                SmStudentAttendance::updateOrCreate(
                    [
                        'student_id' => $studentRecord->student_id,
                        'attendance_date' => $today->toDateString(),
                        'student_record_id' => $studentRecord->id,
                        'academic_id' => $studentRecord->academic_id,
                        'school_id' => $studentRecord->school_id
                    ],
                    [
                        'attendance_type' => 'A', // Mark as Absent
                        'notes' => 'Defaulted to Absent',
                        'class_id' => $studentRecord->class_id,
                        'section_id' => $studentRecord->section_id,
                    ]
                );
                $markedAbsentCount++;
            } catch (\Exception $e) {
                $this->error('Failed to mark student_id: ' . $studentRecord->student_id . ' (record_id: ' . $studentRecord->id . ') as Absent. Error: ' . $e->getMessage());
                Log::error('Command attendance:mark-absent-daily: Error for student_id: ' . $studentRecord->student_id . ' - ' . $e->getMessage());
                $errors++;
            }
        }

        $this->info("Daily absent marking process completed. Marked {$markedAbsentCount} students as Absent. {$errors} errors encountered.");
        Log::info("Command attendance:mark-absent-daily finished. Marked Absent: {$markedAbsentCount}, Errors: {$errors}.");
        
        return parent::SUCCESS;
    }
} 