<?php

namespace Tests\Feature;

use App\Services\Security\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression tests for dotted setting keys: `get()` must first match the
 * longest exact key ("withdrawal.assets") before descending into the value
 * ("withdrawal.assets.USDT.fee").
 */
final class SettingsServiceTest extends TestCase
{
    use RefreshDatabase;

    private SettingsService $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = app(SettingsService::class);

        $this->settings->set('withdrawal.assets', [
            'USDT' => ['enabled' => true, 'rail' => 'binance', 'min' => '10', 'fee' => '1', 'auto_limit' => '500'],
        ], 'withdrawal');

        $this->settings->set('deposits.confirmations', ['eth' => 12, 'btc' => 3], 'deposits');
        $this->settings->set('withdrawal.auto_enabled', false, 'withdrawal');
    }

    public function test_exact_key_lookup(): void
    {
        $this->assertFalse($this->settings->bool('withdrawal.auto_enabled', true));
        $this->assertSame([], $this->settings->array('does.not.exist', []));
    }

    public function test_nested_lookup_inside_dotted_keys(): void
    {
        $this->assertTrue($this->settings->bool('withdrawal.assets.USDT.enabled'));
        $this->assertSame('binance', $this->settings->string('withdrawal.assets.USDT.rail'));
        $this->assertSame('10', $this->settings->decimal('withdrawal.assets.USDT.min'));
        $this->assertSame(12, $this->settings->int('deposits.confirmations.eth'));
        $this->assertSame(3, $this->settings->int('deposits.confirmations.btc'));
    }

    public function test_defaults_are_used_for_missing_keys(): void
    {
        $this->assertSame('fallback', $this->settings->get('withdrawal.assets.ETH.rail', 'fallback'));
        $this->assertSame(99, $this->settings->int('deposits.confirmations.tron', 99));
        $this->assertSame([], $this->settings->array('withdrawal.assets.ETH', []));
    }

    public function test_set_overrides_existing_values(): void
    {
        $this->settings->set('withdrawal.auto_enabled', true, 'withdrawal');

        $this->assertTrue($this->settings->bool('withdrawal.auto_enabled'));

        $this->settings->set('withdrawal.assets', [
            'USDT' => ['enabled' => false, 'rail' => 'binance', 'min' => '20', 'fee' => '2', 'auto_limit' => '0'],
        ], 'withdrawal');

        $this->assertFalse($this->settings->bool('withdrawal.assets.USDT.enabled'));
        $this->assertSame('20', $this->settings->decimal('withdrawal.assets.USDT.min'));
    }
}
