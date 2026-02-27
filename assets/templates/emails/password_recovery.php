<?php
// templates/emails/password_recovery.php
?>
<h2 style='color: #333333; margin: 0 0 20px 0; font-size: 22px;'>Password Recovery Request</h2>
<p style='color: #555555; line-height: 1.6;'>
    Hello <strong><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></strong>,
</p>
<p style='color: #555555; line-height: 1.6;'>
    We received a request to reset your password. Use the verification code below to proceed:
</p>

<table role='presentation' style='width: 100%; margin: 30px 0;'>
    <tr>
        <td align='center' style='background-color: #f8f9fa; border: 2px dashed #2a5298; border-radius: 6px; padding: 25px;'>
            <p style='margin: 0 0 10px 0; color: #666666; font-size: 13px; text-transform: uppercase;'>Your Verification Code</p>
            <p style='margin: 0; color: #1e3c72; font-size: 36px; font-weight: bold; letter-spacing: 8px;'><?php echo htmlspecialchars($code); ?></p>
        </td>
    </tr>
</table>

<table role='presentation' style='width: 100%; background-color: #fff3cd; border-left: 4px solid #ffc107; margin: 20px 0;'>
    <tr>
        <td style='padding: 15px;'>
            <p style='margin: 0; color: #856404; font-size: 14px;'>
                <strong>⚠️ Important:</strong> This code expires in <strong>5 minutes</strong>. If you did not request this, please ignore this email.
            </p>
        </td>
    </tr>
</table>