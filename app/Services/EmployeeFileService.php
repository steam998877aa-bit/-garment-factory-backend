<?php

namespace App\Services;

use App\Models\Employee;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Stores the identity documents attached to an employee record.
 *
 * These are personal documents — an ID card scan and a CV — so they are kept on
 * the private disk and reached only through authorised endpoints. A public URL
 * would expose staff identity papers to anyone who guessed the path.
 */
class EmployeeFileService
{
    public const DISK = 'local';

    public const ID_CARD_DIRECTORY = 'employees/id-cards';

    public const CV_DIRECTORY = 'employees/cvs';

    /**
     * Store an ID card image, replacing any the employee already had.
     */
    public function storeIdCard(Employee $employee, UploadedFile $file): string
    {
        $this->deleteIdCard($employee);

        return $file->store(self::ID_CARD_DIRECTORY, self::DISK);
    }

    /**
     * Store a CV, replacing any the employee already had.
     */
    public function storeCv(Employee $employee, UploadedFile $file): string
    {
        $this->deleteCv($employee);

        return $file->store(self::CV_DIRECTORY, self::DISK);
    }

    public function deleteIdCard(Employee $employee): void
    {
        if ($employee->id_card_image !== null) {
            Storage::disk(self::DISK)->delete($employee->id_card_image);
        }
    }

    public function deleteCv(Employee $employee): void
    {
        if ($employee->cv_file !== null) {
            Storage::disk(self::DISK)->delete($employee->cv_file);
        }
    }

    /**
     * Remove every document held for an employee.
     */
    public function deleteAll(Employee $employee): void
    {
        $this->deleteIdCard($employee);
        $this->deleteCv($employee);
    }
}
