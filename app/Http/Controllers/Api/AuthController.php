<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Models\Tenant;
use App\Models\ActivityLog;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Mail\OtpMail;
use App\Mail\ResetPasswordMail;
use Illuminate\Support\Facades\Http;


class AuthController extends Controller
{
    use ApiResponse;

    public function checkCompany(Request $request)
    {
        $request->validate([
            'company_code' => 'required|string|max:50',
        ]);

        $code = strtoupper(trim($request->company_code));

        // Special Bypass for System Administrator
        if ($code === 'SYSAD') {
            return $this->success([
                'company_code' => 'SYSAD',
                'company_name' => 'System Administration',
                'tenant_count' => 1,
            ], 'Kode perusahaan valid (Mode Administrator)');
        }

        // Cek apakah company_code terdaftar di tabel tenants (aktif)
        $tenant = Tenant::where('company_code', $code)
            ->where('status', 1)
            ->where('is_deleted', 0)
            ->first();

        if (!$tenant) {
            return $this->error('Kode perusahaan tidak ditemukan atau tidak aktif.', 404);
        }

        $tenantCount = Tenant::where('company_code', $code)
            ->where('status', 1)
            ->where('is_deleted', 0)
            ->count();

        return $this->success([
            'company_code' => $code,
            'company_name' => $tenant->tenant_name,
            'tenant_count' => $tenantCount,
        ], 'Kode perusahaan valid');
    }

    public function register(RegisterRequest $request)
    {
        $user = User::create([
            'name' => $request->full_name,
            'full_name' => $request->full_name,
            'username' => $request->username,
            'email' => $request->email,
            'phone' => $request->phone,
            'password' => Hash::make($request->password),
            'role' => 'customer',
            'company_code' => $request->company_code ?? 'UNIV',
            'created_by' => $request->username,
            'updated_by' => $request->username,
            'status' => 1,
            'profile_completed' => false,
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;
        ActivityLog::record('register', "User baru terdaftar: {$user->email}", $user->id);

        return $this->success(['user' => $user, 'token' => $token], 'Registrasi berhasil', 201);
    }

    public function login(LoginRequest $request)
    {
        $user = User::where('email', $request->email)->where('is_deleted', 0)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return $this->error('Email atau password salah.', 401);
        }

        if (!$user->status) {
            return $this->error('Akun Anda telah dinonaktifkan.', 403);
        }

        $user->tokens()->delete();
        $token = $user->createToken('auth_token')->plainTextToken;

        ActivityLog::record('login', "Login berhasil: {$user->email}", $user->id);

        $user->load(['tenant', 'assignedRole']);
        $user->computed_permissions = $user->getAllPermissions()->pluck('slug');

        return $this->success(['user' => $user, 'token' => $token], 'Login berhasil');
    }

    public function logout(Request $request)
    {
        ActivityLog::record('logout', "Logout: {$request->user()->email}");
        $request->user()->currentAccessToken()->delete();
        return $this->success(null, 'Logout berhasil');
    }

    public function me(Request $request)
    {
        $user = $request->user()->load(['tenant', 'assignedRole']);
        $user->computed_permissions = $user->getAllPermissions()->pluck('slug');
        return $this->success($user);
    }

    public function updateProfile(Request $request)
    {
        $request->validate([
            'full_name' => 'required|string|max:200',
            'phone' => 'nullable|string|max:20',
            'dob' => 'nullable|date',
            'email_notif' => 'nullable|boolean',
            'wa_notif' => 'nullable|boolean',
            'photo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        $user = $request->user();
        $data = $request->only(['full_name', 'phone', 'dob', 'email_notif', 'wa_notif']);

        if ($request->hasFile('photo')) {
            // Delete old photo if exists and is a local file (not a URL)
            if ($user->photo && !filter_var($user->photo, FILTER_VALIDATE_URL)) {
                Storage::disk('public')->delete($user->photo);
            }
            $data['photo'] = $request->file('photo')->store('avatars', 'public');
        }

        $user->update($data);
        ActivityLog::record('update', 'Update profil');

        return $this->success($user->fresh(), 'Profil berhasil diperbarui');
    }

    public function setupProfile(Request $request)
    {
        $user = $request->user();

        if ($user->profile_completed) {
            return $this->error('Profil sudah lengkap.', 400);
        }

        $request->validate([
            'username' => 'required|string|max:100|unique:users,username,' . $user->id,
            'full_name' => 'required|string|max:200',
            'email' => 'required|email|max:255|unique:users,email,' . $user->id,
            'no_ktp' => 'required|string|max:50|min:16',
            'phone' => 'nullable|string|max:20',
            'dob' => 'nullable|date',
            'role' => 'required|in:customer,owner',
            'tenant_name' => 'required_if:role,owner|nullable|string|max:200',
            'password' => 'nullable|string|min:8|confirmed',
        ]);

        $companyCode = 'UNIV';
        $tenant = null;

        DB::transaction(function () use ($request, $user, &$companyCode, &$tenant) {
            if ($request->role === 'owner') {
                $companyCode = $this->generateCompanyCode($request->tenant_name);

                // New tenants get a 2-day free trial automatically
                $tenant = Tenant::create([
                    'user_id' => $user->id,
                    'tenant_name' => $request->tenant_name,
                    'slug' => Str::slug($request->tenant_name) . '-' . time(),
                    'company_code' => $companyCode,
                    'status' => 1,
                    'is_deleted' => 0,
                    'trial_ends_at' => now()->addDays(2),
                ]);

                Log::info("setupProfile: Tenant created", [
                    'tenant_id' => $tenant->id,
                    'user_id' => $user->id,
                    'tenant_name' => $tenant->tenant_name,
                ]);
            }

            $updateData = [
                'username' => $request->username,
                'full_name' => $request->full_name,
                'name' => $request->full_name,
                'email' => $request->email,
                'no_ktp' => $request->no_ktp,
                'phone' => $request->phone,
                'dob' => $request->dob,
                'role' => $request->role,
                'company_code' => $companyCode,
                'profile_completed' => true,
            ];

            // Only update password if provided (Google users need to set one, regular users can skip)
            if ($request->filled('password')) {
                $updateData['password'] = Hash::make($request->password);
            }

            $user->update($updateData);
        });

        ActivityLog::record('update', 'Setup profil berhasil');

        // Reload user with all relationships (same as login response)
        $freshUser = User::where('id', $user->id)
            ->with(['tenant', 'assignedRole'])
            ->first();

        $freshUser->computed_permissions = $freshUser->getAllPermissions()->pluck('slug');

        // For owner: send company code email
        if ($request->role === 'owner' && $freshUser->tenant) {
            try {
                Mail::to($freshUser->email)->send(
                    new \App\Mail\TenantRegisteredMail($freshUser, $freshUser->tenant, $companyCode)
                );
            } catch (\Throwable $e) {
                Log::warning('Failed to send tenant registration email: ' . $e->getMessage());
            }
        }

        $responseData = $freshUser->toArray();
        $responseData['company_code'] = $companyCode;

        return $this->success($responseData, 'Setup profil berhasil disimpan');
    }

    private function generateCompanyCode(string $tenantName): string
    {
        $words = explode(' ', trim($tenantName));
        $code = '';
        foreach ($words as $word) {
            if (strlen($word) > 0) {
                // Ambil 1 huruf depan saja
                $code .= strtoupper(substr($word, 0, 1));
            }
        }

        // Pelindung jika nama kosong atau karakter aneh
        if (empty($code)) {
            $code = 'KNTN';
        }

        // Limit code max length if extremely long, e.g., 20 chars
        if (strlen($code) > 20) {
            $code = substr($code, 0, 20);
        }

        // Check uniqueness
        $originalCode = $code;
        while (Tenant::where('company_code', $code)->exists()) {
            // Jika ada yang sama, tambahkan angka acak di belakangnya
            $code = $originalCode . rand(1, 99);
        }

        return $code;
    }

    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required',
            'password' => 'required|min:8|confirmed',
            'password_confirmation' => 'required',
        ]);

        if (!Hash::check($request->current_password, $request->user()->password)) {
            return $this->error('Password lama tidak sesuai.', 422);
        }

        $request->user()->update(['password' => Hash::make($request->password)]);
        ActivityLog::record('update', 'Ganti password');

        return $this->success(null, 'Password berhasil diubah');
    }

    public function redirectToGoogle()
    {
        /** @var \Laravel\Socialite\Two\GoogleProvider $driver */
        $driver = Socialite::driver('google');
        return $driver->stateless()->redirect();
    }

    public function handleGoogleCallback()
    {
        $frontend = config('app.frontend_url', 'http://localhost:5173');

        try {
            /** @var \Laravel\Socialite\Two\GoogleProvider $driver */
            $driver = Socialite::driver('google');
            $googleUser = $driver->stateless()->user();

            $user = User::where('email', $googleUser->getEmail())->first();

            if ($user) {
                if ($user->is_deleted || !$user->status) {
                    return redirect($frontend . '/login?error=Akun+Anda+telah+dinonaktifkan');
                }
                // Update google_id, photo
                $user->update([
                    'google_id' => $googleUser->getId(),
                    'photo' => $googleUser->getAvatar(),
                ]);
            } else {
                // Generate a unique username
                $baseUsername = Str::slug(Str::before($googleUser->getEmail(), '@'));
                $username = $baseUsername;
                $counter = 1;
                while (User::where('username', $username)->exists()) {
                    $username = $baseUsername . $counter;
                    $counter++;
                }

                // Create new user with profile_completed = false
                $user = User::create([
                    'name' => $googleUser->getName() ?? $username,
                    'full_name' => $googleUser->getName() ?? $username,
                    'username' => $username,
                    'email' => $googleUser->getEmail(),
                    'google_id' => $googleUser->getId(),
                    'photo' => $googleUser->getAvatar(),
                    'password' => Hash::make(Str::random(24)),
                    'role' => 'customer',
                    'company_code' => 'UNIV',
                    'created_by' => 'System',
                    'updated_by' => 'System',
                    'profile_completed' => false,
                    'status' => 1,
                ]);
            }

            if (!$user->status) {
                return redirect($frontend . '/login?error=Akun+dinonaktifkan');
            }

            // ── 2FA OTP Logic ─────────────────────────────
            $otp = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
            $intentKey = Str::random(40);

            // Simpan OTP di Cache selama 10 menit
            Cache::put("otp_intent:{$intentKey}", [
                'user_id' => $user->id,
                'email' => $user->email,
                'otp' => $otp
            ], now()->addMinutes(10));

            // Kirim Email (menggunakan send secara sinkronous agar terkirim tanpa antrean/queue worker)
            try {
                Mail::to($user->email)->send(new OtpMail($user, $otp));
            } catch (\Throwable $e) {
                Log::error('Failed to send OTP email: ' . $e->getMessage());
                // Tetap lanjut, user bisa cek log jika mode log aktif
            }

            ActivityLog::record('login_attempt', "OTP dikirim ke: {$user->email}", $user->id);

            // Redirect ke halaman verifikasi OTP di frontend
            return redirect($frontend . '/auth/otp?email=' . urlencode($user->email) . '&intent=' . $intentKey);

        } catch (\Exception $e) {
            Log::error('Google Login Error: ' . $e->getMessage());
            return redirect($frontend . '/login?error=Otentikasi+Google+Gagal');
        }
    }

    public function verifyGoogleOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'intent' => 'required|string',
            'otp' => 'required|string|size:6',
        ]);

        $cached = Cache::get("otp_intent:{$request->intent}");

        if (!$cached || $cached['email'] !== $request->email) {
            return $this->error('Sesi verifikasi kadaluarsa atau tidak valid.', 422);
        }

        if ($cached['otp'] !== $request->otp) {
            return $this->error('Kode OTP yang Anda masukkan salah.', 422);
        }

        // OTP Valid -> Selesaikan Login
        $user = User::findOrFail($cached['user_id']);
        $user->load(['tenant', 'assignedRole']);
        $user->computed_permissions = $user->getAllPermissions()->pluck('slug');

        // Hapus Cache setelah berhasil
        Cache::forget("otp_intent:{$request->intent}");

        $token = $user->createToken('auth_token')->plainTextToken;
        ActivityLog::record('login', "Login Google 2FA Berhasil: {$user->email}", $user->id);

        return $this->success([
            'user' => $user,
            'token' => $token
        ], 'Verifikasi berhasil');
    }

    public function resendOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'intent' => 'required|string',
        ]);

        // Get cached OTP data
        $cached = Cache::get("otp_intent:{$request->intent}");

        if (!$cached || $cached['email'] !== $request->email) {
            return $this->error('Sesi verifikasi kadaluarsa atau tidak valid.', 422);
        }

        // Generate new OTP
        $otp = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);

        // Update cache with new OTP
        Cache::put("otp_intent:{$request->intent}", [
            'user_id' => $cached['user_id'],
            'email' => $request->email,
            'otp' => $otp
        ], now()->addMinutes(10));

        // Send new OTP email
        try {
            $user = User::findOrFail($cached['user_id']);
            Mail::to($user->email)->send(new OtpMail($user, $otp));
            ActivityLog::record('login_attempt', "OTP dikirim ulang ke: {$user->email}", $user->id);
            
            return $this->success(null, 'Kode OTP baru telah dikirim ke email Anda.');
        } catch (\Throwable $e) {
            Log::error('Failed to resend OTP email: ' . $e->getMessage());
            return $this->error('Gagal mengirim ulang OTP. Silakan coba lagi nanti.', 500);
        }
    }

    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email|exists:users,email']);

        $user = User::where('email', $request->email)->first();
        if (!$user->status || $user->is_deleted) {
            return $this->error('Akun tidak ditemukan atau tidak aktif.', 422);
        }

        // Generate token (simple random string or numeric for ease of use)
        $token = strtoupper(Str::random(8));

        // Save to password_reset_tokens
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            [
                'token' => Hash::make($token),
                'created_at' => Carbon::now()
            ]
        );

        // Send Email
        try {
            Mail::to($user->email)->send(new ResetPasswordMail($user, $token));
            ActivityLog::record('forgot_password', "Request reset password untuk: {$user->email}", $user->id);
            return $this->success(null, 'Instruksi reset password telah dikirim ke email Anda.');
        } catch (\Throwable $e) {
            Log::error('Forgot Password Email Error: ' . $e->getMessage());
            return $this->error('Gagal mengirim email reset password. Coba lagi nanti.', 500);
        }
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
            'token' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $record = DB::table('password_reset_tokens')->where('email', $request->email)->first();

        if (!$record || !Hash::check($request->token, $record->token)) {
            return $this->error('Token reset password tidak valid atau sudah kadaluarsa.', 422);
        }

        // Check expiry (60 minutes)
        if (Carbon::parse($record->created_at)->addMinutes(60)->isPast()) {
            return $this->error('Token reset password sudah kadaluarsa.', 422);
        }

        $user = User::where('email', $request->email)->first();
        $user->update(['password' => Hash::make($request->password)]);

        // Delete token
        DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        ActivityLog::record('reset_password', "Reset password berhasil: {$user->email}", $user->id);

        return $this->success(null, 'Password Anda berhasil diubah. Silakan login kembali.');
    }

    public function redirectToGoogleGmail()
    {
        $redirectUri = rtrim(config('app.url', 'http://localhost:8000'), '/') . '/api/v1/auth/google/gmail-callback';
        
        $queries = http_build_query([
            'client_id'     => config('services.google.client_id'),
            'redirect_uri'  => $redirectUri,
            'scope'         => 'https://www.googleapis.com/auth/gmail.send',
            'response_type' => 'code',
            'access_type'   => 'offline',
            'prompt'        => 'consent'
        ]);

        return redirect('https://accounts.google.com/o/oauth2/v2/auth?' . $queries);
    }

    public function handleGoogleGmailCallback(Request $request)
    {
        $code = $request->query('code');
        if (!$code) {
            return response('No authorization code provided.', 400);
        }

        $redirectUri = rtrim(config('app.url', 'http://localhost:8000'), '/') . '/api/v1/auth/google/gmail-callback';

        $response = Http::post('https://oauth2.googleapis.com/token', [
            'client_id'     => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri'  => $redirectUri,
            'code'          => $code,
            'grant_type'    => 'authorization_code',
        ]);

        if ($response->failed()) {
            return response()->json([
                'error' => 'Failed to obtain refresh token.',
                'details' => $response->json()
            ], 400);
        }

        $data = $response->json();
        $refreshToken = $data['refresh_token'] ?? null;

        if (!$refreshToken) {
            return response('No refresh token returned. Make sure to remove app access from your Google Account and try again (or ensure prompt=consent is active).', 400);
        }

        return "
            <html>
            <head>
                <title>Google Gmail Refresh Token</title>
                <style>
                    body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding: 40px; background: #f3f4f6; color: #1f2937; line-height: 1.6; }
                    .card { background: white; padding: 32px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); max-width: 600px; margin: 0 auto; }
                    h2 { color: #10b981; margin-top: 0; }
                    code { background: #f3f4f6; padding: 8px 12px; border-radius: 6px; display: block; word-break: break-all; margin: 16px 0; font-family: monospace; font-size: 14px; border: 1px solid #e5e7eb; }
                    .instruction { font-size: 14px; color: #4b5563; }
                </style>
            </head>
            <body>
                <div class='card'>
                    <h2>Google Gmail API Authorization Successful!</h2>
                    <p>Copy this Refresh Token and add it to your Railway variables as <code>GOOGLE_REFRESH_TOKEN</code>:</p>
                    <code>{$refreshToken}</code>
                    <div class='instruction'>
                        <p><strong>Step-by-step:</strong></p>
                        <ol>
                            <li>Copy the token above.</li>
                            <li>Go to your Railway Dashboard.</li>
                            <li>Add variable: <code>GOOGLE_REFRESH_TOKEN</code> = <i>[paste token]</i></li>
                            <li>Change <code>MAIL_MAILER</code> to <code>gmail-api</code>.</li>
                            <li>You're all set! All emails will now be sent securely via Gmail API.</li>
                        </ol>
                    </div>
                </div>
            </body>
            </html>
        ";
    }

    /**
     * Debug-only callback: returns raw Google OAuth code as JSON.
     * Useful to verify Railway callback URL is reachable.
     * Route: GET /auth/google/callback  (web.php)
     */
    public function handleGoogleCallbackDebug(Request $request)
    {
        $code  = $request->input('code');
        $error = $request->input('error');

        if ($error) {
            return response()->json([
                'message' => 'Google OAuth ditolak / dibatalkan',
                'error'   => $error,
            ], 400);
        }

        return response()->json([
            'message' => '✅ Callback masuk dengan sukses!',
            'code'    => $code ? substr($code, 0, 20) . '...' : null,
            'hint'    => 'Jika code tidak null, berarti Google Redirect URI sudah benar.',
        ]);
    }

    /**
     * Endpoint untuk mendiagnosis error email secara real-time.
     * Route: GET /api/v1/auth/test-email?email=emailanda@gmail.com
     */
    public function testEmail(Request $request)
    {
        $targetEmail = $request->query('email', 'pangestu5711@gmail.com');
        $results = [];

        // Ambil konfigurasi saat ini untuk ditampilkan
        $results['config'] = [
            'default_mailer' => config('mail.default'),
            'from_address'   => config('mail.from.address'),
            'smtp_host'      => config('mail.mailers.smtp.host'),
            'smtp_port'      => config('mail.mailers.smtp.port'),
            'smtp_scheme'    => config('mail.mailers.smtp.scheme'),
        ];

        // Jalankan test kirim
        try {
            Mail::raw('Ini adalah email uji coba koneksi dari backend KantinKita.', function ($message) use ($targetEmail) {
                $message->to($targetEmail)
                    ->subject('Test Koneksi Mailer KantinKita');
            });
            $results['send_result'] = '✅ SUKSES! Email berhasil terkirim ke ' . $targetEmail;
        } catch (\Exception $e) {
            $results['send_result'] = '❌ GAGAL!';
            $results['error_message'] = $e->getMessage();
            $results['error_trace'] = substr($e->getTraceAsString(), 0, 500) . '...';
        }

        return response()->json($results);
    }
}
