<?php
// templates/emails/layout.php
$currentYear = date('Y');
?>
<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
</head>
<body style='margin: 0; padding: 0; font-family: -apple-system, sans-serif; background-color: #f4f7fa;'>
    <table role='presentation' style='width: 100%; border-collapse: collapse; background-color: #f4f7fa;'>
        <tr>
            <td align='center' style='padding: 40px 0;'>
                <table role='presentation' style='width: 600px; max-width: 90%; background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);'>
                    <tr>
                        <td style='padding: 0; text-align: center; border-radius: 8px 8px 0 0; overflow: hidden;'>
                            <img src='cid:header_image' alt='Sign-um System' style='width: 100%; max-width: 700px; height: auto; display: block;' />
                        </td>
                    </tr>
                    
                    <tr>
                        <td style='padding: 40px 30px;'>
                            <?php echo $content; ?>
                        </td>
                    </tr>
                    
                    <tr>
                        <td style='background-color: #f8f9fa; padding: 25px 30px; border-radius: 0 0 8px 8px; border-top: 1px solid #e9ecef;'>
                            <p style='margin: 0; color: #6c757d; font-size: 12px;'>
                                &copy; <?php echo $currentYear; ?> Sign-um System.<br>
                                Systems Plus College Foundation | Angeles City
                            </p>
                        </td>
                    </tr>
                </table>
                <table role='presentation' style='width: 600px; max-width: 90%; margin-top: 20px;'>
                    <tr>
                        <td align='center'>
                            <p style='color: #999999; font-size: 12px; line-height: 1.5; margin: 0;'>
                                This is an automated message, please do not reply to this email.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>