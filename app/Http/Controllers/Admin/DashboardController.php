<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\SmClass;
use App\SmStudentAttendance;
use App\Models\StudentRecord;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        $classes = SmClass::where('active_status', 1)
                        ->where('academic_id', getAcademicId())
                        ->where('school_id', auth()->user()->school_id)
                        ->get();

        $classAttendanceData = [];

        foreach ($classes as $class) {
            $studentRecords = StudentRecord::where('class_id', $class->id)
                                ->where('academic_id', getAcademicId())
                                ->where('school_id', auth()->user()->school_id)
                                ->where('is_promote', 0)
                                ->whereHas('student', function ($q) {
                                    $q->where('active_status', 1);
                                })
                                ->pluck('id');

            if ($studentRecords->isNotEmpty()) {
                $totalStudents = $studentRecords->count();

                // Calculate total possible attendance days (e.g., for the current month)
                // This is a simplified example. You might need a more complex logic 
                // to determine the number of instructional days.
                $daysInMonth = cal_days_in_month(CAL_GREGORIAN, date('m'), date('Y'));
                $totalPossibleAttendance = $totalStudents * $daysInMonth;


                $presentAttendance = SmStudentAttendance::whereIn('student_record_id', $studentRecords)
                                    ->where('attendance_type', 'P')
                                    ->where('academic_id', getAcademicId())
                                    ->where('school_id', auth()->user()->school_id)
                                    ->whereMonth('attendance_date', date('m'))
                                    ->whereYear('attendance_date', date('Y'))
                                    ->count();

                $attendancePercentage = ($totalPossibleAttendance > 0) ? ($presentAttendance / $totalPossibleAttendance) * 100 : 0;

                $classAttendanceData[] = [
                    'class_name' => $class->class_name,
                    'attendance_percentage' => round($attendancePercentage, 2),
                ];
            } else {
                $classAttendanceData[] = [
                    'class_name' => $class->class_name,
                    'attendance_percentage' => 0,
                ];
            }
        }

        // Sort by attendance percentage, highest to lowest
        usort($classAttendanceData, function ($a, $b) {
            return $b['attendance_percentage'] <=> $a['attendance_percentage'];
        });

        return view('backEnd.dashboard', compact('classAttendanceData'));
    }
}
