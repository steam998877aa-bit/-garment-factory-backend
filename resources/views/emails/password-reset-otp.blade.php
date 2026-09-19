<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Password reset code</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #1f2933; line-height: 1.6;">
    <h2 style="margin-bottom: 0.5rem;">Password reset code</h2>

    <p>Use the code below to reset your password:</p>

    <p style="font-size: 2rem; font-weight: bold; letter-spacing: 0.35rem; margin: 1.5rem 0;">
        {{ $otp }}
    </p>

    <p>
        This code expires in {{ $expiresInMinutes }} minutes and can only be used once.
    </p>

    <p style="color: #52606d; font-size: 0.9rem;">
        If you did not request a password reset, you can ignore this email — your
        password will not change.
    </p>
</body>
</html>
