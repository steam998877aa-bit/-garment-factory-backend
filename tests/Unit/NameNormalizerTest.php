<?php

namespace Tests\Unit;

use App\Services\NameNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NameNormalizerTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_composite_department_and_workshop_string(): void
    {
        (new \Database\Seeders\DepartmentWorkshopSeeder())->run();
        $normalizer = new NameNormalizer();

        $parsed = $normalizer->parseComposite('خياطة <مصطفى>');

        $this->assertEquals('خياطة', $parsed['department']);
        $this->assertEquals('مصطفى', $parsed['workshop']);
        $this->assertEquals('خياطة', $normalizer->department('خياطة <مصطفى>'));
    }

    public function test_resolves_composite_string_with_parentheses(): void
    {
        (new \Database\Seeders\DepartmentWorkshopSeeder())->run();
        $normalizer = new NameNormalizer();

        $parsed = $normalizer->parseComposite('خياطة (أبو كرم)');

        $this->assertEquals('خياطة', $parsed['department']);
        $this->assertEquals('أبو كرم', $parsed['workshop']);
        $this->assertEquals('خياطة', $normalizer->department('خياطة (أبو كرم)'));
    }

    public function test_resolves_html_encoded_composite_string(): void
    {
        (new \Database\Seeders\DepartmentWorkshopSeeder())->run();
        $normalizer = new NameNormalizer();

        $parsed = $normalizer->parseComposite('خياطة &lt;مصطفى&gt;');

        $this->assertEquals('خياطة', $parsed['department']);
        $this->assertEquals('مصطفى', $parsed['workshop']);
        $this->assertEquals('خياطة', $normalizer->department('خياطة &lt;مصطفى&gt;'));
    }

    public function test_resolves_dash_separated_sewing_workshop(): void
    {
        (new \Database\Seeders\DepartmentWorkshopSeeder())->run();
        $normalizer = new NameNormalizer();

        $parsed = $normalizer->parseComposite('خياطة - مصطفى');

        $this->assertEquals('خياطة', $parsed['department']);
        $this->assertEquals('مصطفى', $parsed['workshop']);
        $this->assertEquals('خياطة', $normalizer->department('خياطة - مصطفى'));
    }

    public function test_resolves_dash_and_angle_brackets(): void
    {
        (new \Database\Seeders\DepartmentWorkshopSeeder())->run();
        $normalizer = new NameNormalizer();

        $parsed = $normalizer->parseComposite('خياطة - <مصطفى>');

        $this->assertEquals('خياطة', $parsed['department']);
        $this->assertEquals('مصطفى', $parsed['workshop']);
        $this->assertEquals('خياطة', $normalizer->department('خياطة - <مصطفى>'));
    }

    public function test_resolves_unicode_brackets(): void
    {
        (new \Database\Seeders\DepartmentWorkshopSeeder())->run();
        $normalizer = new NameNormalizer();

        $parsed = $normalizer->parseComposite('خياطة [مصطفى]');

        $this->assertEquals('خياطة', $parsed['department']);
        $this->assertEquals('مصطفى', $parsed['workshop']);
        $this->assertEquals('خياطة', $normalizer->department('خياطة [مصطفى]'));
    }
}
