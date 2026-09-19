<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Workshop;
use Illuminate\Database\Seeder;

/**
 * The canonical department and workshop names, with every spelling that has
 * been seen in the worksheets or typed into the screens.
 *
 * Adding a new name here is how the factory grows: nothing outside this list
 * can be written to a production row.
 */
class DepartmentWorkshopSeeder extends Seeder
{
    /**
     * @var list<array{name: string, aliases: list<string>, position: int}>
     */
    protected array $departments = [
        ['name' => 'موجه تصميم', 'aliases' => ['موجه التصميم', 'موجة تصميم'],        'position' => 5],
        ['name' => 'جاهز قص',    'aliases' => ['جاهز القص', 'قص'],                  'position' => 10],
        // "جاهز طباعه" (waiting to be printed) and "طباعة" (on the press) are
        // two different stages that both appear in the same monthly sheet, so
        // "طباعه" belongs to the latter and must not be an alias of the former.
        ['name' => 'جاهز طباعه', 'aliases' => ['جاهز طباعة', 'جاهز الطباعه'],        'position' => 20],
        ['name' => 'طباعة',      'aliases' => ['طباعه', 'الطباعة', 'الطباعه'],       'position' => 25],
        ['name' => 'مطابع',      'aliases' => ['المطابع'],                            'position' => 30],
        ['name' => 'ج خ',        'aliases' => [],                                     'position' => 40],
        ['name' => 'جاهز خياطة', 'aliases' => ['جاهز خياطه', 'جاهز الخياطة'],        'position' => 45],
        ['name' => 'خياطة',      'aliases' => ['خياطه', 'الخياطة', 'الخياطه'],       'position' => 50],
        ['name' => 'جاهز كوي',   'aliases' => ['جاهز الكوي', 'جاهز كوى'],            'position' => 52],
        ['name' => 'كوي',        'aliases' => ['الكوي', 'كوى'],                       'position' => 54],
        ['name' => 'تجهيز',      'aliases' => ['التجهيز'],                            'position' => 56],
        ['name' => 'تنضيف و فحص', 'aliases' => ['تنظيف وفحص', 'تنظيف و فحص', 'تنضيف وفحص'], 'position' => 58],
        ['name' => 'امبلاج',     'aliases' => ['أمبلاج', 'إمبلاج', 'التغليف', 'امبلاچ'], 'position' => 60],
        ['name' => 'جاهز تسليم', 'aliases' => ['جاهز التسليم'],                       'position' => 70],
        ['name' => 'البيزك',     'aliases' => ['بيزك', 'الببزك', 'Basic', 'basic'],  'position' => 75],
        ['name' => 'مسلم',       'aliases' => [],                                     'position' => 80],
    ];

    /**
     * @var list<array{name: string, aliases: list<string>}>
     */
    protected array $workshops = [
        ['name' => 'مصطفى',    'aliases' => ['مصطفي']],
        ['name' => 'أبو كرم',  'aliases' => ['ابو كرم']],
        ['name' => 'أبو يوسف', 'aliases' => ['ابو يوسف']],
        ['name' => 'صالح',     'aliases' => []],
        ['name' => 'مسلم',     'aliases' => []],
        ['name' => 'تجار',     'aliases' => ['التجار']],
    ];

    public function run(): void
    {
        foreach ($this->departments as $row) {
            Department::updateOrCreate(
                ['name' => $row['name']],
                ['aliases' => $row['aliases'], 'position' => $row['position'], 'is_active' => true],
            );
        }

        foreach ($this->workshops as $row) {
            Workshop::updateOrCreate(
                ['name' => $row['name']],
                ['aliases' => $row['aliases'], 'is_active' => true],
            );
        }

        $this->command?->line(sprintf(
            '  %d departments, %d workshops',
            count($this->departments),
            count($this->workshops),
        ));
    }
}
