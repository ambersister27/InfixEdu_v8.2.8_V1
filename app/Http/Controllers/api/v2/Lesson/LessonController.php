<?php

namespace App\Http\Controllers\api\v2\Lesson;

use Exception;
use App\SmStaff;
use App\SmStudent;
use App\SmWeekend;
use Carbon\Carbon;
use App\SmClassRoom;
use App\SmClassTime;
use App\SmAcademicYear;
use App\SmAssignSubject;
use Carbon\CarbonPeriod;
use App\SmGeneralSettings;
use App\Scopes\SchoolScope;
use Illuminate\Http\Request;
use App\Models\StudentRecord;
use App\SmClassRoutineUpdate;
use App\Scopes\GlobalAcademicScope;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Modules\Lesson\Entities\SmLesson;
use App\Scopes\StatusAcademicSchoolScope;
use Modules\Lesson\Entities\LessonPlanner;
use App\Http\Resources\v2\LessonPlanResource;
use App\Http\Resources\v2\LessonPlanDetailsResource;

class LessonController extends Controller
{
    public function index(Request $request)
    {
        try {
            $student_id = $request->student_id;
            $record_id  = $request->record_id;
            $next_date  = $request->next_date;
    
            $student_detail = SmStudent::withoutGlobalScope(SchoolScope::class)
                ->where('id', $student_id)
                ->first(['id', 'school_id']);
    
            if (!$student_detail) {
                return response()->json([
                    'success' => false,
                    'data'    => null,
                    'message' => 'Student not found',
                ]);
            }
    
            $week_start_id = SmGeneralSettings::where('school_id', $student_detail->school_id)->value('week_start_id');
            $week_end_name = SmWeekend::withoutGlobalScope(SchoolScope::class)
                ->where('school_id', $student_detail->school_id)
                ->where('id', $week_start_id)
                ->value('name');
            
            $start_day  = WEEK_DAYS_BY_NAME[$week_end_name ?? 'Monday'];
            $week_start = $week_end_name??'Monday';
            $week_end    = getWeekendDay($week_start);
            $firstDayOfWeek = date('Y-m-d', strtotime($week_start.' this week', strtotime($next_date)));
            $lastDayOfWeek = date('Y-m-d', strtotime($week_end. ' this week', strtotime($next_date)));
            $data['this_week'] = getWeekNumber($next_date); 
            $period = generateDatePeriod($firstDayOfWeek, $lastDayOfWeek);            
            $dates = [];
            foreach ($period as $date) {
                $dates[] = $date;
            }
            
            $student_record = StudentRecord::where('school_id', $student_detail->school_id)->findOrFail($record_id);
            
            
            $data['weeks'] = SmWeekend::withoutGlobalScope(SchoolScope::class)
                ->with(['classRoutine' => function ($q) use ($student_record) {
                    $q->withoutGlobalScope(StatusAcademicSchoolScope::class)
                        ->where('class_id', $student_record->class_id)
                        ->where('section_id', $student_record->section_id)
                        ->where('academic_id', SmAcademicYear::SINGLE_SCHOOL_API_ACADEMIC_YEAR())
                        ->where('school_id', auth()->user()->school_id);
                }])
                ->where('active_status', 1)
                ->where('school_id', $student_detail->school_id)
                ->orderBy('order', 'ASC')
                ->get()
                ->map(function ($weekend, $index) use ($dates) {
                    
                    return [
                        'id'          => (int) $weekend->id,
                        'name'        => (string) date("l",strtotime($dates[$index])),
                        'isWeekend'   => (int) $weekend->is_weekend,
                        'date'        => $dates[$index] ?? null,
                        'classRoutine' => LessonPlanResource::collection($weekend->classRoutine),
                    ];
                });
            return response()->json([
                'success' => true,
                'data'    => $data,
                'message' => 'Lesson plan list',
            ]);
    
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => 'Something went wrong: ' . $e->getMessage(),
            ]);
        }
    }


    public function ViewlessonPlannerLesson(Request $request)
    {
        if(auth()->user()->role_id != 2){
            $request->validate([
                "student_id" => "required",
                "date" => "required",
                "subject_id" => "required"
            ]);
        }else{
            $request->validate([
                "student_id" => "nullable",
                "date" => "required",
                "subject_id" => "required"
            ]);
        }
        
        if(auth()->user()->role_id == 2)
        {
            $student = SmStudent::where('user_id',auth()->id())->first();
            if($student)
            {
                $record = StudentRecord::where('student_id',$student->id)->where('academic_id',getAcademicId())->first();
            }
        }else{
            $record = StudentRecord::where('student_id',$request->student_id)->where('academic_id',getAcademicId())->first();
        }

        if(!empty($record))
        {

            $lessonPlanDetail = LessonPlanner::where('class_id',$record->class_id)
                                ->where('subject_id',$request->subject_id)
                                ->where('lesson_date',date("Y-m-d",strtotime($request->date)))
                                ->with(['topics', 'subject'])
                                ->first();            
            $lessonPlanDetail = new LessonPlanDetailsResource($lessonPlanDetail);

            if (!$lessonPlanDetail) {
                $response = [
                    'success' => false,
                    'data'    => null,
                    'message' => 'Operation failed'
                ];
            } else {
                $response = [
                    'success' => true,
                    'data'    => $lessonPlanDetail,
                    'message' => 'Lesson plan detail'
                ];
            }
            return response()->json($response);
        }

        $response = [
            'success' => false,
            'data'    => [],
            'message' => 'Failed'
        ];
        
    }
}
