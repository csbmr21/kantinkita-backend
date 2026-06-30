<?php
namespace Tests\Feature;

use App\Models\User;
use App\Models\Tenant;
use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::insert([
            ['key' => 'fee_type',  'value' => 'percentage', 'type' => 'string', 'group' => 'payment', 'label' => 'Fee',        'company_code' => 'UNIV', 'created_by' => 'test', 'updated_by' => 'test'],
            ['key' => 'fee_value', 'value' => '5',          'type' => 'float',  'group' => 'payment', 'label' => 'Fee Value',  'company_code' => 'UNIV', 'created_by' => 'test', 'updated_by' => 'test'],
            ['key' => 'payment_timeout', 'value' => '30',   'type' => 'integer','group' => 'payment', 'label' => 'Timeout',    'company_code' => 'UNIV', 'created_by' => 'test', 'updated_by' => 'test'],
        ]);
    }

    public function test_register_success(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'full_name'             => 'Test User',
            'username'              => 'testuser123',
            'email'                 => 'test@example.com',
            'phone'                 => '081234567890',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201)
                 ->assertJsonPath('status', true)
                 ->assertJsonStructure(['data' => ['user', 'token']]);
    }

    public function test_register_duplicate_email(): void
    {
        User::factory()->create(['email' => 'dup@example.com']);

        $response = $this->postJson('/api/v1/auth/register', [
            'full_name'             => 'Test',
            'username'              => 'newuser',
            'email'                 => 'dup@example.com',
            'phone'                 => '081234567890',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422)->assertJsonPath('status', false);
    }

    public function test_login_success(): void
    {
        User::factory()->create(['email' => 'login@example.com', 'password' => bcrypt('secret123')]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email'    => 'login@example.com',
            'password' => 'secret123',
        ]);

        $response->assertStatus(200)
                 ->assertJsonPath('status', true)
                 ->assertJsonStructure(['data' => ['token', 'user']]);
    }

    public function test_login_wrong_password(): void
    {
        User::factory()->create(['email' => 'user@example.com', 'password' => bcrypt('correct')]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email'    => 'user@example.com',
            'password' => 'wrong_password',
        ]);

        $response->assertStatus(401)->assertJsonPath('status', false);
    }

    public function test_logout(): void
    {
        $user  = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/v1/auth/logout');

        $response->assertStatus(200)->assertJsonPath('status', true);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_me_returns_current_user(): void
    {
        $user  = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/v1/auth/me');

        $response->assertStatus(200)
                 ->assertJsonPath('data.email', $user->email);
    }

    public function test_setup_profile_customer_success(): void
    {
        // Seeder roles need to exist
        $this->artisan('db:seed', ['--class' => 'RolePermissionSeeder']);

        $user = User::factory()->create([
            'role' => 'customer',
            'profile_completed' => false,
        ]);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->putJson('/api/v1/auth/setup-profile', [
            'username' => 'customeruser',
            'full_name' => 'Customer User',
            'email' => $user->email,
            'phone' => '081234567890',
            'no_ktp' => '1234567890123456',
            'dob' => '2000-01-01',
            'role' => 'customer',
            'tenant_name' => '',
        ]);

        $response->assertStatus(200);
    }

    public function test_setup_profile_owner_success(): void
    {
        // Seeder roles need to exist
        $this->artisan('db:seed', ['--class' => 'RolePermissionSeeder']);

        $user = User::factory()->create([
            'role' => 'customer', // newly registered user has role customer by default
            'profile_completed' => false,
        ]);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->putJson('/api/v1/auth/setup-profile', [
            'username' => 'owneruser',
            'full_name' => 'Owner User',
            'email' => $user->email,
            'phone' => '081234567890',
            'no_ktp' => '1234567890123456',
            'dob' => '2000-01-01',
            'role' => 'owner',
            'tenant_name' => 'Toko Owner Baru',
        ]);

        $response->assertStatus(200);
    }

    public function test_verify_google_otp_success(): void
    {
        $this->artisan('db:seed', ['--class' => 'RolePermissionSeeder']);

        $user = User::factory()->create([
            'email' => 'googleuser@example.com',
            'role' => 'customer',
        ]);
        $intent = \Illuminate\Support\Str::random(40);
        $otp = '123456';

        \Illuminate\Support\Facades\Cache::put("otp_intent:{$intent}", [
            'user_id' => $user->id,
            'email' => $user->email,
            'otp' => $otp
        ], now()->addMinutes(10));

        $response = $this->postJson('/api/v1/auth/google/verify-otp', [
            'email' => 'googleuser@example.com',
            'intent' => $intent,
            'otp' => $otp,
        ]);

        $response->assertStatus(200)
                 ->assertJsonPath('status', true)
                 ->assertJsonStructure(['data' => ['token', 'user']]);
                 
        $data = $response->json('data');
        $this->assertArrayHasKey('computed_permissions', $data['user']);
        $this->assertArrayHasKey('tenant', $data['user']);
        $this->assertArrayHasKey('assigned_role', $data['user']);
    }

    public function test_verify_google_otp_invalid(): void
    {
        $user = User::factory()->create(['email' => 'googleuser@example.com']);
        $intent = \Illuminate\Support\Str::random(40);

        \Illuminate\Support\Facades\Cache::put("otp_intent:{$intent}", [
            'user_id' => $user->id,
            'email' => $user->email,
            'otp' => '123456'
        ], now()->addMinutes(10));

        // Wrong OTP code
        $response = $this->postJson('/api/v1/auth/google/verify-otp', [
            'email' => 'googleuser@example.com',
            'intent' => $intent,
            'otp' => '654321',
        ]);

        $response->assertStatus(422)
                 ->assertJsonPath('status', false)
                 ->assertJsonPath('message', 'Kode OTP yang Anda masukkan salah.');
    }

    public function test_resend_otp_success(): void
    {
        \Illuminate\Support\Facades\Mail::fake();

        $user = User::factory()->create(['email' => 'googleuser@example.com']);
        $intent = \Illuminate\Support\Str::random(40);
        $otp = '111111';

        \Illuminate\Support\Facades\Cache::put("otp_intent:{$intent}", [
            'user_id' => $user->id,
            'email' => $user->email,
            'otp' => $otp
        ], now()->addMinutes(10));

        $response = $this->postJson('/api/v1/auth/google/resend-otp', [
            'email' => 'googleuser@example.com',
            'intent' => $intent,
        ]);

        $response->assertStatus(200)
                 ->assertJsonPath('status', true);

        // Check cache is updated
        $newCached = \Illuminate\Support\Facades\Cache::get("otp_intent:{$intent}");
        $this->assertNotEquals($otp, $newCached['otp']);
        
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\OtpMail::class);
    }

    public function test_google_callback_deactivated_user(): void
    {
        $user = User::factory()->create([
            'email' => 'deactivated@example.com',
            'status' => 0,
        ]);

        $googleUser = \Mockery::mock('Laravel\Socialite\Two\User');
        $googleUser->shouldReceive('getId')->andReturn('123456');
        $googleUser->shouldReceive('getEmail')->andReturn('deactivated@example.com');
        $googleUser->shouldReceive('getName')->andReturn('Deactivated User');
        $googleUser->shouldReceive('getAvatar')->andReturn('avatar.jpg');

        $provider = \Mockery::mock('Laravel\Socialite\Two\GoogleProvider');
        $provider->shouldReceive('stateless')->andReturn($provider);
        $provider->shouldReceive('user')->andReturn($googleUser);

        \Laravel\Socialite\Facades\Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $response = $this->get('/api/v1/auth/google/callback');

        $response->assertRedirect(config('app.frontend_url', 'http://localhost:5173') . '/login?error=Akun+Anda+telah+dinonaktifkan');
        
        $this->assertDatabaseHas('users', [
            'email' => 'deactivated@example.com',
            'status' => 0, // status must remain inactive
        ]);
    }
}

