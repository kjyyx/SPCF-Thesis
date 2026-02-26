<?php
// includes/mailer.php

require_once __DIR__ . '/config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Ensure vendor autoload is loaded
require_once ROOT_PATH . 'vendor/autoload.php';

if (!function_exists('sendPasswordResetEmail')) {
    function sendPasswordResetEmail($userEmail, $userName, $code)
    {
        $mail = new PHPMailer(true);

        try {
            // Server settings (Pulls securely from your .env file)
            $mail->isSMTP();
            $mail->Host = $_ENV['MAIL_HOST'];
            $mail->SMTPAuth = true;
            $mail->Username = $_ENV['MAIL_USERNAME'];
            $mail->Password = $_ENV['MAIL_PASSWORD'];
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port = $_ENV['MAIL_PORT'];

            // Recipients
            $mail->setFrom($_ENV['MAIL_FROM_ADDRESS'], $_ENV['MAIL_FROM_NAME']);
            $mail->addAddress($userEmail, $userName);
            $mail->addReplyTo($_ENV['MAIL_FROM_ADDRESS'], 'Support');

            // Content
            $mail->isHTML(true);
            $mail->Subject = 'Password Recovery - Sign-um System';
            $currentYear = date('Y');

            // Embed the header image
            $mail->addEmbeddedImage(ROOT_PATH . 'assets/images/Email_background.jpg', 'header_image', 'Email_background.jpg', 'base64', 'image/jpeg');

            $mail->Body = "
            <!DOCTYPE html>
            <html lang='en'>
            <head>
                <meta charset='UTF-8'>
                <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                <title>Password Recovery</title>
            </head>
            <body style='margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, \"Helvetica Neue\", Arial, sans-serif; background-color: #f4f7fa;'>
                <table role='presentation' style='width: 100%; border-collapse: collapse; background-color: #f4f7fa;'>
                    <tr>
                        <td align='center' style='padding: 40px 0;'>
                            <table role='presentation' style='width: 600px; max-width: 90%; border-collapse: collapse; background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);'>
                                <tr>
                                    <td style='padding: 0; text-align: center; border-radius: 8px 8px 0 0; overflow: hidden;'>
                                        <img src='cid:header_image' alt='Sign-um System' style='width: 100%; max-width: 700px; height: auto; display: block; margin: 0 auto;' />
                                    </td>
                                </tr>
                                <tr>
                                    <td style='padding: 40px 30px;'>
                                        <h2 style='color: #333333; margin: 0 0 20px 0; font-size: 22px; font-weight: 600;'>Password Recovery Request</h2>
                                        <p style='color: #555555; line-height: 1.6; margin: 0 0 20px 0; font-size: 15px;'>
                                            Hello <strong>{$userName}</strong>,
                                        </p>
                                        <p style='color: #555555; line-height: 1.6; margin: 0 0 20px 0; font-size: 15px;'>
                                            We received a request to reset your password. Use the verification code below to proceed:
                                        </p>
                                        <table role='presentation' style='width: 100%; border-collapse: collapse; margin: 30px 0;'>
                                            <tr>
                                                <td align='center' style='background-color: #f8f9fa; border: 2px dashed #2a5298; border-radius: 6px; padding: 25px;'>
                                                    <p style='margin: 0 0 10px 0; color: #666666; font-size: 13px; text-transform: uppercase; letter-spacing: 1px;'>Your Verification Code</p>
                                                    <p style='margin: 0; color: #1e3c72; font-size: 36px; font-weight: bold; letter-spacing: 8px; font-family: \"Courier New\", monospace;'>{$code}</p>
                                                </td>
                                            </tr>
                                        </table>
                                        <table role='presentation' style='width: 100%; border-collapse: collapse; background-color: #fff3cd; border-left: 4px solid #ffc107; border-radius: 4px; margin: 20px 0;'>
                                            <tr>
                                                <td style='padding: 15px;'>
                                                    <p style='margin: 0; color: #856404; font-size: 14px; line-height: 1.5;'>
                                                        <strong>⚠️ Important:</strong> This code will expire in <strong>5 minutes</strong>. If you did not request this, please ignore this email.
                                                    </p>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style='background-color: #f8f9fa; padding: 25px 30px; border-radius: 0 0 8px 8px; border-top: 1px solid #e9ecef;'>
                                        <p style='margin: 0; color: #6c757d; font-size: 12px;'>
                                            &copy; {$currentYear} Sign-um System. All rights reserved.<br>
                                            Systems Plus College Foundation | Angeles City
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </body>
            </html>";

            $mail->AltBody = "Password Recovery - Sign-um System\n\nHello {$userName},\n\nWe received a request to reset your password. Your verification code is: {$code}\n\nThis code expires in 5 minutes.";

            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("Email sending failed: {$mail->ErrorInfo}");
            return false;
        }
    }
}

if (!function_exists('sendDocumentApprovedEmail')) {
    function sendDocumentApprovedEmail($userEmail, $userName, $documentTitle)
    {
        $mail = new PHPMailer(true);

        try {
            // Server settings (Pulls securely from your .env file)
            $mail->isSMTP();
            $mail->Host = $_ENV['MAIL_HOST'];
            $mail->SMTPAuth = true;
            $mail->Username = $_ENV['MAIL_USERNAME'];
            $mail->Password = $_ENV['MAIL_PASSWORD'];
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port = $_ENV['MAIL_PORT'];

            // Recipients
            $mail->setFrom($_ENV['MAIL_FROM_ADDRESS'], $_ENV['MAIL_FROM_NAME']);
            $mail->addAddress($userEmail, $userName);
            $mail->addReplyTo($_ENV['MAIL_FROM_ADDRESS'], 'Support');

            // Content
            $mail->isHTML(true);
            $mail->Subject = 'Document Approved - Sign-um System';
            $currentYear = date('Y');

            // Embed the header image
            $mail->addEmbeddedImage(ROOT_PATH . 'assets/images/Email_background.jpg', 'header_image', 'Email_background.jpg', 'base64', 'image/jpeg');

            $mail->Body = "
            <!DOCTYPE html>
            <html lang='en'>
            <head>
                <meta charset='UTF-8'>
                <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                <title>Document Approved</title>
            </head>
            <body style='margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, \"Helvetica Neue\", Arial, sans-serif; background-color: #f4f7fa;'>
                <table role='presentation' style='width: 100%; border-collapse: collapse; background-color: #f4f7fa;'>
                    <tr>
                        <td align='center' style='padding: 40px 0;'>
                            <table role='presentation' style='width: 600px; max-width: 90%; border-collapse: collapse; background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);'>
                                <tr>
                                    <td style='padding: 0; text-align: center; border-radius: 8px 8px 0 0; overflow: hidden;'>
                                        <img src='cid:header_image' alt='Sign-um System' style='width: 100%; max-width: 700px; height: auto; display: block; margin: 0 auto;' />
                                    </td>
                                </tr>
                                <tr>
                                    <td style='padding: 40px 30px;'>
                                        <h2 style='color: #333333; margin: 0 0 20px 0; font-size: 22px; font-weight: 600;'>Document Approved</h2>
                                        <p style='color: #555555; line-height: 1.6; margin: 0 0 20px 0; font-size: 15px;'>
                                            Hello <strong>{$userName}</strong>,
                                        </p>
                                        <p style='color: #555555; line-height: 1.6; margin: 0 0 20px 0; font-size: 15px;'>
                                            Great news! Your document <strong>\"{$documentTitle}\"</strong> has been approved and is now ready for download.
                                        </p>
                                        <table role='presentation' style='width: 100%; border-collapse: collapse; margin: 30px 0;'>
                                            <tr>
                                                <td align='center' style='background-color: #d4edda; border: 2px solid #28a745; border-radius: 6px; padding: 25px;'>
                                                    <p style='margin: 0 0 10px 0; color: #155724; font-size: 13px; text-transform: uppercase; letter-spacing: 1px;'>Approval Status</p>
                                                    <p style='margin: 0; color: #155724; font-size: 24px; font-weight: bold;'>✅ APPROVED</p>
                                                </td>
                                            </tr>
                                        </table>
                                        <p style='color: #555555; line-height: 1.6; margin: 0 0 20px 0; font-size: 15px;'>
                                            You can now download your approved document from the Sign-um System dashboard.
                                        </p>
                                        <table role='presentation' style='width: 100%; border-collapse: collapse; background-color: #e7f3ff; border-left: 4px solid #007bff; border-radius: 4px; margin: 20px 0;'>
                                            <tr>
                                                <td style='padding: 15px;'>
                                                    <p style='margin: 0; color: #004085; font-size: 14px; line-height: 1.5;'>
                                                        <strong>📋 Next Steps:</strong> Log in to your account to download the document and view approval details.
                                                    </p>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style='background-color: #f8f9fa; padding: 25px 30px; border-radius: 0 0 8px 8px; border-top: 1px solid #e9ecef;'>
                                        <p style='margin: 0; color: #6c757d; font-size: 12px;'>
                                            &copy; {$currentYear} Sign-um System. All rights reserved.<br>
                                            Systems Plus College Foundation | Angeles City
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </body>
            </html>";

            $mail->AltBody = "Document Approved - Sign-um System\n\nHello {$userName},\n\nYour document \"{$documentTitle}\" has been approved and is ready for download.\n\nPlease log in to your account to download the document.";

            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("Document approved email sending failed: {$mail->ErrorInfo}");
            return false;
        }
    }
}

if (!function_exists('sendDocumentAssignedEmail')) {
    function sendDocumentAssignedEmail($userEmail, $userName, $documentTitle)
    {
        $mail = new PHPMailer(true);

        try {
            // Server settings (Pulls securely from your .env file)
            $mail->isSMTP();
            $mail->Host = $_ENV['MAIL_HOST'];
            $mail->SMTPAuth = true;
            $mail->Username = $_ENV['MAIL_USERNAME'];
            $mail->Password = $_ENV['MAIL_PASSWORD'];
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port = $_ENV['MAIL_PORT'];

            // Recipients
            $mail->setFrom($_ENV['MAIL_FROM_ADDRESS'], $_ENV['MAIL_FROM_NAME']);
            $mail->addAddress($userEmail, $userName);
            $mail->addReplyTo($_ENV['MAIL_FROM_ADDRESS'], 'Support');

            // Content
            $mail->isHTML(true);
            $mail->Subject = 'Document Assigned for Review - Sign-um System';
            $currentYear = date('Y');

            // Embed the header image
            $mail->addEmbeddedImage(ROOT_PATH . 'assets/images/Email_background.jpg', 'header_image', 'Email_background.jpg', 'base64', 'image/jpeg');

            $mail->Body = "
            <!DOCTYPE html>
            <html lang='en'>
            <head>
                <meta charset='UTF-8'>
                <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                <title>Document Assigned</title>
            </head>
            <body style='margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, \"Helvetica Neue\", Arial, sans-serif; background-color: #f4f7fa;'>
                <table role='presentation' style='width: 100%; border-collapse: collapse; background-color: #f4f7fa;'>
                    <tr>
                        <td align='center' style='padding: 40px 0;'>
                            <table role='presentation' style='width: 600px; max-width: 90%; border-collapse: collapse; background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);'>
                                <tr>
                                    <td style='padding: 0; text-align: center; border-radius: 8px 8px 0 0; overflow: hidden;'>
                                        <img src='cid:header_image' alt='Sign-um System' style='width: 100%; max-width: 700px; height: auto; display: block; margin: 0 auto;' />
                                    </td>
                                </tr>
                                <tr>
                                    <td style='padding: 40px 30px;'>
                                        <h2 style='color: #333333; margin: 0 0 20px 0; font-size: 22px; font-weight: 600;'>Document Assigned for Review</h2>
                                        <p style='color: #555555; line-height: 1.6; margin: 0 0 20px 0; font-size: 15px;'>
                                            Hello <strong>{$userName}</strong>,
                                        </p>
                                        <p style='color: #555555; line-height: 1.6; margin: 0 0 20px 0; font-size: 15px;'>
                                            A new document has been assigned to you for review: <strong>\"{$documentTitle}\"</strong>.
                                        </p>
                                        <table role='presentation' style='width: 100%; border-collapse: collapse; margin: 30px 0;'>
                                            <tr>
                                                <td align='center' style='background-color: #fff3cd; border: 2px solid #ffc107; border-radius: 6px; padding: 25px;'>
                                                    <p style='margin: 0 0 10px 0; color: #856404; font-size: 13px; text-transform: uppercase; letter-spacing: 1px;'>Action Required</p>
                                                    <p style='margin: 0; color: #856404; font-size: 24px; font-weight: bold;'>📋 REVIEW NEEDED</p>
                                                </td>
                                            </tr>
                                        </table>
                                        <p style='color: #555555; line-height: 1.6; margin: 0 0 20px 0; font-size: 15px;'>
                                            Please log in to the Sign-um System to review and take action on this document.
                                        </p>
                                        <table role='presentation' style='width: 100%; border-collapse: collapse; background-color: #e7f3ff; border-left: 4px solid #007bff; border-radius: 4px; margin: 20px 0;'>
                                            <tr>
                                                <td style='padding: 15px;'>
                                                    <p style='margin: 0; color: #004085; font-size: 14px; line-height: 1.5;'>
                                                        <strong>⏰ Deadline:</strong> Please review this document promptly to avoid delays in the approval process.
                                                    </p>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style='background-color: #f8f9fa; padding: 25px 30px; border-radius: 0 0 8px 8px; border-top: 1px solid #e9ecef;'>
                                        <p style='margin: 0; color: #6c757d; font-size: 12px;'>
                                            &copy; {$currentYear} Sign-um System. All rights reserved.<br>
                                            Systems Plus College Foundation | Angeles City
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </body>
            </html>";

            $mail->AltBody = "Document Assigned for Review - Sign-um System\n\nHello {$userName},\n\nA new document \"{$documentTitle}\" has been assigned to you for review.\n\nPlease log in to the system to review and take action on this document.";

            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("Document assigned email sending failed: {$mail->ErrorInfo}");
            return false;
        }
    }

    /**
     * Send reminder email for documents approaching timeout
     */
    function sendDocumentReminderEmail($userEmail, $userName, $documentTitle, $daysRemaining)
    {
        $mail = new PHPMailer(true);

        try {
            // Server settings (Pulls securely from your .env file)
            $mail->isSMTP();
            $mail->Host = $_ENV['MAIL_HOST'];
            $mail->SMTPAuth = true;
            $mail->Username = $_ENV['MAIL_USERNAME'];
            $mail->Password = $_ENV['MAIL_PASSWORD'];
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port = $_ENV['MAIL_PORT'];

            // Recipients
            $mail->setFrom($_ENV['MAIL_FROM_ADDRESS'], $_ENV['MAIL_FROM_NAME']);
            $mail->addAddress($userEmail, $userName);
            $mail->addReplyTo($_ENV['MAIL_FROM_ADDRESS'], 'Support');

            // Content
            $mail->isHTML(true);
            $mail->Subject = "⏰ Reminder: Document Review Due Soon - {$documentTitle}";
            $currentYear = date('Y');

            // Embed the header image
            $mail->addEmbeddedImage(ROOT_PATH . 'assets/images/Email_background.jpg', 'header_image', 'Email_background.jpg', 'base64', 'image/jpeg');

            $urgencyColor = $daysRemaining <= 3 ? '#dc3545' : '#ffc107'; // Red for 3 days, yellow for 7 days
            $urgencyText = $daysRemaining <= 3 ? 'URGENT' : 'REMINDER';

            $mail->Body = "
            <!DOCTYPE html>
            <html lang='en'>
            <head>
                <meta charset='UTF-8'>
                <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                <title>Document Review Reminder</title>
            </head>
            <body style='margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, \"Helvetica Neue\", Arial, sans-serif; background-color: #f4f7fa;'>
                <table role='presentation' style='width: 100%; border-collapse: collapse; background-color: #f4f7fa;'>
                    <tr>
                        <td align='center' style='padding: 40px 0;'>
                            <table role='presentation' style='width: 600px; max-width: 90%; border-collapse: collapse; background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);'>
                                <tr>
                                    <td style='background: linear-gradient(135deg, {$urgencyColor} 0%, " . ($daysRemaining <= 3 ? '#c82333' : '#e0a800') . " 100%); padding: 30px; border-radius: 8px 8px 0 0; text-align: center;'>
                                        <img src='cid:header_image' alt='Sign-um System' style='max-width: 100%; height: auto; border-radius: 4px; margin-bottom: 20px;'>
                                        <h1 style='color: #ffffff; margin: 0; font-size: 28px; font-weight: bold; text-shadow: 0 1px 2px rgba(0,0,0,0.3);'>📋 DOCUMENT REVIEW REMINDER</h1>
                                    </td>
                                </tr>
                                <tr>
                                    <td style='padding: 40px 30px;'>
                                        <p style='margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;'>Hello <strong>{$userName}</strong>,</p>

                                        <p style='color: #555555; line-height: 1.6; margin: 0 0 25px 0; font-size: 15px;'>
                                            This is a reminder that you have a document awaiting your review in the Sign-um System.
                                        </p>

                                        <table role='presentation' style='width: 100%; border-collapse: collapse; background-color: #f8f9fa; border-radius: 6px; margin: 25px 0; border-left: 4px solid {$urgencyColor};'>
                                            <tr>
                                                <td style='padding: 20px;'>
                                                    <h3 style='margin: 0 0 10px 0; color: #333333; font-size: 18px;'>📄 Document Details</h3>
                                                    <p style='margin: 0 0 8px 0; color: #555555;'><strong>Title:</strong> {$documentTitle}</p>
                                                    <p style='margin: 0 0 8px 0; color: #555555;'><strong>Status:</strong> Awaiting Your Review</p>
                                                    <p style='margin: 0; color: #555555;'><strong>Days Remaining:</strong> {$daysRemaining} day" . ($daysRemaining != 1 ? 's' : '') . "</p>
                                                </td>
                                            </tr>
                                        </table>

                                        <table role='presentation' style='width: 100%; border-collapse: collapse; margin: 30px 0;'>
                                            <tr>
                                                <td align='center' style='background-color: {$urgencyColor}; border-radius: 6px; padding: 15px;'>
                                                    <p style='margin: 0; color: #ffffff; font-size: 18px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px;'>⚠️ {$urgencyText} ACTION REQUIRED</p>
                                                </td>
                                            </tr>
                                        </table>

                                        <p style='color: #555555; line-height: 1.6; margin: 0 0 20px 0; font-size: 15px;'>
                                            Please log in to the Sign-um System immediately to review and take action on this document to avoid automatic rejection.
                                        </p>

                                        <table role='presentation' style='width: 100%; border-collapse: collapse; background-color: #e7f3ff; border-left: 4px solid #007bff; border-radius: 4px; margin: 20px 0;'>
                                            <tr>
                                                <td style='padding: 15px;'>
                                                    <p style='margin: 0; color: #004085; font-size: 14px; line-height: 1.5;'>
                                                        <strong>⏰ Deadline:</strong> This document will be automatically rejected if not reviewed within {$daysRemaining} day" . ($daysRemaining != 1 ? 's' : '') . ".<br>
                                                        <strong>💡 Action:</strong> Log in to review and approve/reject the document.
                                                    </p>
                                                </td>
                                            </tr>
                                        </table>

                                        <table role='presentation' style='width: 100%; border-collapse: collapse; margin: 30px 0;'>
                                            <tr>
                                                <td align='center'>
                                                    <a href='" . BASE_URL . "' style='background-color: #007bff; color: #ffffff; padding: 15px 30px; text-decoration: none; border-radius: 6px; font-weight: bold; display: inline-block; box-shadow: 0 2px 4px rgba(0,0,0,0.1);'>🔗 Access Sign-um System</a>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style='background-color: #f8f9fa; padding: 25px 30px; border-radius: 0 0 8px 8px; border-top: 1px solid #e9ecef;'>
                                        <p style='margin: 0; color: #6c757d; font-size: 12px;'>
                                            &copy; {$currentYear} Sign-um System. All rights reserved.<br>
                                            Systems Plus College Foundation | Angeles City<br>
                                            <em>This is an automated reminder. Please do not reply to this email.</em>
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </body>
            </html>
            ";

            $mail->send();
            error_log("Document reminder email sent successfully to {$userEmail} for document '{$documentTitle}' ({$daysRemaining} days remaining)");
            return true;
        } catch (Exception $e) {
            error_log("Document reminder email sending failed: {$mail->ErrorInfo}");
            return false;
        }
    }

    /**
     * Send rejection email for documents
     */
    function sendDocumentRejectedEmail($userEmail, $userName, $documentTitle, $reason)
    {
        $mail = new PHPMailer(true);

        try {
            // Server settings (Pulls securely from your .env file)
            $mail->isSMTP();
            $mail->Host = $_ENV['MAIL_HOST'];
            $mail->SMTPAuth = true;
            $mail->Username = $_ENV['MAIL_USERNAME'];
            $mail->Password = $_ENV['MAIL_PASSWORD'];
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port = $_ENV['MAIL_PORT'];

            // Recipients
            $mail->setFrom($_ENV['MAIL_FROM_ADDRESS'], $_ENV['MAIL_FROM_NAME']);
            $mail->addAddress($userEmail, $userName);
            $mail->addReplyTo($_ENV['MAIL_FROM_ADDRESS'], 'Support');

            // Content
            $mail->isHTML(true);
            $mail->Subject = "❌ Document Rejected - {$documentTitle}";
            $currentYear = date('Y');

            // Embed the header image
            $mail->addEmbeddedImage(ROOT_PATH . 'assets/images/Email_background.jpg', 'header_image', 'Email_background.jpg', 'base64', 'image/jpeg');

            $mail->Body = "
            <!DOCTYPE html>
            <html lang='en'>
            <head>
                <meta charset='UTF-8'>
                <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                <title>Document Rejected</title>
            </head>
            <body style='margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, \"Helvetica Neue\", Arial, sans-serif; background-color: #f4f7fa;'>
                <table role='presentation' style='width: 100%; border-collapse: collapse; background-color: #f4f7fa;'>
                    <tr>
                        <td align='center' style='padding: 40px 0;'>
                            <table role='presentation' style='width: 600px; max-width: 90%; border-collapse: collapse; background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);'>
                                <tr>
                                    <td style='background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); padding: 30px; border-radius: 8px 8px 0 0; text-align: center;'>
                                        <img src='cid:header_image' alt='Sign-um System' style='max-width: 100%; height: auto; border-radius: 4px; margin-bottom: 20px;'>
                                        <h1 style='color: #ffffff; margin: 0; font-size: 28px; font-weight: bold; text-shadow: 0 1px 2px rgba(0,0,0,0.3);'>❌ DOCUMENT REJECTED</h1>
                                    </td>
                                </tr>
                                <tr>
                                    <td style='padding: 40px 30px;'>
                                        <p style='margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;'>Hello <strong>{$userName}</strong>,</p>

                                        <p style='color: #555555; line-height: 1.6; margin: 0 0 25px 0; font-size: 15px;'>
                                            We regret to inform you that your document has been rejected during the approval process.
                                        </p>

                                        <table role='presentation' style='width: 100%; border-collapse: collapse; background-color: #f8f9fa; border-radius: 6px; margin: 25px 0; border-left: 4px solid #dc3545;'>
                                            <tr>
                                                <td style='padding: 20px;'>
                                                    <h3 style='margin: 0 0 10px 0; color: #333333; font-size: 18px;'>📄 Document Details</h3>
                                                    <p style='margin: 0 0 8px 0; color: #555555;'><strong>Title:</strong> {$documentTitle}</p>
                                                    <p style='margin: 0 0 8px 0; color: #555555;'><strong>Status:</strong> Rejected</p>
                                                    <p style='margin: 0; color: #555555;'><strong>Reason:</strong> {$reason}</p>
                                                </td>
                                            </tr>
                                        </table>

                                        <table role='presentation' style='width: 100%; border-collapse: collapse; margin: 30px 0;'>
                                            <tr>
                                                <td align='center' style='background-color: #dc3545; border-radius: 6px; padding: 15px;'>
                                                    <p style='margin: 0; color: #ffffff; font-size: 18px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px;'>REJECTED</p>
                                                </td>
                                            </tr>
                                        </table>

                                        <p style='color: #555555; line-height: 1.6; margin: 0 0 20px 0; font-size: 15px;'>
                                            Please review the rejection reason and consider revising your document before resubmitting. You can create a new document submission through the Sign-um System.
                                        </p>

                                        <table role='presentation' style='width: 100%; border-collapse: collapse; background-color: #e7f3ff; border-left: 4px solid #007bff; border-radius: 4px; margin: 20px 0;'>
                                            <tr>
                                                <td style='padding: 15px;'>
                                                    <p style='margin: 0; color: #004085; font-size: 14px; line-height: 1.5;'>
                                                        <strong>💡 Next Steps:</strong><br>
                                                        • Review the rejection feedback<br>
                                                        • Make necessary revisions to your document<br>
                                                        • Submit a new document through the system
                                                    </p>
                                                </td>
                                            </tr>
                                        </table>

                                        <table role='presentation' style='width: 100%; border-collapse: collapse; margin: 30px 0;'>
                                            <tr>
                                                <td align='center'>
                                                    <a href='" . BASE_URL . "' style='background-color: #007bff; color: #ffffff; padding: 15px 30px; text-decoration: none; border-radius: 6px; font-weight: bold; display: inline-block; box-shadow: 0 2px 4px rgba(0,0,0,0.1);'>🔄 Create New Document</a>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style='background-color: #f8f9fa; padding: 25px 30px; border-radius: 0 0 8px 8px; border-top: 1px solid #e9ecef;'>
                                        <p style='margin: 0; color: #6c757d; font-size: 12px;'>
                                            &copy; {$currentYear} Sign-um System. All rights reserved.<br>
                                            Systems Plus College Foundation | Angeles City<br>
                                            <em>This is an automated notification. Please do not reply to this email.</em>
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </body>
            </html>
            ";

            $mail->send();
            error_log("Document rejection email sent successfully to {$userEmail} for document '{$documentTitle}'");
            return true;
        } catch (Exception $e) {
            error_log("Document rejection email sending failed: {$mail->ErrorInfo}");
            return false;
        }
    }

    /**
     * Send progress email when document advances to next approval level
     */
    function sendDocumentProgressEmail($userEmail, $userName, $documentTitle, $currentStep, $nextStep)
    {
        $mail = new PHPMailer(true);

        try {
            // Server settings (Pulls securely from your .env file)
            $mail->isSMTP();
            $mail->Host = $_ENV['MAIL_HOST'];
            $mail->SMTPAuth = true;
            $mail->Username = $_ENV['MAIL_USERNAME'];
            $mail->Password = $_ENV['MAIL_PASSWORD'];
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port = $_ENV['MAIL_PORT'];

            // Recipients
            $mail->setFrom($_ENV['MAIL_FROM_ADDRESS'], $_ENV['MAIL_FROM_NAME']);
            $mail->addAddress($userEmail, $userName);
            $mail->addReplyTo($_ENV['MAIL_FROM_ADDRESS'], 'Support');

            // Content
            $mail->isHTML(true);
            $mail->Subject = "📈 Document Progress Update - {$documentTitle}";
            $currentYear = date('Y');

            // Embed the header image
            $mail->addEmbeddedImage(ROOT_PATH . 'assets/images/Email_background.jpg', 'header_image', 'Email_background.jpg', 'base64', 'image/jpeg');

            $mail->Body = "
            <!DOCTYPE html>
            <html lang='en'>
            <head>
                <meta charset='UTF-8'>
                <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                <title>Document Progress Update</title>
            </head>
            <body style='margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, \"Helvetica Neue\", Arial, sans-serif; background-color: #f4f7fa;'>
                <table role='presentation' style='width: 100%; border-collapse: collapse; background-color: #f4f7fa;'>
                    <tr>
                        <td align='center' style='padding: 40px 0;'>
                            <table role='presentation' style='width: 600px; max-width: 90%; border-collapse: collapse; background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);'>
                                <tr>
                                    <td style='background: linear-gradient(135deg, #28a745 0%, #20c997 100%); padding: 30px; border-radius: 8px 8px 0 0; text-align: center;'>
                                        <img src='cid:header_image' alt='Sign-um System' style='max-width: 100%; height: auto; border-radius: 4px; margin-bottom: 20px;'>
                                        <h1 style='color: #ffffff; margin: 0; font-size: 28px; font-weight: bold; text-shadow: 0 1px 2px rgba(0,0,0,0.3);'>📈 DOCUMENT PROGRESS</h1>
                                    </td>
                                </tr>
                                <tr>
                                    <td style='padding: 40px 30px;'>
                                        <p style='margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;'>Hello <strong>{$userName}</strong>,</p>

                                        <p style='color: #555555; line-height: 1.6; margin: 0 0 25px 0; font-size: 15px;'>
                                            Great news! Your document has successfully passed the current approval level and is now progressing to the next stage.
                                        </p>

                                        <table role='presentation' style='width: 100%; border-collapse: collapse; background-color: #f8f9fa; border-radius: 6px; margin: 25px 0; border-left: 4px solid #28a745;'>
                                            <tr>
                                                <td style='padding: 20px;'>
                                                    <h3 style='margin: 0 0 10px 0; color: #333333; font-size: 18px;'>📄 Document Progress</h3>
                                                    <p style='margin: 0 0 8px 0; color: #555555;'><strong>Title:</strong> {$documentTitle}</p>
                                                    <p style='margin: 0 0 8px 0; color: #28a745; font-weight: bold;'><strong>✅ Completed:</strong> {$currentStep}</p>
                                                    <p style='margin: 0 0 8px 0; color: #007bff; font-weight: bold;'><strong>➡️ Next:</strong> {$nextStep}</p>
                                                    <p style='margin: 0; color: #555555;'><strong>Status:</strong> In Progress</p>
                                                </td>
                                            </tr>
                                        </table>

                                        <table role='presentation' style='width: 100%; border-collapse: collapse; margin: 30px 0;'>
                                            <tr>
                                                <td align='center' style='background-color: #28a745; border-radius: 6px; padding: 15px;'>
                                                    <p style='margin: 0; color: #ffffff; font-size: 18px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px;'>APPROVED & PROGRESSING</p>
                                                </td>
                                            </tr>
                                        </table>

                                        <p style='color: #555555; line-height: 1.6; margin: 0 0 20px 0; font-size: 15px;'>
                                            Your document is now under review by the next approval authority. You'll receive another notification when it advances further or reaches final approval.
                                        </p>

                                        <table role='presentation' style='width: 100%; border-collapse: collapse; background-color: #e7f3ff; border-left: 4px solid #007bff; border-radius: 4px; margin: 20px 0;'>
                                            <tr>
                                                <td style='padding: 15px;'>
                                                    <p style='margin: 0; color: #004085; font-size: 14px; line-height: 1.5;'>
                                                        <strong>📋 What happens next:</strong><br>
                                                        • The document moves to the next approval level<br>
                                                        • You'll be notified of further progress<br>
                                                        • Final approval notification will be sent when complete
                                                    </p>
                                                </td>
                                            </tr>
                                        </table>

                                        <table role='presentation' style='width: 100%; border-collapse: collapse; margin: 30px 0;'>
                                            <tr>
                                                <td align='center'>
                                                    <a href='" . BASE_URL . "' style='background-color: #007bff; color: #ffffff; padding: 15px 30px; text-decoration: none; border-radius: 6px; font-weight: bold; display: inline-block; box-shadow: 0 2px 4px rgba(0,0,0,0.1);'>📊 Track Progress</a>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style='background-color: #f8f9fa; padding: 25px 30px; border-radius: 0 0 8px 8px; border-top: 1px solid #e9ecef;'>
                                        <p style='margin: 0; color: #6c757d; font-size: 12px;'>
                                            &copy; {$currentYear} Sign-um System. All rights reserved.<br>
                                            Systems Plus College Foundation | Angeles City<br>
                                            <em>This is an automated progress notification. Please do not reply to this email.</em>
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </body>
            </html>
            ";

            $mail->send();
            error_log("Document progress email sent successfully to {$userEmail} for document '{$documentTitle}'");
            return true;
        } catch (Exception $e) {
            error_log("Document progress email sending failed: {$mail->ErrorInfo}");
            return false;
        }
    }
}
?>