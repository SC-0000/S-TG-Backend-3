<!DOCTYPE html>
<html>
<head>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            color: white;
            padding: 30px;
            text-align: center;
            border-radius: 10px 10px 0 0;
        }
        .content {
            background: #f9f9f9;
            padding: 30px;
            border-radius: 0 0 10px 10px;
        }
        .button {
            display: inline-block;
            padding: 12px 30px;
            background: #11998e;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            margin-top: 20px;
        }
        .footer {
            text-align: center;
            margin-top: 20px;
            color: #666;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🎉 Welcome Aboard!</h1>
        </div>
        <div class="content">
            <p>Hi <strong>{{ $teacher->name }}</strong>,</p>
            
            <p>Great news! Your teacher application has been <strong>approved</strong>.</p>
            
            <p>You can now log in to your account and start your journey as a teacher with us.</p>
            
            <p><strong>Login Details:</strong></p>
            <ul>
                <li>Email: {{ $teacher->email }}</li>
                <li>Password: The password you created during registration</li>
            </ul>
            
            <p style="text-align: center;">
                <a href="{{ route('login') }}" class="button">Login to Your Account</a>
            </p>
            
            <p>We're excited to have you on our team!</p>
            
            <p>Best regards,<br>
            <strong>The Team</strong></p>
        </div>
        <div class="footer">
            <p>This is an automated message. Please do not reply to this email.</p>
        </div>
    </div>
</body>
</html>
