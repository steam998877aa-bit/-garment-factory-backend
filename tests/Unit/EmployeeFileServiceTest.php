<?php

namespace Tests\Unit;

use App\Models\Employee;
use App\Http\Resources\PortalProfileResource;
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
                && $options === [
                    'folder' => 'employee_documents',
                    'resource_type' => 'auto',
                    'type' => 'upload',
                    'access_mode' => 'public',
                ])
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

    public function test_portal_profile_exposes_cloudinary_document_urls_directly(): void
    {
        $idCardUrl = 'https://res.cloudinary.com/example/id-card.pdf';
        $cvUrl = 'https://res.cloudinary.com/example/cv.pdf';
        $employee = new Employee([
            'id_card_image' => $idCardUrl,
            'cv_file' => $cvUrl,
        ]);

        $profile = (new PortalProfileResource($employee))->toArray(request());

        $this->assertSame($idCardUrl, $profile['id_card_path']);
        $this->assertSame($cvUrl, $profile['cv_path']);
        $this->assertSame($idCardUrl, $profile['id_card_image']['url']);
        $this->assertSame($cvUrl, $profile['cv_file']['url']);
    }
}
