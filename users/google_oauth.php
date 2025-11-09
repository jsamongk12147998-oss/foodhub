    <?php
    // users/google_oauth.php

    require_once __DIR__ . '/../vendor/autoload.php';
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../db/db.php';
    require_once __DIR__ . '/../config.php';

    use PHPMailer\PHPMailer\PHPMailer;
    use PHPMailer\PHPMailer\SMTP;
    use PHPMailer\PHPMailer\Exception;

    session_start();

    function sendOTPEmail($email, $name, $otp) {
        $mail = new PHPMailer(true);
        
        try {
            // Enable verbose debugging (remove in production)
            $mail->SMTPDebug = SMTP::DEBUG_SERVER;
            
            // Server settings
            $mail->isSMTP();
            $mail->Host = SMTP_HOST;
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USERNAME;
            $mail->Password = SMTP_PASSWORD;
            $mail->SMTPSecure = constant('PHPMailer::ENCRYPTION_' . strtoupper(SMTP_SECURE));
            $mail->Port = SMTP_PORT;

            // Recipients
            $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
            $mail->addAddress($email, $name);

            // Content
            $mail->isHTML(true);
            $mail->Subject = 'Verify Your Account - OTP Required';
            
            $mail_template = '<!DOCTYPE html>
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; }
            .container { background: white; padding: 30px; border-radius: 10px; max-width: 600px; margin: 0 auto; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
            .header { background: linear-gradient(15deg, #0d1abc, #ffcc00); color: white; padding: 20px; text-align: center; border-radius: 10px; margin-bottom: 20px; }
            .otp-code { background: #0d1abc; color: #ffcc00; padding: 20px; font-size: 32px; font-weight: bold; text-align: center; letter-spacing: 10px; border-radius: 5px; margin: 20px 0; }
            .instructions { background: #e7e7ef; padding: 15px; border-radius: 10px; margin: 20px 0; }
            .footer { margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd; color: #666; text-align: center; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>Verify Your Account</h1>
            </div>
            <p>Hello ' . htmlspecialchars($name) . ',</p>
            <p>Please use the following OTP code to verify your account:</p>
            <div class="otp-code">' . $otp . '</div>
            <div class="instructions">
                <p><strong>Instructions:</strong></p>
                <p>Enter this code in the verification field to complete your account setup.</p>
                <p>This code will expire in 10 minutes.</p>
            </div>
            <div class="footer">
                <p>If you didn\'t request this verification, please ignore this email.</p>
            </div>
        </div>
    </body>
    </html>';
            
            $mail->Body = $mail_template;
            $mail->AltBody = "Hello $name,\n\nYour OTP verification code is: $otp\n\nThis code will expire in 10 minutes.\n\nIf you didn't request this, please ignore this email.";

            $mail->send();
            return ['success' => true, 'message' => 'Email sent successfully'];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => "Email could not be sent. Error: {$mail->ErrorInfo}"];
        }
    }

    // Test function to verify SMTP configuration
    function testSMTPConfiguration() {
        $mail = new PHPMailer(true);
        
        try {
            $mail->isSMTP();
            $mail->Host = SMTP_HOST;
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USERNAME;
            $mail->Password = SMTP_PASSWORD;
            $mail->SMTPSecure = constant('PHPMailer::ENCRYPTION_' . strtoupper(SMTP_SECURE));
            $mail->Port = SMTP_PORT;
            $mail->Timeout = 10;
            
            // Test connection
            if (!$mail->smtpConnect()) {
                throw new Exception('Failed to connect to SMTP server');
            }
            $mail->smtpClose();
            
            return ['success' => true, 'message' => 'SMTP configuration is correct'];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'SMTP Error: ' . $e->getMessage()];
        }
    }
    // ✅ Generate 6-digit OTP
    function generateOTP() {
        return sprintf("%06d", mt_rand(1, 999999));
    }

    // ✅ Update user OTP
    function updateUserOTP($email, $otp, $otp_expires, $conn) {
        $sql = "UPDATE users SET verification_otp = ?, otp_expires_at = ? WHERE email = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sss", $otp, $otp_expires, $email);
        return $stmt->execute();
    }

    // ✅ Helper function to get user
    function getUserByEmail($email, $conn) {
        $sql = "SELECT id, name, email, password, role, email_verified FROM users WHERE email = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }

    // ✅ Initialize Google Client
    $client = new Google_Client();
    $client->setClientId('47485666333-ujg373qqjsr40rnd3jnskq2idgq4472a.apps.googleusercontent.com');
    $client->setClientSecret('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');
    $client->setRedirectUri('http://localhost/test/users/google_oauth.php');
    $client->addScope('email');
    $client->addScope('profile');

    // ✅ Show only UMAK accounts on the Google chooser
    $client->setHostedDomain('umak.edu.ph');

    // ✅ Step 1: Redirect to Google OAuth consent screen
    if (!isset($_GET['code'])) {
        $authUrl = $client->createAuthUrl();
        header('Location: ' . filter_var($authUrl, FILTER_SANITIZE_URL));
        exit();
    }

    try {
        // ✅ Step 2: Exchange authorization code for access token
        $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
        if (isset($token['error'])) {
            throw new Exception($token['error_description'] ?? 'OAuth error');
        }

        $client->setAccessToken($token);

        // ✅ Step 3: Retrieve user info
        $googleService = new Google_Service_Oauth2($client);
        $userInfo = $googleService->userinfo->get();

        $email = $userInfo->email;
        $name  = $userInfo->name;
        $hd    = property_exists($userInfo, 'hd') ? $userInfo->hd : null;

        // ✅ Enforce UMAK email restriction
        if ($hd !== 'umak.edu.ph' && !preg_match('/@umak\.edu\.ph$/i', $email)) {
            $_SESSION['error'] = "Only University of Makati accounts (@umak.edu.ph) can register.";
            header("Location: signup.php");
            exit();
        }

        // ✅ Check if user already exists
        $user = getUserByEmail($email, $conn);
        
        if ($user) {
            // User exists - check if verified
            if ($user['email_verified'] == 1) {
                // ✅ Verified user - log them in
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['email'] = $email;
                header("Location: ../users/UserInt.php");
                exit();
            } else {
                // ❌ User exists but not verified - send new OTP
                $otp = generateOTP();
                $otp_expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));
                
                updateUserOTP($email, $otp, $otp_expires, $conn);
                
                // Send OTP email
                if (sendOTPEmail($email, $name, $otp)) {
                    $_SESSION['verify_email'] = $email;
                    $_SESSION['verify_name'] = $name;
                    $_SESSION['success'] = "We've sent a new verification code to your UMAK email.";
                    header("Location: verify_otp.php");
                    exit();
                } else {
                    $_SESSION['verify_email'] = $email;
                    $_SESSION['verify_name'] = $name;
                    $_SESSION['warning'] = "Account found but email delivery failed. Please use manual verification.";
                    header("Location: verify_otp.php");
                    exit();
                }
            }
        }

        // ✅ Register new UMAK user and send OTP
        $otp = generateOTP();
        $otp_expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));
        $role = "user";
        $randomPassword = bin2hex(random_bytes(8));
        $hashedPassword = password_hash($randomPassword, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("INSERT INTO users (name, email, password, role, verification_otp, otp_expires_at, email_verified) VALUES (?, ?, ?, ?, ?, ?, 0)");
        $stmt->bind_param("ssssss", $name, $email, $hashedPassword, $role, $otp, $otp_expires);
        
        if ($stmt->execute()) {
            // ✅ Send OTP email using Gmail SMTP
            if (sendOTPEmail($email, $name, $otp)) {
                $_SESSION['verify_email'] = $email;
                $_SESSION['verify_name'] = $name;
                $_SESSION['success'] = "Registration successful! We've sent a verification code to your UMAK email.";
                header("Location: verify_otp.php");
                exit();
            } else {
                // If email fails, still redirect to OTP page but show manual option
                $_SESSION['verify_email'] = $email;
                $_SESSION['verify_name'] = $name;
                $_SESSION['warning'] = "Registration successful! If you don't receive the email within 2 minutes, use the manual verification option.";
                header("Location: verify_otp.php");
                exit();
            }
        } else {
            throw new Exception("Failed to create user account.");
        }

    } catch (Exception $e) {
        $_SESSION['error'] = "Google registration failed: " . $e->getMessage();
        header("Location: signup.php");
        exit();
    }
    ?>