<?php

namespace App\Services;

use App\Mail\OtpMail;
use App\Models\EmailOtp;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class OtpService
{
    public const RESET_PASSWORD = 'reset_password';

    public const VERIFY_EMAIL = 'verify_email';

    /** Minutos de validez del código. */
    public const TTL_MINUTES = 10;

    /** Intentos fallidos antes de que el código quede inservible. */
    public const MAX_ATTEMPTS = 3;

    /**
     * Segundos durante los que un segundo envío reutiliza el código vigente.
     *
     * Un doble click, un reenvío del proxy o cualquier ráfaga llegan en menos
     * de un segundo. Sin esta ventana la segunda petición ejecuta clear() y
     * deja sin validez el código que el usuario acaba de recibir, de modo que
     * el primer correo que leyó deja de servir.
     */
    public const IDEMPOTENCY_SECONDS = 5;

    /**
     * Genera un código nuevo y lo envía por correo.
     * Invalida cualquier código anterior del mismo propósito.
     *
     * Si hace menos de IDEMPOTENCY_SECONDS que se envió un código que sigue
     * vivo, no se genera ni se envía otro: se devuelve true como si se hubiera
     * enviado. Dos peticiones en ráfaga producen un solo correo.
     *
     * Devuelve false si el correo no pudo enviarse (SMTP caído, credenciales
     * mal, límite alcanzado...), pero NO lanza excepción: el código ya quedó
     * guardado y el usuario puede reintentar. Así un fallo de correo no rompe
     * el login ni el registro con un error 500.
     */
    public function send(string $email, string $purpose): bool
    {
        $enRafaga = EmailOtp::where('email', $email)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->where('created_at', '>=', now()->subSeconds(self::IDEMPOTENCY_SECONDS))
            ->exists();

        if ($enRafaga) {
            Log::info('Envío de código OTP omitido por ráfaga', [
                'purpose' => $purpose,
                'email' => $email,
            ]);

            return true;
        }

        $this->clear($email, $purpose);

        $code = $this->generateCode();

        EmailOtp::create([
            'email' => $email,
            'purpose' => $purpose,
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        try {
            Mail::to($email)->send(new OtpMail(
                code: $code,
                purpose: $purpose,
                ttlMinutes: self::TTL_MINUTES,
                subjectLine: $purpose === self::VERIFY_EMAIL
                    ? 'Confirma tu correo - Simulador SPGTH'
                    : 'Restablece tu contraseña - Simulador SPGTH',
            ));
        } catch (\Throwable $e) {
            Log::error('No se pudo enviar el código OTP por correo', [
                'purpose' => $purpose,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        Log::info('Código OTP enviado', [
            'purpose' => $purpose,
            'email' => $email,
        ]);

        return true;
    }

    /** Comprueba el código. Si es correcto lo consume (un solo uso). */
    public function verify(string $email, string $purpose, string $code): bool
    {
        $otp = EmailOtp::where('email', $email)->where('purpose', $purpose)->first();

        if (! $otp || ! $otp->isUsable()) {
            return false;
        }

        if (! Hash::check($code, $otp->code_hash)) {
            $this->registerFailedAttempt($otp);

            return false;
        }

        $otp->update(['consumed_at' => now()]);

        return true;
    }

    public function clear(string $email, string $purpose): void
    {
        EmailOtp::where('email', $email)->where('purpose', $purpose)->delete();
    }

    /** random_int es criptográficamente seguro; el padding garantiza 6 dígitos. */
    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function registerFailedAttempt(EmailOtp $otp): void
    {
        $attempts = $otp->attempts + 1;
        $otp->update(['attempts' => $attempts]);

        if ($attempts >= self::MAX_ATTEMPTS) {
            // Se agotaron los intentos: el código queda inservible aunque el tiempo siga vivo.
            $otp->update(['consumed_at' => now()]);
        }
    }
}
