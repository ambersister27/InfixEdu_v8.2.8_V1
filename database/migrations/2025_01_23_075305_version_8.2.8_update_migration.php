<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\RolePermission\Entities\Permission;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Permission::where('route', 'fees_settings')->update([
            'sidebar_menu' => 'fees_settings',
            'parent_route' => null,
            'permission_section' => 0,
            'user_id' => null,
            'type' => 1,
            'old_id' => null,
            'role_id' => null,
        ]);
        Permission::where('route', 'exam_settings')->update([
            'sidebar_menu' => 'exam_settings',
            'parent_route' => null,
            'permission_section' => 0,
            'user_id' => null,
            'type' => 1,
            'old_id' => null,
            'role_id' => null,
        ]);
        Permission::where('route', 'students_report')->update([
            'sidebar_menu' => 'students_report',
            'lang_name' => 'common.students_report',
            'parent_route' => null,
            'permission_section' => 0,
            'user_id' => null,
            'type' => 1,
            'old_id' => null,
            'role_id' => null,
        ]);
        Permission::where('route', 'exam_report')->update([
            'sidebar_menu' => 'exam_report',
            'parent_route' => null,
            'permission_section' => 0,
            'user_id' => null,
            'type' => 1,
            'old_id' => null,
            'role_id' => null,
        ]);
        Permission::where('route', 'staff_report')->update([
            'sidebar_menu' => 'staff_report',
            'parent_route' => null,
            'permission_section' => 0,
            'user_id' => null,
            'type' => 1,
            'old_id' => null,
            'role_id' => null,
        ]);
        Permission::where('route', 'fees_report')->update([
            'sidebar_menu' => 'fees_report',
            'parent_route' => null,
            'permission_section' => 0,
            'user_id' => null,
            'type' => 1,
            'old_id' => null,
            'role_id' => null,
        ]);
        Permission::where('route', 'accounts_report')->update([
            'sidebar_menu' => 'accounts_report',
            'parent_route' => null,
            'permission_section' => 0,
            'user_id' => null,
            'type' => 1,
            'old_id' => null,
            'role_id' => null,
        ]);

        Permission::where('route', 'fees.fine-report')->update([
            'alternate_module' => null,
        ]);
        $fine_reports =  Permission::where('route', 'fine-report')->get();
        if($fine_reports->count()> 1){
            $fine_reports->first()?->delete();
        }
        Permission::where('route', 'accounts-report')->first()?->delete();
        Permission::where('route', 'reports')->first()?->delete();

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
