<?php

namespace Tests\Unit;

use App\Models\Employee;
use App\Services\EmployeeFileService;
use Cloudinary\Api\ApiResponse;
use Cloudinary\Api\Upload\UploadApi;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class EmployeeFileServiceTest extends TestCase
{
    public function test_employee_documents_are_uploaded_to_cloudinary_and_return_secure_urls(): void
    {
        Storage::fake('public');

        $uploadApi = Mockery::mock(UploadApi::class);
        $uploadApi->shouldReceive('upload')
            ->twice()
            ->withArgs(fn (string $path, array $options): bool => is_file($path)
                && $options === ['folder' => 'employee_documents', 'resource_type' => 'auto'])
            ->andReturn(
                new ApiResponse(['secure_url' => 'https://res.cloudinary.com/example/id-card.pdf'], []),
                new ApiResponse(['secure_url' => 'https://res.cloudinary.com/example/cv.pdf'], []),
            );
        Cloudinary::shouldReceive('uploadApi')->twice()->andReturn($uploadApi);

        $employee = new Employee();
        $files = app(EmployeeFileService::class);

        $idCardUrl = $files->storeIdCard(
            $employee,
            UploadedFile::fake()->create('id-card.pdf', 20, 'application/pdf'),
        );
        $cvUrl = $files->storeCv(
            $employee,
            UploadedFile::fake()->create('cv.pdf', 20, 'application/pdf'),
        );

        $this->assertSame('https://res.cloudinary.com/example/id-card.pdf', $idCardUrl);
        $this->assertSame('https://res.cloudinary.com/example/cv.pdf', $cvUrl);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_cloudinary_document_response_returns_the_direct_url_as_json(): void
    {
        $url = 'https://res.cloudinary.com/example/id-card.pdf';

        $response = app(EmployeeFileService::class)->response($url, 'No document.');

        $this->assertSame([
            'status' => true,
            'url' => $url,
        ], json_decode($response->getContent(), true));
    }
}
