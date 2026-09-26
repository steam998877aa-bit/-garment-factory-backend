<?php

namespace Tests\Unit;

use App\Services\ProductFileService;
use Cloudinary\Api\ApiResponse;
use Cloudinary\Api\Upload\UploadApi;
use Cloudinary\Asset\DeliveryType;
use Cloudinary\Cloudinary;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class ProductFileServiceTest extends TestCase
{
    public function test_product_images_are_uploaded_as_authenticated_cloudinary_assets(): void
    {
        Config::set('filesystems.disks.cloudinary.url', 'cloudinary://123:secret@demo');
        $uploadApi = Mockery::mock(UploadApi::class);
        $uploadApi->shouldReceive('upload')
            ->once()
            ->with(Mockery::type('string'), Mockery::on(fn (array $options): bool => $options['resource_type'] === 'image'
                && $options['type'] === DeliveryType::AUTHENTICATED
                && $options['overwrite'] === false
            ))
            ->andReturn(new ApiResponse([
                'public_id' => 'products/images/generated-id',
                'format' => 'jpg',
            ], []));

        $cloudinary = Mockery::mock(new Cloudinary([
            'cloud' => [
                'cloud_name' => 'demo',
                'api_key' => '123',
                'api_secret' => 'secret',
            ],
            'url' => ['secure' => true],
        ]));
        $cloudinary->shouldReceive('uploadApi')->once()->andReturn($uploadApi);
        $this->app->instance(Cloudinary::class, $cloudinary);

        $paths = app(ProductFileService::class)->storeImages([
            UploadedFile::fake()->create('sample.jpg', 20, 'image/jpeg'),
        ]);

        $this->assertSame(['cloudinary:products/images/generated-id.jpg'], $paths);
    }

    public function test_cloudinary_product_photo_is_proxied_with_an_authenticated_signed_url(): void
    {
        Config::set('filesystems.disks.cloudinary.url', 'cloudinary://123:secret@demo');
        $this->app->instance(Cloudinary::class, new Cloudinary([
            'cloud' => [
                'cloud_name' => 'demo',
                'api_key' => '123',
                'api_secret' => 'secret',
            ],
            'url' => ['secure' => true],
        ]));

        Http::fake(['*' => Http::response('image-bytes', 200, ['Content-Type' => 'image/jpeg'])]);

        $response = app(ProductFileService::class)
            ->imageResponse('cloudinary:products/images/sample.jpg');

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/image/authenticated/s--')
            && str_contains($request->url(), '/products/images/sample.jpg'));
        $this->assertSame('image-bytes', $response->getContent());
        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
    }
}
