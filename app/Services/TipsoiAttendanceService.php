<?php

namespace App\Services;

use App\SmStudent;
use App\SmStudentAttendance;
use App\Models\StudentRecord;
use App\SmNotification;
use App\SmParent;
use App\Http\Controllers\Admin\SystemSettings\SmSystemSettingController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class TipsoiAttendanceService
{
    protected $baseUrl;
    protected $apiToken;
    protected $perPage;

    public function __construct()
    {
        $this->baseUrl = 'https://api-inovace360.com/api/v1/logs';
        $this->apiToken = config('services.tipsoi.api_token');
        // $this->perPage = 500; // We'll let the command specify per_page if needed, or use API default first
    }

    public function syncAttendance(Carbon $startTime, Carbon $endTime)
    {
        $syncStartTime = microtime(true); // Performance tracking
        Log::info('TIPSOI: Starting attendance sync for range: ' . $startTime->toDateTimeString() . ' to ' . $endTime->toDateTimeString());
        $syncedCount = 0;
        $notFoundStudent = 0;
        $notFoundRecord = 0;

        $nextPageUrl = $this->baseUrl;
        $currentPage = 1;

        // Prepare initial parameters for the first API call
        $queryParams = [
            'start' => $startTime->format('Y-m-d H:i:s'),
            'end' => $endTime->format('Y-m-d H:i:s'),
            'api_token' => $this->apiToken,
            'per_page' => 500000, // Explicitly set per_page for robust fetching
            // 'criteria' and 'order_key' can be added if needed, defaults are fine
        ];

        // Get settings once outside the loop
        $currentAcademicId = generalSetting()->session_id;

        $apiCallStartTime = microtime(true); // Track API time
        
        do {
            try {
                Log::info("TIPSOI: Fetching page {$currentPage}. URL: " . ($nextPageUrl ?? 'N/A') . " with params: " . json_encode($queryParams));
                
                // Use $queryParams for the first request, then $nextPageUrl for subsequent ones
                $response = Http::get($nextPageUrl, $queryParams);
                
                // After the first request, clear queryParams so they are not re-sent if $nextPageUrl already contains them.
                // The API might include all necessary params in the 'next' link.
                if ($currentPage == 1) {
                    $queryParams = []; // Clear for subsequent paged requests using $nextPageUrl
                }

                if ($response->failed()) {
                    Log::error('TIPSOI: API request failed for page ' . $currentPage . '. Status: ' . $response->status() . '. Body: ' . $response->body());
                    break; 
                }

                $data = $response->json();
                $attendanceLogs = $data['data'] ?? [];

                if (empty($attendanceLogs) && $currentPage == 1) {
                    Log::info('TIPSOI: No attendance logs found in the given time range.');
                   // break; // No need to break if first page is empty, subsequent calls controlled by $nextPageUrl
                }
                
                $apiCallEndTime = microtime(true);
                $apiCallDuration = round($apiCallEndTime - $apiCallStartTime, 2);
                Log::info('TIPSOI: Found ' . count($attendanceLogs) . ' logs on page ' . $currentPage . '. API call took ' . $apiCallDuration . ' seconds.');

                // Skip processing if no logs found
                if (empty($attendanceLogs)) {
                    $nextPageUrl = $data['links']['next'] ?? null;
                    $currentPage++;
                    continue;
                }

                // OPTIMIZATION: Extract all person identifiers to fetch students in batch
                $personIdentifiers = array_column($attendanceLogs, 'person_identifier');
                $personIdentifiers = array_filter($personIdentifiers); // Remove any empty values
                
                if (empty($personIdentifiers)) {
                    Log::warning('TIPSOI: No valid person identifiers found in the logs.');
                    $nextPageUrl = $data['links']['next'] ?? null;
                    $currentPage++;
                    continue;
                }

                // Fetch all students at once indexed by admission_no
                $dbFetchStartTime = microtime(true);
                $students = SmStudent::whereIn('admission_no', $personIdentifiers)
                    ->get()
                    ->keyBy('admission_no');
                
                // Extract all student IDs for the students we found
                $studentIds = $students->pluck('id')->toArray();
                
                // Fetch all student records at once for the found students
                $studentRecords = StudentRecord::whereIn('student_id', $studentIds)
                    ->where('academic_id', $currentAcademicId)
                    ->get()
                    ->groupBy('student_id'); // Group by student_id for easy lookup
                
                $dbFetchEndTime = microtime(true);
                $dbFetchDuration = round($dbFetchEndTime - $dbFetchStartTime, 2);
                Log::info('TIPSOI: Fetched ' . $students->count() . ' students and ' . $studentRecords->count() . ' student records in ' . $dbFetchDuration . ' seconds.');

                $processStartTime = microtime(true);
                
                $toCreate = []; // Collect records for batch processing if possible

                foreach ($attendanceLogs as $log) {
                    // Ensure essential fields are present
                    if (!isset($log['person_identifier']) || !isset($log['logged_time'])) {
                        Log::warning('TIPSOI: Skipping log due to missing person_identifier or logged_time.', $log);
                        continue;
                    }
                    
                    // Student lookup using person_identifier (admission_no) from the pre-fetched collection
                    $student = $students[$log['person_identifier']] ?? null;

                    if (!$student) {
                        Log::warning('TIPSOI: Student not found with person_identifier (admission_no): ' . $log['person_identifier']);
                        $notFoundStudent++;
                        continue;
                    }

                    // Student record lookup from the pre-fetched and grouped collection
                    $currentSchoolId = $student->school_id;
                    $studentRecord = $studentRecords[$student->id]->first() ?? null;

                    if (!$studentRecord) {
                        Log::warning('TIPSOI: StudentRecord not found for student_id: ' . $student->id . ' (person_identifier: ' . $log['person_identifier'] . ') for academic_id: ' . $currentAcademicId);
                        $notFoundRecord++;
                        continue;
                    }

                    $attendanceType = 'P'; // Default to Present, modify based on $log['type'] if available and mapped
                    // Example: if ($log['type'] === 'late_entry_from_tipsoi') { $attendanceType = 'L'; }

                    $attendanceDate = Carbon::parse($log['logged_time'])->format('Y-m-d');

                    SmStudentAttendance::updateOrCreate(
                        [
                            'student_id' => $student->id,
                            'attendance_date' => $attendanceDate,
                            'student_record_id' => $studentRecord->id,
                            'academic_id' => $studentRecord->academic_id, // Use academic_id from student_record
                            'school_id' => $studentRecord->school_id    // Use school_id from student_record
                        ],
                        [
                            'attendance_type' => $attendanceType,
                            'notes' => 'Synced from TIPSOI - Log UID: ' . ($log['uid'] ?? 'N/A'),
                            'class_id' => $studentRecord->class_id,
                            'section_id' => $studentRecord->section_id,
                            // 'created_at' and 'updated_at' will be handled by Eloquent
                        ]
                    );
                    $syncedCount++;
                }

                $processEndTime = microtime(true);
                $processDuration = round($processEndTime - $processStartTime, 2);
                Log::info('TIPSOI: Processed ' . count($attendanceLogs) . ' logs in ' . $processDuration . ' seconds. Synced: ' . $syncedCount . ', Students not found: ' . $notFoundStudent . ', Records not found: ' . $notFoundRecord);

                $nextPageUrl = $data['links']['next'] ?? null;
                $currentPage++;
                
                // Reset API timing for next page
                $apiCallStartTime = microtime(true);

            } catch (\Exception $e) {
                Log::error('TIPSOI: Exception during attendance sync. Message: ' . $e->getMessage() . ' Trace: ' . $e->getTraceAsString());
                break; // Exit loop on exception
            }
        } while ($nextPageUrl);

        $syncEndTime = microtime(true);
        $totalDuration = round($syncEndTime - $syncStartTime, 2);
        Log::info("TIPSOI: Sync completed in {$totalDuration} seconds. Total records processed from API: {$syncedCount}. Students not found: {$notFoundStudent}. StudentRecords not found: {$notFoundRecord}.");
        return ['synced' => $syncedCount, 'notFoundStudent' => $notFoundStudent, 'notFoundRecord' => $notFoundRecord];
    }

    // Original notification logic - can be called after processing all attendance for a student for a day
    public function sendNotification(SmStudentAttendance $attendance)
    {
        // ... (keep existing notification logic or adapt as needed)
        // This might need to be re-evaluated if we sync a whole day at once, 
        // to avoid sending multiple notifications for the same student/day.
        // For now, it's separate and can be called if individual attendance marking needs instant notification.
        
        $student = SmStudent::find($attendance->student_id);
        if(!$student) return;

        $notification = new SmNotification();
        $notification->user_id = $student->user_id; // For student
        $notification->role_id = 2; // Student role
        $notification->school_id = $student->school_id; 
        $notification->academic_id = $attendance->academic_id;
        $notification->date = date('Y-m-d');
        $notification->message = $attendance->attendance_type == 'P' ? 'Attendance: Present' : ($attendance->attendance_type == 'L' ? 'Attendance: Late' : 'Attendance: Absent');
        $notification->url = 'student-attendance';
        $notification->save();

        // Parent Notification
        $parent = SmParent::find($student->parent_id);
        if($parent){
            $notification = new SmNotification();
            $notification->user_id = $parent->user_id; // For parent
            $notification->role_id = 3; // Parent role
            $notification->school_id = $student->school_id;
            $notification->academic_id = $attendance->academic_id;
            $notification->date = date('Y-m-d');
            $notification->message = $student->full_name . ' - Attendance: ' . ($attendance->attendance_type == 'P' ? 'Present' : ($attendance->attendance_type == 'L' ? 'Late' : 'Absent'));
            $notification->url = 'student-attendance';
            $notification->save();
        }
        
        // Consider using SmSystemSettingController for push notifications if configured
        // (Ensure $request is available or adapt this part)
        // try {
        //     $systemSettingsController = new SmSystemSettingController();
        //     // $request might need to be new Request() or constructed with necessary data
        //     // $systemSettingsController->sendNotification($notification, new Request()); // This line needs careful review for context
        // } catch (\Exception $e) {
        //     Log::error('TIPSOI: Failed to send push notification. Error: ' . $e->getMessage());
        // }
    }
} 