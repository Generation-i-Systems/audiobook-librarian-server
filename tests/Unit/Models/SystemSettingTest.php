<?php

namespace Tests\Unit\Models;

use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SystemSettingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_can_get_and_set_settings(): void
    {
        // Test setting a value
        SystemSetting::set('test_key', 'test_value');

        $setting = SystemSetting::where('key', 'test_key')->first();
        $this->assertNotNull($setting);
        $this->assertEquals('test_value', $setting->value);
    }

    #[Test]
    public function it_can_get_existing_setting(): void
    {
        // Create a setting directly
        SystemSetting::create([
            'key' => 'existing_key',
            'value' => 'existing_value',
        ]);

        $value = SystemSetting::get('existing_key');
        $this->assertEquals('existing_value', $value);
    }

    #[Test]
    public function it_returns_default_when_setting_not_found(): void
    {
        $value = SystemSetting::get('non_existent_key', 'default_value');
        $this->assertEquals('default_value', $value);
    }

    #[Test]
    public function it_returns_null_when_setting_not_found_and_no_default(): void
    {
        $value = SystemSetting::get('non_existent_key');
        $this->assertNull($value);
    }

    #[Test]
    public function it_updates_existing_setting(): void
    {
        // Create initial setting
        SystemSetting::set('update_key', 'initial_value');

        // Update the setting
        SystemSetting::set('update_key', 'updated_value');

        $value = SystemSetting::get('update_key');
        $this->assertEquals('updated_value', $value);

        // Ensure only one record exists
        $count = SystemSetting::where('key', 'update_key')->count();
        $this->assertEquals(1, $count);
    }
}
