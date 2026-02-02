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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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
            <h1>Application Received</h1>
        </div>
        <div class="content">
            <p>Hi <strong>{{ $teacherName }}</strong>,</p>
            
            <p>Thank you for applying to become a teacher with us!</p>
            
            <p>Your application has been successfully received and is now under review by our admin team.</p>
            
            <p>We will notify you via email once your application has been reviewed.</p>
            
            <p>If you have any questions in the meantime, please don't hesitate to contact us.</p>
            
            <p>Best regards,<br>
            <strong>The Team</strong></p>
        </div>
        <div class="footer">
            <p>This is an automated message. Please do not reply to this email.</p>
        </div>
    </div>
</body>
</html>
