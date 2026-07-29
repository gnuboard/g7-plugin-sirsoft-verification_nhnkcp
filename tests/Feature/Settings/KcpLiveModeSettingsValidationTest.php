<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Tests\Feature\Settings;

use App\Extension\PluginManager;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\PluginSettingsService;
use Illuminate\Testing\TestResponse;
use Plugins\Sirsoft\VerificationNhnkcp\Tests\PluginTestCase;

/**
 * 운영 모드 전환 시 자격증명 필수 검증.
 *
 * 코어 설정 저장 경로(PUT /api/admin/plugins/{id}/settings)에서 운영 모드일 때만 사이트코드와
 * 암호화 키를 required 로 강제하는지, 그리고 에러 메시지의 항목 이름이 화면 언어로 노출되는지
 * 확인한다. 자격증명 없이 운영 모드가 켜져 인증이 조용히 실패하는 상황을 막는 게 목적이다.
 *
 * @since 1.0.0
 */
class KcpLiveModeSettingsValidationTest extends PluginTestCase
{
    private User $admin;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdminUser();
        $this->token = $this->admin->createToken('test-token')->plainTextToken;
    }

    /**
     * @scenario mode=live,live_credentials=missing_site_cd
     *
     * @effects live_mode_requires_site_cd_and_enc_key
     */
    public function test_live_mode_without_credentials_is_rejected(): void
    {
        $response = $this->putSettings([
            'is_test_mode' => false,
            'live_site_cd' => '',
            'live_enc_key' => '',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['live_site_cd', 'live_enc_key']);
    }

    /**
     * @scenario mode=live,live_credentials=missing_enc_key
     *
     * @effects live_mode_requires_site_cd_and_enc_key
     */
    public function test_validation_messages_use_korean_field_labels(): void
    {
        $response = $this->putSettings([
            'is_test_mode' => false,
            'live_site_cd' => '',
            'live_enc_key' => '',
        ]);

        $response->assertStatus(422);

        $errors = $response->json('errors');
        $this->assertStringContainsString('운영 사이트코드', $errors['live_site_cd'][0]);
        $this->assertStringNotContainsString('live site cd', $errors['live_site_cd'][0]);
        $this->assertStringContainsString('운영 암호화 키', $errors['live_enc_key'][0]);
    }

    /**
     * @scenario mode=live,live_credentials=complete
     *
     * @effects live_mode_requires_site_cd_and_enc_key
     */
    public function test_live_mode_with_credentials_is_accepted(): void
    {
        $response = $this->putSettings([
            'is_test_mode' => false,
            'live_site_cd' => 'A1B2C',
            'live_enc_key' => 'live-secret-key',
        ]);

        $response->assertStatus(200);
    }

    /**
     * @scenario mode=test,live_credentials=complete
     *
     * @effects test_mode_saves_without_live_credentials
     */
    public function test_test_mode_without_live_credentials_is_accepted(): void
    {
        $response = $this->putSettings([
            'is_test_mode' => true,
            'live_site_cd' => '',
            'live_enc_key' => '',
        ]);

        $response->assertStatus(200);
    }

    /**
     * @scenario mode=live,live_credentials=complete
     *
     * @effects sensitive_key_is_not_returned_in_plain_text
     */
    public function test_saved_encryption_key_is_not_stored_in_plain_text(): void
    {
        $this->putSettings([
            'is_test_mode' => false,
            'live_site_cd' => 'A1B2C',
            'live_enc_key' => 'super-secret-live-key',
        ])->assertStatus(200);

        // 저장 파일에는 암호문이 들어가고, 서비스 조회 시에만 복호화되어야 한다.
        $storage = app(PluginManager::class)
            ->getPlugin(self::PLUGIN_IDENTIFIER)
            ->getStorage();

        $stored = json_decode((string) $storage->get('settings', 'setting.json'), true);

        $this->assertIsArray($stored);
        $this->assertNotSame('super-secret-live-key', $stored['live_enc_key'] ?? null, '평문 저장 금지');

        $this->assertSame(
            'super-secret-live-key',
            app(PluginSettingsService::class)->get(self::PLUGIN_IDENTIFIER, 'live_enc_key'),
        );
    }

    /**
     * core.plugins.update 권한을 가진 관리자 생성.
     */
    private function createAdminUser(): User
    {
        $user = User::factory()->create();

        $permission = Permission::firstOrCreate(
            ['identifier' => 'core.plugins.update'],
            ['name' => json_encode(['ko' => '플러그인 수정', 'en' => 'Update Plugins']), 'type' => 'admin']
        );

        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            ['name' => json_encode(['ko' => '관리자', 'en' => 'Admin']), 'type' => 'admin']
        );

        $testRole = Role::create([
            'identifier' => 'nhnkcp_settings_test_'.uniqid(),
            'name' => json_encode(['ko' => '테스트', 'en' => 'Test']),
            'type' => 'admin',
        ]);
        $testRole->permissions()->sync([$permission->id]);

        $user->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $user->roles()->attach($testRole->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user->fresh();
    }

    /**
     * 인증 헤더가 적용된 설정 저장 요청.
     *
     * @param  array<string, mixed>  $body
     * @return TestResponse
     */
    private function putSettings(array $body)
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->putJson('/api/admin/plugins/'.self::PLUGIN_IDENTIFIER.'/settings', $body);
    }
}
